<?php // tests/SavedUndoTest.php
namespace App\Tests;

use App\Saved;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

/** The Undo button on the saved line must appear only for a link this
 *  owner's own form token signed, and only for the two kinds of action
 *  that are safe to repeat. */
final class SavedUndoTest extends TestCase
{
    private const TOKEN = 'owner-form-token';

    /** The query string a redirect from withUndo() carries, as the next request's GET. */
    private function landing(string $url, string $code, string $undo, string $token = self::TOKEN): array
    {
        $to = Saved::withUndo($url, $code, $undo, $token)->headers['Location'];
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        return $query;
    }

    private function request(array $get): Request
    {
        return new Request('GET', '/adminmembers', $get, [], []);
    }

    public function test_a_signed_undo_comes_back_to_the_owner_who_made_it(): void
    {
        $query = $this->landing('/adminmembers', 'locked', '/adminmembers/unlock/7');
        $this->assertSame('/adminmembers/unlock/7', Saved::undo($this->request($query), self::TOKEN));
    }

    public function test_a_different_session_token_cannot_use_the_link(): void
    {
        $query = $this->landing('/adminmembers', 'locked', '/adminmembers/unlock/7');
        $this->assertNull(Saved::undo($this->request($query), 'someone-elses-token'));
    }

    public function test_changing_the_target_after_signing_drops_the_button(): void
    {
        $query = $this->landing('/adminmembers', 'locked', '/adminmembers/unlock/7');
        $query['undo'] = '/adminmembers/unlock/8';
        $this->assertNull(Saved::undo($this->request($query), self::TOKEN));
    }

    public function test_a_cut_short_or_altered_signature_is_refused(): void
    {
        $query = $this->landing('/adminmembers', 'locked', '/adminmembers/unlock/7');
        $good = $query['undo_key'];
        foreach ([substr($good, 0, 10), '', strtoupper($good), $good . 'x'] as $bad) {
            $query['undo_key'] = $bad;
            $this->assertNull(Saved::undo($this->request($query), self::TOKEN), "key '{$bad}' must not pass");
        }
    }

    public function test_an_empty_token_never_signs_anything_valid(): void
    {
        // A signed-out or tokenless visitor must not be handed a button, even
        // if someone computes the signature with an empty key.
        $query = $this->landing('/adminmembers', 'locked', '/adminmembers/unlock/7', '');
        $this->assertNull(Saved::undo($this->request($query), ''));
    }

    public function test_array_or_missing_parameters_are_ignored_not_fatal(): void
    {
        $this->assertNull(Saved::undo(null, self::TOKEN));
        $this->assertNull(Saved::undo($this->request([]), self::TOKEN));
        $this->assertNull(Saved::undo($this->request(['undo' => ['/adminmembers/unlock/7'], 'undo_key' => ['x']]), self::TOKEN));
        $this->assertNull(Saved::undo($this->request(['undo' => '/adminmembers/unlock/7']), self::TOKEN));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeTargets')]
    public function test_only_lock_unlock_and_feature_switches_are_ever_offered(string $undo): void
    {
        $to = Saved::withUndo('/adminmembers', 'locked', $undo, self::TOKEN)->headers['Location'];
        $this->assertSame('/adminmembers?saved=locked', $to, 'no button is added');
        // And even a correctly signed value outside the list is refused on the way back.
        $key = substr(hash_hmac('sha256', $undo, self::TOKEN), 0, 32);
        $this->assertNull(Saved::undo($this->request(['undo' => $undo, 'undo_key' => $key]), self::TOKEN));
    }

    public static function unsafeTargets(): array
    {
        return [
            'delete a member' => ['/adminmembers/delete/7'],
            'non-numeric id' => ['/adminmembers/lock/abc'],
            'feature to a third state' => ['/features/set/news/sideways'],
            'feature toggle (flips, not names a state)' => ['/features/toggle/news'],
            'another site' => ['https://evil.example/adminmembers/lock/7'],
            'trailing newline' => ["/adminmembers/lock/7\n"],
            'path tail' => ['/adminmembers/lock/7/extra'],
            'empty' => [''],
        ];
    }

    public function test_the_undo_address_keeps_a_fragment_at_the_end(): void
    {
        $to = Saved::withUndo('/features#flags', 'turned_off', '/features/set/news/on', self::TOKEN)->headers['Location'];
        $this->assertMatchesRegularExpression('~^/features\?saved=turned_off&undo=%2Ffeatures%2Fset%2Fnews%2Fon&undo_key=[0-9a-f]{32}#flags$~', $to);
    }

    public function test_an_unknown_code_falls_back_to_plain_saved(): void
    {
        $this->assertSame('/queue?saved=1', Saved::url('/queue', 'made-up'));
        $this->assertSame('/queue?a=b&saved=1#top', Saved::url('/queue?a=b#top', 'made-up'));
    }

    public function test_the_message_comes_only_from_the_fixed_list(): void
    {
        $this->assertSame('admin.saved_removed', Saved::message($this->request(['saved' => 'removed'])));
        $this->assertNull(Saved::message($this->request(['saved' => '<script>'])));
        $this->assertNull(Saved::message($this->request(['saved' => ['removed']])));
        $this->assertNull(Saved::message($this->request([])));
        $this->assertNull(Saved::message(null));
    }
}
