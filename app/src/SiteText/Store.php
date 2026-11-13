<?php // app/src/SiteText/Store.php
namespace App\SiteText;

use App\Lang;
use Kip\Database;

/**
 * The owner's saved text: languages and per-string changes in SQLite.
 * Every write is checked by Rules first and runs in a transaction. Nothing
 * here is read at render time; the Compiler turns the rows into PHP files.
 */
final class Store
{
    public function __construct(private Database $db) {}

    /** @return list<array{code: string, name: string, dir: string, owned: bool}> every installed language */
    public function languages(): array
    {
        $owned = [];
        foreach ($this->db->all('SELECT code, name, dir FROM site_languages ORDER BY code') as $r) {
            $owned[(string) $r['code']] = $r;
        }
        $out = [['code' => 'en', 'name' => Lang::name('en'), 'dir' => 'ltr', 'owned' => false]];
        foreach (Lang::builtinCodes() as $code) {
            if ($code !== 'en') {
                $out[] = ['code' => $code, 'name' => Lang::name($code), 'dir' => (Lang::builtin($code)['_dir'] ?? '') === 'rtl' ? 'rtl' : 'ltr', 'owned' => false];
            }
        }
        foreach ($owned as $code => $r) {
            if ($code !== 'en' && !in_array($code, Lang::builtinCodes(), true)) {
                $out[] = ['code' => $code, 'name' => (string) $r['name'], 'dir' => $r['dir'] === 'rtl' ? 'rtl' : 'ltr', 'owned' => true];
            }
        }
        return $out;
    }

    public function has(string $code): bool
    {
        foreach ($this->languages() as $l) {
            if ($l['code'] === $code) return true;
        }
        return false;
    }

    /** @return array<string, string> key => owner text for one language */
    public function rows(string $lang): array
    {
        $out = [];
        foreach ($this->db->all('SELECT key, value FROM site_text WHERE lang = ?', [$lang]) as $r) {
            $out[(string) $r['key']] = (string) $r['value'];
        }
        return $out;
    }

    /** Save one string. Returns null on success or the reason text. An empty
     *  value, or one equal to the built-in text, removes the owner's change. */
    public function put(string $lang, string $key, string $value): ?string
    {
        if (!$this->has($lang)) return Lang::t('text.err_language');
        $value = str_replace("\r\n", "\n", $value);
        if ($key === '' || $key[0] === '_' || !isset(Lang::base()[$key])) return Lang::t('text.err_key');
        $builtin = Lang::builtin($lang)[$key] ?? null;
        if ($value === '' || $value === $builtin) {
            $this->db->query('DELETE FROM site_text WHERE lang = ? AND key = ?', [$lang, $key]);
            return null;
        }
        $err = Rules::check($key, $value);
        if ($err !== null) return $err;
        $this->db->query(
            "INSERT INTO site_text (lang, key, value, updated_at) VALUES (?, ?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
             ON CONFLICT (lang, key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            [$lang, $key, $value]
        );
        return null;
    }

    public function addLanguage(string $code, string $name, string $dir): ?string
    {
        if (!Lang::validCode($code)) return Lang::t('text.err_code');
        if (Compiler::nameError($name)) return Lang::t('text.err_name');
        if (!in_array($dir, ['ltr', 'rtl'], true)) return Lang::t('text.err_dir');
        if ($this->has($code)) return Lang::t('text.err_exists');
        $this->db->query('INSERT INTO site_languages (code, name, dir) VALUES (?, ?, ?)', [$code, $name, $dir]);
        return null;
    }

    /** Remove an owner-added language and its text. Built-in packs cannot be removed. */
    public function removeLanguage(string $code): ?string
    {
        $row = $this->db->one('SELECT code FROM site_languages WHERE code = ?', [$code]);
        if ($row === null || in_array($code, Lang::builtinCodes(), true)) return Lang::t('text.err_remove');
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM site_text WHERE lang = ?', [$code]);
            $this->db->query('DELETE FROM site_languages WHERE code = ?', [$code]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return null;
    }

    /** Apply many strings at once, all or nothing.
     *  @param array<string, string> $map key => text
     *  @return array{saved: int, error: ?string} */
    public function import(string $lang, array $map): array
    {
        if (!$this->has($lang)) return ['saved' => 0, 'error' => Lang::t('text.err_language')];
        $this->db->begin();
        try {
            $n = 0;
            foreach ($map as $key => $value) {
                $err = $this->put($lang, (string) $key, (string) $value);
                if ($err !== null) {
                    $this->db->rollBack();
                    return ['saved' => 0, 'error' => (string) $key . ': ' . $err];
                }
                $n++;
            }
            $this->db->commit();
            return ['saved' => $n, 'error' => null];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
