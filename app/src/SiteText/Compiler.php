<?php // app/src/SiteText/Compiler.php
namespace App\SiteText;

use App\Lang;
use Kip\Database;

/**
 * Turns the owner's saved text into plain PHP arrays, one file per language,
 * so a page render reads no database for text. Every row is checked again
 * with Rules here: a row that got into the table some other way never reaches
 * a compiled file. Files are written to a temp name and renamed into place.
 */
final class Compiler
{
    /** Write the compiled files. Returns false (and logs) when the folder is not writable. */
    public static function compile(Database $db, string $dir): bool
    {
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }
            $owned = [];
            foreach ($db->all('SELECT code, name, dir FROM site_languages ORDER BY code') as $l) {
                if (preg_match('/^[a-z]{2,3}$/', (string) $l['code']) !== 1 || self::nameError((string) $l['name'])) {
                    continue;
                }
                $owned[(string) $l['code']] = ['name' => (string) $l['name'], 'dir' => $l['dir'] === 'rtl' ? 'rtl' : 'ltr'];
            }
            $known = array_keys($owned);
            foreach (Lang::builtinCodes() as $code) {
                $known[] = $code;
            }
            $known[] = 'en';
            $known = array_unique($known);

            $maps = [];
            foreach ($db->all('SELECT lang, key, value FROM site_text ORDER BY lang, key') as $r) {
                $lang = (string) $r['lang'];
                if (!in_array($lang, $known, true)) {
                    continue;
                }
                if (Rules::check((string) $r['key'], (string) $r['value']) !== null) {
                    continue;
                }
                $maps[$lang][(string) $r['key']] = (string) $r['value'];
            }
            foreach ($owned as $code => $meta) {
                $maps[$code] ??= [];
            }
            $keep = [];
            foreach ($maps as $code => $map) {
                if (isset($owned[$code])) {
                    $map = ['_dir' => $owned[$code]['dir'], '_name' => $owned[$code]['name']] + $map;
                }
                self::write($dir . '/' . $code . '.php', $map);
                $keep[$code . '.php'] = true;
            }
            self::write($dir . '/index.php', $owned);
            $keep['index.php'] = true;
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                if (!isset($keep[basename($file)]) && preg_match('/^[a-z]{2,3}\.php$/', basename($file)) === 1) {
                    @unlink($file);
                    self::invalidate($file);
                }
            }
            Lang::useSiteText($dir);
            return true;
        } catch (\Throwable $e) {
            error_log('Site text compile failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Display names: 1 to 40 characters, never <, >, a double quote or a line break. */
    public static function nameError(string $name): bool
    {
        $len = mb_strlen($name);
        return $len < 1 || $len > 40 || preg_match('/[<>"\r\n\x00-\x1f]/u', $name) === 1 || !mb_check_encoding($name, 'UTF-8');
    }

    private static function write(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $php = "<?php\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write ' . $file);
        }
        self::invalidate($file);
    }

    private static function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
