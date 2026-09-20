<?php // app/src/Import/EfictionMapper.php
namespace App\Import;

/** Pure legacy-row mapping. No DB, no IO: every method takes arrays and a
 *  Report; the Importer owns persistence. Dates leave as ISO strings or null
 *  fallback markers; content columns leave as RAW legacy bytes (the Importer
 *  runs Charset + HtmlToMarkdown on the prose fields it persists). */
final class EfictionMapper
{
    private const RESPONSE_RX = '#(?:<br\s*/?>){2}\s*<i>\s*Author\'s Response\s*:?\s*(.*?)</i>\s*$#is';

    public static function mapUser(?array $a, ?array $p, array $takenPennames, string $takenEmails, Report $r): ?array
    {
        if ($a === null) return null;
        $email = strtolower(trim((string) $a['email']));
        if ($email === '' || str_contains($takenEmails, '|' . $email . '|')) {
            $r->reject('email collision');
            return null;
        }
        $pen = trim((string) $a['penname']);
        $pen = preg_replace('/\s+/', ' ', $pen) ?? $pen;
        $base = $pen;
        $n = 2;
        $lower = array_map('mb_strtolower', $takenPennames);
        while (in_array(mb_strtolower($pen), $lower, true)) { $pen = $base . '-' . $n++; }
        $validated = $p !== null && (int) $p['validated'] === 1;
        $role = $validated ? 'validated_author' : 'member';
        $zeroDate = ($a['date'] ?? '') === '0000-00-00 00:00:00' || empty($a['date']);
        $ghost = ($a['admincreated'] ?? '0') === '1' && $zeroDate;
        if ((string) $a['password'] !== '0' && strlen((string) $a['password']) === 32) $r->passwordLegacy++;
        return [
            'legacy_uid' => (int) $a['uid'],
            'penname' => $pen,
            'email' => $email,
            'bio' => (string) ($a['bio'] ?? ''),
            'legacy_md5' => ((string) $a['password'] !== '0' && strlen((string) $a['password']) === 32) ? (string) $a['password'] : null,
            'role' => $role,
            'email_verified_at' => $ghost ? null : self::iso($a['date'] ?? null),
            'approved_at' => $ghost ? null : self::iso($a['date'] ?? null),
            'created_at' => self::iso($a['date'] ?? null) ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];
    }

    public static function mapPrefs(?array $p): array
    {
        return [
            'notify_review' => $p === null ? 1 : (int) ($p['newreviews'] ?? 1),
            'notify_response' => $p === null ? 1 : (int) ($p['newrespond'] ?? 1),
            'notify_favorites' => 1,
            'notify_favorite_digest' => $p === null ? 0 : (int) ($p['alertson'] ?? 0),
            'default_sort' => ($p !== null && (int) ($p['sortby'] ?? 0) === 2) ? 'alpha' : 'recent',
            'toc_first' => $p === null ? 0 : ((int) ($p['storyindex'] ?? 0) === 2 ? 1 : 0),
        ];
    }

    /** @param array{categories:array<string,string>,ratings:array<string,string>,classes:array<string,string>,characters?:array<string,string>} $ctx
     *  Context values are the Importer's id maps: legacy CSV token -> new row id. */
    public static function mapStory(array $s, array $ctx, Report $r): array
    {
        $rids = self::csv((string) $s['rid']);
        if (count($rids) > 1) $r->multiRatingStories++;
        $cats = [];
        foreach (self::csv((string) $s['catid']) as $cid) {
            if (isset($ctx['categories'][$cid])) { $cats[] = $ctx['categories'][$cid]; }
            else { $r->unresolvableTokens++; }
        }
        $tags = [];
        foreach (self::csv((string) $s['classes']) as $clid) {
            if (isset($ctx['classes'][$clid])) { $tags[] = $ctx['classes'][$clid]; }
            else { $r->unresolvableTokens++; }
        }
        $chars = [];
        foreach (self::csv((string) $s['charid']) as $chid) {
            if (isset($ctx['characters'][$chid])) { $chars[] = $ctx['characters'][$chid]; }
            else { $r->unresolvableTokens++; }
        }
        return [
            'legacy_sid' => (int) $s['sid'],
            'title' => mb_substr(trim((string) $s['title']), 0, 255),
            'summary' => (string) ($s['summary'] ?? ''),
            'notes' => (string) ($s['storynotes'] ?? ''),
            'categories' => array_values(array_unique($cats)),
            'tags' => array_values(array_unique($tags)),
            'characters' => array_values(array_unique($chars)),
            'rating_label' => $ctx['ratings'][$rids[0] ?? ''] ?? null,
            'completed' => ($s['completed'] ?? '0') === '1' ? 1 : 0,
            'featured' => ($s['featured'] ?? '0') === '1' ? 1 : 0,
            'validated' => ($s['validated'] ?? '0') === '1' ? 1 : 0,
            'round_robin' => ($s['rr'] ?? '0') === '1' ? 1 : 0,
            'legacy_reads' => (int) ($s['count'] ?? 0),
            'created_at' => self::iso($s['date'] ?? null),          // null marker: Importer falls back
            'created_fallback' => self::iso($s['date'] ?? null) === null,
            'updated_at' => self::iso($s['updated'] ?? null) ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];
    }

