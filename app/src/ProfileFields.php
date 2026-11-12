<?php // app/src/ProfileFields.php
namespace App;

use Kip\Database;

/** Author profile fields the owner defines and members fill in. Three types:
 *  one line of text, a web link (http or https only), and yes/no. Values are
 *  checked when saved and again when shown, so a bad row written by any other
 *  route still cannot become a clickable `javascript:` link. */
final class ProfileFields
{
    public const TYPES = ['text', 'url', 'yesno'];
    public const MAX_FIELDS = 20;
    public const MAX_LABEL = 60;
    public const MAX_TEXT = 200;
    public const MAX_URL = 500;

    public function __construct(private Database $db) {}

    /** @return list<array{id:int,label:string,type:string,position:int}> */
    public function all(): array
    {
        $rows = $this->db->all('SELECT id, label, type, position FROM profile_fields ORDER BY position, id');
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label'],
            'type' => (string) $r['type'], 'position' => (int) $r['position']], $rows);
    }

    public function find(int $id): ?array
    {
        $r = $this->db->one('SELECT id, label, type, position FROM profile_fields WHERE id = ?', [$id]);
        return $r === null ? null : ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'type' => (string) $r['type'], 'position' => (int) $r['position']];
    }

    /** Add a field at the end. Returns a lang key of the refusal or null. */
    public function add(string $label, string $type): ?string
    {
        $label = $this->cleanLabel($label);
        if ($label === '') return 'profilefields.err_label';
        if (!\in_array($type, self::TYPES, true)) return 'profilefields.err_type';
        if ((int) $this->db->one('SELECT COUNT(*) c FROM profile_fields')['c'] >= self::MAX_FIELDS) return 'profilefields.err_many';
        $this->db->query('INSERT INTO profile_fields (label, type, position) VALUES (?, ?, (SELECT COALESCE(MAX(position), 0) + 1 FROM profile_fields))', [$label, $type]);
        return null;
    }

    public function rename(int $id, string $label): ?string
    {
        $label = $this->cleanLabel($label);
        if ($label === '') return 'profilefields.err_label';
        return $this->db->query('UPDATE profile_fields SET label = ? WHERE id = ?', [$label, $id])->rowCount() === 1 ? null : 'profilefields.err_missing';
    }

    /** Swap a field with its neighbour above or below. Positions are
     *  renumbered 1..n first so ties and gaps can never leave a move stuck. */
    public function move(int $id, string $dir): ?string
    {
        if ($dir !== 'up' && $dir !== 'down') return 'profilefields.err_missing';
        $ids = array_column($this->all(), 'id');
        $i = array_search($id, $ids, true);
        if ($i === false) return 'profilefields.err_missing';
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < \count($ids)) [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->db->begin();
        try {
            foreach ($ids as $pos => $fid) $this->db->query('UPDATE profile_fields SET position = ? WHERE id = ?', [$pos + 1, $fid]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return null;
    }

    /** Delete a field; every member's answer to it goes with it (cascade). */
    public function delete(int $id): bool
    {
        return $this->db->query('DELETE FROM profile_fields WHERE id = ?', [$id])->rowCount() === 1;
    }

    /** The member's saved answers keyed by field id. */
    public function valuesFor(int $userId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT field_id, value FROM profile_field_values WHERE user_id = ?', [$userId]) as $r) $out[(int) $r['field_id']] = (string) $r['value'];
        return $out;
    }

    /** Save a member's answers. $input maps field id => submitted string; a
     *  yes/no field is on when its key is present. Empty clears an answer. One
     *  bad value refuses the whole save, so nothing is half-saved.
     *  @return ?array{0:string,1:string} [lang key, field label] of the refusal, or null */
    public function save(int $userId, array $input): ?array
    {
        $clean = [];
        foreach ($this->all() as $f) {
            $raw = $input[$f['id']] ?? '';
            $raw = \is_string($raw) ? $raw : '';
            $v = match ($f['type']) {
                'yesno' => isset($input[$f['id']]) && $raw !== '0' ? '1' : '',
                'url' => $this->cleanUrl($raw),
                default => $this->cleanText($raw),
            };
            if ($v === null) return ['profilefields.err_url', $f['label']];
            $clean[$f['id']] = $v;
        }
        $this->db->begin();
        try {
            foreach ($clean as $fid => $v) {
                if ($v === '') {
                    $this->db->query('DELETE FROM profile_field_values WHERE user_id = ? AND field_id = ?', [$userId, $fid]);
                } else {
                    $this->db->query('INSERT INTO profile_field_values (user_id, field_id, value) VALUES (?, ?, ?)
                        ON CONFLICT(user_id, field_id) DO UPDATE SET value = excluded.value', [$userId, $fid, $v]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return null;
    }

    /** A link safe to put in an href, or null. http and https only, a real
     *  host, no spaces or control characters. */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || \strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f"<>\\\\]/', $url) === 1) return null;
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || !\in_array(strtolower($p['scheme']), ['http', 'https'], true)) return null;
        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    /** '' for empty, the URL when safe, null when not. */
    private function cleanUrl(string $raw): ?string
    {
        $raw = trim($raw);
        return $raw === '' ? '' : self::safeUrl($raw);
    }

    private function cleanText(string $raw): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $raw) ?? ''), 0, self::MAX_TEXT);
    }

    private function cleanLabel(string $raw): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $raw) ?? ''), 0, self::MAX_LABEL);
    }
}
