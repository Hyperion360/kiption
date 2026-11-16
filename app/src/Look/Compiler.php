<?php // app/src/Look/Compiler.php
declare(strict_types=1);
namespace App\Look;

/** Values in, one CSS string out. Every byte comes from our own literals, the
 *  Palette variable map (hex only), or integers. Owner text is never copied. */
final class Compiler
{
    /** Bump when the emitted CSS shape changes: it feeds the revision, so
     *  browsers refetch after an upgrade. */
    public const VERSION = 1;
    public const FILE = 'look.css';
    private const LIGHT = ['accent' => '#1f4f42', 'bg' => '#f8f7f3', 'ink' => '#1c2320'];

    /** @return array<string,string> the built-in look */
    public static function defaults(): array
    {
        return self::LIGHT + ['width' => 'standard', 'size' => 'standard', 'density' => 'standard', 'header' => 'standard'];
    }

    /** Keys are sorted first so the same values always hash the same, whatever
     *  order Rules or the stored JSON listed them in. $version is a seam so a
     *  test can prove the compiler version feeds the hash. */
    public static function revision(array $values, int $version = self::VERSION): string
    {
        ksort($values);
        return substr(sha1(json_encode($values, JSON_UNESCAPED_SLASHES) . '|' . $version), 0, 10);
    }

    /** True when all three colors equal the built-in set, compared key by key
     *  (strict array comparison depends on key order). */
    private static function colorsAreDefault(array $values): bool
    {
        foreach (self::LIGHT as $k => $v) if (($values[$k] ?? null) !== $v) return false;
        return true;
    }

    public static function css(array $values): string
    {
        $out = '/* look ' . self::revision($values) . " */\n";
        if (!self::colorsAreDefault($values)) {
            $p = Palette::derive(Color::parse($values['accent']), Color::parse($values['bg']), Color::parse($values['ink']));
            $out .= ':root{' . self::decls($p['light']) . "}\n";
            $out .= 'html[data-theme="night"]{' . self::decls($p['night']) . "}\n";
            $out .= '@media (prefers-color-scheme:dark){html:not([data-theme="paper"]):not([data-theme="sepia"]){'
                . self::decls($p['night']) . "}}\n";
        }
        foreach (array_keys(Presets::GROUPS) as $group) {
            $css = Presets::css($group, (string) ($values[$group] ?? ''));
            if ($css !== '') $out .= $css . "\n";
        }
        return $out;
    }

    /** @return bool false when the folder cannot be written (the caller decides how loud to be) */
    public static function write(array $values, string $dir): bool
    {
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
            $file = $dir . '/' . self::FILE;
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, self::css($values), LOCK_EX) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            return true;
            // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            // random_bytes or a stream wrapper failing: a fault the test environment cannot make.
            error_log('Look compile failed: ' . $e->getMessage());
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    private static function decls(array $vars): string
    {
        $o = '';
        foreach ($vars as $k => $v) $o .= $k . ':' . $v . ';';
        return $o;
    }
}
