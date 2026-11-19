<?php
namespace App;

/** The story answers as posted, read the same way by the story form, the new-story page and the draft-story chapter editor. */
final class StoryInput
{
    /** @return array{string,string,string,int,array,bool,bool,string,bool,bool,string,array,string,bool,?array} */
    public static function from(array $post, string $titleKey = 'title'): array
    {
        $text = static fn (string $name): string => is_scalar($post[$name] ?? '') ? (string) ($post[$name] ?? '') : '';
        $language = trim($text('language'));
        if ($language !== '' && (strlen($language) > 10 || !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language))) {
            $language = ''; // junk never reaches the browse filter's regex
        }
        return [
            trim($text($titleKey)),
            trim($text('summary')),
            trim($text('notes')),
            (int) $text('rating_id'),
            array_values(array_filter((array) ($post['categories'] ?? []), 'is_numeric')),
            isset($post['completed']),
            isset($post['restricted']),
            $language,
            // Value-based, not isset (finding 8's lesson): an unchecked box
            // omits the key, and a forged non-'1' value must read as off.
            ($post['round_robin'] ?? '') === '1',
            // Attestation, same value-based idiom: unchecked omits the key,
            // forged non-'1' reads as off. Attaching money surfaces to a
            // fan work is the platform's hard line (DMCA posture).
            ($post['is_original'] ?? '') === '1',
            substr(trim($text('gift_to')), 0, 120),
            // The tags idiom is the categories idiom: numeric-only, unknown
            // ids drop inside writeTags's INSERT..SELECT WHERE id IN.
            array_values(array_filter((array) ($post['tags'] ?? []), 'is_numeric')),
            // The post-password pair (Fictioneer parity): the raw password
            // travels to the repository, which hashes it (never stored raw,
            // never echoed back); the clear checkbox rides the same
            // value-based idiom as the flags above.
            trim($text('access_password')),
            ($post['access_clear'] ?? '') === '1',
            // The character picker: null when the form did not offer it (the
            // hidden characters_shown marker is absent), so a save from an
            // old form never wipes the story's characters. Unknown ids and
            // characters of unchosen categories drop in writeCharacters.
            isset($post['characters_shown'])
                ? array_values(array_filter((array) ($post['characters'] ?? []), 'is_numeric'))
                : null,
            // The suggest pair (slice 4): suggestions keyed by tag type id
            // (string values are the typed comma lists; a non-array or junk
            // key drops), withdrawals as the member's own pending ids. Both
            // ride the save's transaction; the tag_policy setting decides
            // whether a name parks pending or goes live.
            self::suggest($post),
            array_values(array_filter((array) ($post['withdraw'] ?? []), 'is_numeric')),
        ];
    }

    /** @return array<int, string> tag type id => typed comma list. A scalar,
     *  a nested array, or a non-numeric key is ignored entirely (the policy
     *  decides nothing for junk input; TagSuggestionRepository owns the rest). */
    public static function suggest(array $post): array
    {
        $raw = $post['suggest'] ?? [];
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $typeId => $list) {
            if (!is_numeric($typeId) || (int) $typeId <= 0 || !is_string($list)) continue;
            $out[(int) $typeId] = $list;
        }
        return $out;
    }

    /** The posted values kept for a refused save: raw, explicitly named fields; a password is only a notice flag.
     *  @return array{fields: array, suggest: array, passwordDiscarded: bool, categories: array, tags: array, characters: ?array, withdraw: array} */
    public static function retry(array $post, string $titleKey = 'title'): array
    {
        $text = static fn (string $name): string => is_scalar($post[$name] ?? '') ? (string) ($post[$name] ?? '') : '';
        $fields = ['title' => $text($titleKey)];
        foreach (['summary', 'notes', 'rating_id', 'language', 'gift_to', 'canonical_url', 'crosspost_url'] as $name) {
            $fields[$name] = $text($name);
        }
        foreach (['completed', 'restricted'] as $name) $fields[$name] = isset($post[$name]);
        foreach (['round_robin', 'is_original', 'access_clear'] as $name) $fields[$name] = ($post[$name] ?? '') === '1';
        $draft = ['fields' => $fields, 'suggest' => self::suggest($post), 'passwordDiscarded' => $text('access_password') !== ''];
        foreach (['categories', 'tags', 'characters', 'withdraw'] as $name) {
            $draft[$name] = array_values(array_filter((array) ($post[$name] ?? []), 'is_numeric'));
        }
        if (!isset($post['characters_shown'])) $draft['characters'] = null;
        return $draft;
    }
}
