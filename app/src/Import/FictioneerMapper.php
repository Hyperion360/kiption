<?php // app/src/Import/FictioneerMapper.php
namespace App\Import;

/** Pure Fictioneer-row mapping, the EfictionMapper seam: static methods,
 *  no DB, no IO; every uncertainty becomes a caller-level Report line,
 *  never a guess. All public Fictioneer releases are 5.x, so "all
 *  releases" means the four 5.x data-model eras, which differ only in
 *  meta spelling: this mapper reads every spelling and reports the eras
 *  it saw. */
final class FictioneerMapper
{
    public const STORY = 'fcn_story';
    public const CHAPTER = 'fcn_chapter';
    public const COLLECTION = 'fcn_collection';
    public const RECOMMENDATION = 'fcn_recommendation';

    /** @var array<string,string> keys whose presence fingerprints an era (report lines) */
    public const ERA_MARKERS = [
        'fictioneer_seo_title' => 'pre-5.10 SEO fields',
        'fictioneer_seo_fields' => '5.10+ SEO fields',
        'fictioneer_story_hidden' => 'pre-5.35 hidden flags',
        'fictioneer_chapter_hidden' => 'pre-5.35 hidden flags',
    ];

    public static function isFictioneerType(string $type): bool
    {
        return in_array($type, [self::STORY, self::CHAPTER, self::COLLECTION, self::RECOMMENDATION], true);
    }

    /** Serialized meta to a plain list; objects are never instantiated
     *  (allowed_classes: false) and anything else is garbage -> null.
     *  The @ silences the warning unserialize raises on the garbage
     *  payloads this method exists to absorb (parser-noise precedent:
     *  WxrReader's libxml_use_internal_errors). */
    public static function metaList(?string $raw): ?array
    {
        if ($raw === null || $raw === '') return null;
        $v = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($v) ? array_values($v) : null;
    }

    /** WP GMT 'YYYY-MM-DD HH:MM:SS' -> ISO 'T'/'Z'; zero dates -> null. */
    public static function iso(?string $mysqlDate): ?string
    {
        if ($mysqlDate === null || $mysqlDate === '' || str_starts_with($mysqlDate, '0000-00-00')) return null;
        return str_replace(' ', 'T', $mysqlDate) . 'Z';
    }

    public static function completed(?string $status): ?bool
    {
        return match ($status) {
            'Completed', 'Oneshot' => true,
            'Ongoing', 'Hiatus', 'Cancelled' => false,
            default => null,
        };
    }

    public static function ratingLabel(?string $label): ?string
    {
        return match (strtolower((string) $label)) {
            'everyone' => 'General',
            'teen' => 'Teen',
            'mature' => 'Mature',
            'adult' => 'Explicit',
            default => null,
        };
    }

    private const RATING_ORDER = ['General' => 0, 'Teen' => 1, 'Mature' => 2, 'Explicit' => 3];

    /** A Mature/Adult chapter lifts a lighter story rating: the one
     *  direction the import refuses is more public than it lived. */
    public static function stricterRating(string $storyLabel, ?string $chapterLabel): string
    {
        $chapter = self::ratingLabel($chapterLabel);
        if ($chapter === null) return $storyLabel;
        return self::RATING_ORDER[$chapter] > (self::RATING_ORDER[$storyLabel] ?? 0) ? $chapter : $storyLabel;
    }

    /** publish/future/fcn_hidden + the pre-5.35 hidden flags import;
     *  everything else is not public content. The pre-5.35 meta flag is
     *  the author's own hide switch, so it outranks even publish/future
     *  status: a flagged post reads as unlisted, never public. */
    public static function visibility(string $status, array $meta): string
    {
        foreach (['fictioneer_story_hidden', 'fictioneer_chapter_hidden'] as $k) {
            if (in_array(strtolower((string) ($meta[$k] ?? '')), ['1', 'true'], true)) return 'unlisted';
        }
        if ($status === 'publish') return 'public';
        if ($status === 'future') return 'scheduled';
        if ($status === 'fcn_hidden') return 'unlisted';
        return 'private';
    }

    public static function metaDescription(array $meta): string
    {
        $fields = null;
        if (isset($meta['fictioneer_seo_fields'])) $fields = self::metaAssoc($meta['fictioneer_seo_fields']);
        $text = is_array($fields) ? (string) ($fields['description'] ?? '') : '';
        if ($text === '') $text = (string) ($meta['fictioneer_seo_description'] ?? '');
        if ($text === '') $text = (string) ($meta['fictioneer_story_short_description'] ?? '');
        return self::plain($text);
    }

    public static function summary(array $meta, string $content): string
    {
        $short = self::plain((string) ($meta['fictioneer_story_short_description'] ?? ''));
        if ($short !== '') return $short;
        return self::plain($content, keepBreaks: true);
    }

    /** Accounts come from the channel's wp:author record when one exists
     *  (real email, display name as penname); the synthetic form is the
     *  fallback for a missing record or a blank/invalid email. Password
     *  material is never in a WXR: every account is real but locked. */
    public static function mapUser(string $login, ?array $author = null): array
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($login)) ?? '', '_');
        if ($slug === '') $slug = 'author';
        $email = strtolower(trim((string) ($author['email'] ?? '')));
        $synthetic = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false;
        $display = trim((string) ($author['display_name'] ?? ''));
        return [
            'email' => $synthetic ? $slug . '@wxr-import.invalid' : $email,
            'penname' => $display !== '' ? $display : $login,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => 'validated_author',
            'synthetic_email' => $synthetic,
        ];
    }

    /** null = the domain is a category (fcn_fandom, category), not a tag. */
    public static function tagTypeFor(string $domain): ?string
    {
        return match ($domain) {
            'fcn_genre' => 'genre',
            'fcn_character', 'fcn_content_warning', 'post_tag' => 'content',
            default => null,
        };
    }

    public static function slugify(string $name): string
    {
        return \App\Slug::make($name);
    }

    /** Public plain-text extraction for meta blurbs: one line, entities decoded. */
    public static function plainDescription(string $html): string
    {
        return self::plain($html);
    }

    private static function metaAssoc(string $raw): ?array
    {
        if ($raw === '') return null;
        $v = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($v) ? $v : null;
    }

    private static function plain(string $html, bool $keepBreaks = false): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($keepBreaks) {
            return trim(preg_replace(['/[ \t]+/', '/\s*\n\s*/'], [' ', "\n"], $text) ?? $text);
        }
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
