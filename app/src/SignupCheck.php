<?php // app/src/SignupCheck.php
namespace App;

use Kip\Database;
use Kip\Session;

/** Spam checks for the signup form, with no third-party captcha. Three
 *  layers: a hidden field people never fill (honeypot), a minimum fill time
 *  proven by a signed timestamp the form carries, and an optional question
 *  the owner sets (off by default). Each returns a lang key on refusal. */
final class SignupCheck
{
    public const HONEYPOT = 'homepage';
    public const STAMP = 'form_ts';
    public const ANSWER = 'check_answer';
    /** A form older than this is stale; a real person signing up never takes a day. */
    private const MAX_AGE = 86400;

    public function __construct(private Database $db, private Session $session, private int $minSeconds) {}

    /** The signed "form shown at" value for the hidden field. */
    public function stamp(?int $now = null): string
    {
        $now ??= time();
        return $now . '.' . $this->mac($now);
    }

    /** @param array<string, string> $post the submitted fields
     *  @return ?string lang key of the refusal, or null when it passes */
    public function verify(array $post, ?int $now = null): ?string
    {
        $now ??= time();
        if (($post[self::HONEYPOT] ?? '') !== '') return 'auth.register.err_check';
        if ($this->minSeconds > 0) {
            $stamp = (string) ($post[self::STAMP] ?? '');
            if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $stamp, $m) !== 1
                || !hash_equals($this->mac((int) $m[1]), $m[2])) return 'auth.register.err_check';
            $age = $now - (int) $m[1];
            if ($age < $this->minSeconds || $age > self::MAX_AGE) return 'auth.register.err_check';
        }
        $q = $this->question();
        if ($q !== null && !$this->answerMatches((string) ($post[self::ANSWER] ?? ''), $q['answer'])) {
            return 'auth.register.err_question';
        }
        return null;
    }

    /** The owner's question when the check is on and usable, else null. */
    public function question(): ?array
    {
        try {
            $row = $this->db->one('SELECT enabled, question, answer FROM signup_check WHERE id = 1');
        } catch (\PDOException) {
            return null; // unmigrated database: no question, no 500
        }
        if ($row === null || (int) $row['enabled'] !== 1 || trim((string) $row['question']) === '' || trim((string) $row['answer']) === '') return null;
        return ['question' => (string) $row['question'], 'answer' => (string) $row['answer']];
    }

    /** Save the owner's settings. Turning it on needs both a question and an
     *  answer. Returns a lang key of the refusal or null. */
    public function save(bool $enabled, string $question, string $answer): ?string
    {
        $question = trim($question);
        $answer = trim($answer);
        if (mb_strlen($question) > 200 || mb_strlen($answer) > 100) return 'signupcheck.err_long';
        if ($enabled && ($question === '' || $answer === '')) return 'signupcheck.err_incomplete';
        $this->db->query('INSERT INTO signup_check (id, enabled, question, answer) VALUES (1, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET enabled = excluded.enabled, question = excluded.question, answer = excluded.answer',
            [$enabled ? 1 : 0, $question, $answer]);
        return null;
    }

    public function settings(): array
    {
        $row = $this->db->one('SELECT enabled, question, answer FROM signup_check WHERE id = 1');
        return $row === null ? ['enabled' => 0, 'question' => '', 'answer' => ''] : $row;
    }

    private function answerMatches(string $given, string $expected): bool
    {
        $norm = static fn(string $s): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
        return $norm($given) !== '' && hash_equals($norm($expected), $norm($given));
    }

    private function mac(int $ts): string
    {
        return hash_hmac('sha256', 'signup:' . $ts, $this->session->csrfToken());
    }
}