    public static function mapReview(array $v, Report $r): array
    {
        $body = (string) ($v['review'] ?? '');
        $response = null;
        if (preg_match(self::RESPONSE_RX, $body, $m)) {
            $response = trim($m[1]);
            $body = trim(preg_replace(self::RESPONSE_RX, '', $body) ?? $body);
            $r->responseBlocksExtracted++;
        } elseif (($v['respond'] ?? '0') === '1') {
            $r->responseMisses++;
        }
        $sentinel = in_array(trim($body), ['No Review', ''], true);
        return [
            'legacy_reviewid' => (int) $v['reviewid'],
            'story_item' => ($v['type'] ?? 'ST') === 'ST' ? (int) $v['item'] : null,
            'series_item' => ($v['type'] ?? 'ST') === 'SE' ? (int) $v['item'] : null,
            'user_id' => (int) ($v['uid'] ?? 0) > 0 ? (int) $v['uid'] : null,
            'guest_name' => (int) ($v['uid'] ?? 0) === 0 && (string) ($v['reviewer'] ?? '0') !== '0'
                ? mb_substr(trim((string) $v['reviewer']), 0, 60) : null,
            'body' => $sentinel ? null : $body,
            'rating' => ($v['rating'] ?? null) === null || $v['rating'] === '' ? null : max(0, min(10, (int) $v['rating'])),
            'response' => $response,
            'responded_at' => $response !== null ? self::iso($v['date'] ?? null) : null,
            'created_at' => self::iso($v['date'] ?? null) ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];
    }

    public static function mapSeries(array $x, Report $r): array
    {
        // series.php:190-192: 2=OPEN, 1=MODERATED, 0=CLOSED; unknown -> moderated
        $isopen = (int) ($x['isopen'] ?? 99);
        return [
            'legacy_seriesid' => (int) $x['seriesid'],
            'title' => mb_substr(trim((string) $x['title']), 0, 255),
            'summary' => (string) ($x['summary'] ?? ''),
            'membership' => match ($isopen) { 2 => 'open', 1 => 'moderated', 0 => 'closed', default => 'moderated' },
            'owner_uid' => (int) ($x['uid'] ?? 0),
        ];
    }

    public static function mapNews(array $n, Report $r): array
    {
        $r->drop('news author string (no column)');
        return [
            'legacy_nid' => (int) $n['nid'],
            'title' => mb_substr(trim((string) $n['title']), 0, 255),
            'body' => (string) ($n['story'] ?? ''),
            'published_at' => self::iso($n['time'] ?? null) ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];
    }

    /** @return string[] */
    private static function csv(string $s): array
    {
        $parts = array_map(fn ($p) => trim((string) $p), preg_split('/[;,]/', $s) ?: []);
        return array_values(array_filter($parts, fn ($p) => $p !== '' && $p !== '0'));
    }

    private static function iso(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '' || str_starts_with($mysqlDatetime, '0000-00-00')) return null;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime);
        return $d === false ? null : $d->format(DATE_ATOM);
    }
}
