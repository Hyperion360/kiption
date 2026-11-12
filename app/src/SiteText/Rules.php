<?php // app/src/SiteText/Rules.php
declare(strict_types=1);

namespace App\SiteText;

/**
 * Is this owner text safe to print? Interface text is echoed raw by hundreds
 * of view sites, so this table is the XSS boundary: the editor, the importer
 * and the compiler all run it, and a stored row only reaches a page after it
 * passes. check() returns null when the value is fine, or a short reason the
 * owner can read.
 *
 * Defaults come from the shipped English pack only (Lang::base), never from
 * the active language, so a stored override can't widen what is allowed.
 */
final class Rules
{
    private const MIN_CAP = 500;

    /** @param ?array<string, string> $defaults test seam; the shipped English pack by default */
    public static function check(string $key, string $value, ?array $defaults = null): ?string
    {
        $defaults ??= \App\Lang::base();
        if ($key === '' || $key[0] === '_' || !isset($defaults[$key]) || !is_string($defaults[$key])) {
            return \App\Lang::t('text.err_key');
        }
        $default = $defaults[$key];
        if (!mb_check_encoding($value, 'UTF-8')) {
            return \App\Lang::t('text.err_utf8');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return \App\Lang::t('text.err_control');
        }
        $max = max(self::MIN_CAP, 2 * mb_strlen($default));
        if (mb_strlen($value) > $max) {
            return \App\Lang::t('text.err_length', ['max' => $max]);
        }
        if (str_contains($value, '<')) {
            return \App\Lang::t('text.err_angle');
        }
        foreach (['>', '"'] as $char) {
            if (str_contains($value, $char) && !str_contains($default, $char)) {
                return \App\Lang::t('text.err_char', ['char' => $char]);
            }
        }
        // A line break is only legal where the shipped text has one, and then only LF.
        $badBreak = str_contains($value, "\r") || preg_match('/[\x{2028}\x{2029}]/u', $value) === 1
            || (str_contains($value, "\n") && !str_contains($default, "\n"));
        if ($badBreak) {
            return \App\Lang::t('text.err_newline');
        }
        $wanted = self::placeholders($default);
        $given = self::placeholders($value);
        foreach ($wanted as $name) {
            if (!in_array($name, $given, true)) {
                return \App\Lang::t('text.err_placeholder_missing', ['name' => '{' . $name . '}']);
            }
        }
        foreach ($given as $name) {
            if (!in_array($name, $wanted, true)) {
                return \App\Lang::t('text.err_placeholder_extra', ['name' => '{' . $name . '}']);
            }
        }
        return null;
    }

    /** @return list<string> the {name} placeholders in a string, without braces */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }
}
