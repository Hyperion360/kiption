<?php // app/src/Notices.php
declare(strict_types=1);

namespace App;

/**
 * The owner's long messages (welcome, help, rules, terms, thank you, held
 * notice, printer disclaimer, maintenance). They live in the site-text store
 * under the msg.* keys, so they are per language and edited at /text. An
 * empty message means "not shown". Output goes through the Markdown renderer
 * with lists and headings on, which escapes everything: no raw HTML.
 */
final class Notices
{
    public const KEYS = ['msg.welcome', 'msg.help', 'msg.rules', 'msg.terms', 'msg.thanks', 'msg.held', 'msg.printer', 'msg.maintenance'];
    public const MAX_LENGTH = 20000;

    public static function isMessage(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** The raw Markdown for the current language, else the English text. */
    public static function text(string $key): string
    {
        if (!self::isMessage($key)) return '';
        $text = trim((string) (Lang::all(Lang::current())[$key] ?? ''));
        if ($text === '' && Lang::current() !== 'en') {
            $text = trim((string) (Lang::all('en')[$key] ?? ''));
        }
        return $text;
    }

    public static function has(string $key): bool
    {
        return self::text($key) !== '';
    }

    /** The message as safe HTML, or '' when the owner wrote nothing. */
    public static function html(string $key): string
    {
        $text = self::text($key);
        return $text === '' ? '' : Markdown::render($text, true);
    }

    /** Footer links for the pages the owner has written: [url => label key]. */
    public static function footerLinks(): array
    {
        $out = [];
        foreach (['/help' => 'msg.help', '/rules' => 'msg.rules', '/terms' => 'msg.terms'] as $url => $key) {
            if (self::has($key)) $out[$url] = 'foot.' . substr($key, 4);
        }
        // The contact form ships with the accounts slice; link it only once that screen exists.
        if (class_exists('App\\Features\\Contact\\ContactController')) $out['/contact'] = 'foot.contact';
        return $out;
    }

    /** The signup terms link: /terms when the owner wrote terms, else the configured address. */
    public static function termsUrl(string $configured): string
    {
        return self::has('msg.terms') ? '/terms' : $configured;
    }
}
