<?php // app/src/FooterLegal.php
namespace App;

/**
 * The operator's legal line (the Attribution precedent: init once with the
 * config at bootstrap, static reads everywhere else, because layout
 * templates have no config access). Uninitialized reads the default:
 * nothing configured, so no paragraph renders (the MIT default for an
 * operator who sets none of the footer keys).
 */
final class FooterLegal
{
    private static string $copyright = '';
    private static string $copyrightUrl = '';
    /** @var array<int, array{label: string, url: string}> */
    private static array $links = [];

    /** Capture the footer keys from the boot config; re-init re-reads.
     *  footer_links may arrive as a JSON string (the KIP_FOOTER_LINKS env
     *  shape) or an already-decoded list; anything unreadable, and any
     *  entry without a string label+url pair, reads as no link (the
     *  NavLinks::all stance). */
    public static function init(array $config): void
    {
        $holder = $config['footer_copyright'] ?? '';
        self::$copyright = is_string($holder) ? $holder : '';
        $url = $config['footer_copyright_url'] ?? '';
        self::$copyrightUrl = is_string($url) ? $url : '';
        $links = $config['footer_links'] ?? [];
        if (is_string($links)) {
            $links = json_decode($links, true);
        }
        self::$links = [];
        if (is_array($links)) {
            foreach ($links as $l) {
                if (is_array($l) && isset($l['label'], $l['url'])
                    && is_string($l['label']) && is_string($l['url'])
                    && $l['label'] !== '' && $l['url'] !== '') {
                    self::$links[] = ['label' => $l['label'], 'url' => $l['url']];
                }
            }
        }
    }

    /** Drop captured state back to unconfigured; the tearDown of init-ing
     *  test classes so no later suite inherits a legal line. */
    public static function reset(): void
    {
        self::$copyright = '';
        self::$copyrightUrl = '';
        self::$links = [];
    }

    /** Does anything render? Either a holder or at least one link. */
    public static function has(): bool
    {
        return self::$copyright !== '' || self::$links !== [];
    }

    /** The copyright holder (rendered after the links, inside the span). */
    public static function copyright(): string
    {
        return self::$copyright;
    }

    /** The holder's link target; only https and site-relative values link. */
    public static function copyrightUrl(): string
    {
        return self::$copyrightUrl;
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function links(): array
    {
        return self::$links;
    }

    /** The nav stance: only https URLs and site-relative paths link. A
     *  leading // is scheme-relative (an external host), not site
     *  relative, so it does not count. */
    public static function linkable(string $url): bool
    {
        return str_starts_with($url, 'https://')
            || (str_starts_with($url, '/') && !str_starts_with($url, '//'));
    }
}
