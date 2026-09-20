<?php // resources/efiction-export.php - self-contained eFiction 3.5.5 exporter.
// Ship this file as-is to the old install's webroot. It defines EfictionExporter
// and, when executed directly (browser), runs the token-gated UI at the bottom.
// PHP 8.0+, core extensions only (zlib, phar, json).

final class EfictionExporter
{
    public const VERSION = '1';
    private const TABLES = [
        'fanfiction_authors', 'fanfiction_authorprefs', 'fanfiction_stories',
        'fanfiction_chapters', 'fanfiction_series', 'fanfiction_inseries',
        'fanfiction_reviews', 'fanfiction_favorites', 'fanfiction_categories',
        'fanfiction_classtypes', 'fanfiction_classes', 'fanfiction_characters',
        'fanfiction_ratings', 'fanfiction_coauthors', 'fanfiction_news',
        'fanfiction_comments',
        // Plan review finding 7: these five are installer-created and the
        // companion design maps from them (nav links, mail templates AND
        // custom pages, the log viewer, the EAV profile). Path A is a one-shot
        // download; omitting them is unrecoverable after self-delete.
        'fanfiction_pagelinks', 'fanfiction_messages', 'fanfiction_log',
        'fanfiction_authorfields', 'fanfiction_authorinfo',
    ];

    /** @param string $installRoot absolute path of the eFiction install (config.php lives here)
     *  @param string $settingsPrefix the {settingsprefix} for the settings table
     *  @param string $outDir writable directory for bundle parts */
    public function __construct(
        private string $installRoot,
        private string $settingsPrefix,
        private string $outDir,
    ) {}

    /** Runs the export. Returns the manifest array (also written as manifest.json). */
    public function export(): array
    {
        $settings = $this->settings();
        $manifest = [
            'exporter_version' => self::VERSION,
            'efiction_version' => '3.5.5',
            'exported_at' => gmdate('c'),
            'settings' => [
                'store' => (string) $settings['store'],
                'storiespath' => (string) $settings['storiespath'],
                'maintenance' => (int) $settings['maintenance'],
                'language' => (string) ($settings['language'] ?? 'en'),
            ],
            'tableprefix' => (string) $settings['tableprefix'],
            'counts' => [],
            'invalid_utf8_replaced' => 0,
        ];
        $jsonl = gzopen($this->outDir . '/archive.jsonl.gz', 'wb9');
        foreach (self::TABLES as $i => $table) {
            $count = 0;
            // Unbuffered in situ (mysqli_use_result under the install's layer is
            // the live path; the query text is a hardcoded literal).
            $q = dbquery('SELECT * FROM ' . TABLEPREFIX . $table);
            while (($row = dbassoc($q)) !== null) {
                $row['_table'] = $table; // the reader's table discriminator (finding 1)
                $json = json_encode($this->utf8Safe($row, $manifest), JSON_UNESCAPED_UNICODE);
                gzwrite($jsonl, $json . "\n");
                $count++;
            }
            $manifest['counts'][$table] = $count;
            flush(); // progress line per table when run from the UI
        }
        gzclose($jsonl);
        if ($manifest['settings']['store'] === 'files') {
            $this->copyStoryFiles($manifest);
        }
        file_put_contents($this->outDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $manifest;
    }

    private function settings(): array
    {
        $prefixSql = escapestring($this->settingsPrefix);
        $q = dbquery("SELECT * FROM {$prefixSql}fanfiction_settings WHERE sitekey = '" . escapestring(SITEKEY) . "'");
        $row = dbassoc($q);
        if ($row === null) throw new RuntimeException('settings row not found for this sitekey');
        return $row;
    }

    /** Byte-faithful transport: replace invalid UTF-8 (the latin1 swamp) with
     *  mb's substitution character so json_encode cannot fail, and COUNT every
     *  replacement so the 9b charset heuristics know how swampy the source
     *  was (finding 10: mb_convert_encoding substitutes '?' on this build,
     *  not U+FFFD; the count, not the glyph, is the contract). */
    private function utf8Safe(array $row, array &$manifest): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $row[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8'); // substitution
                $manifest['invalid_utf8_replaced']++;
            }
        }
        return $row;
    }

    /** Copy {storiespath}/{uid}/{chapid}.txt for every chapter row, keyed by the
     *  chapter's OWN uid (author reassignment makes uid drift; read the column).
     *  BY REFERENCE (finding 5): missing_story_files must land in the manifest. */
    private function copyStoryFiles(array &$manifest): void
    {
        $srcRoot = realpath($this->installRoot . '/' . $manifest['settings']['storiespath']);
        if ($srcRoot === false) throw new RuntimeException('storiespath missing on disk');
        $q = dbquery('SELECT chapid, uid FROM ' . TABLEPREFIX . 'fanfiction_chapters');
        $dest = $this->outDir . '/stories';
        while (($row = dbassoc($q)) !== null) {
            $uid = (int) $row['uid']; $chapid = (int) $row['chapid'];
            $src = $srcRoot . '/' . $uid . '/' . $chapid . '.txt';
            if (!is_file($src)) { $manifest['missing_story_files'][] = "$uid/$chapid.txt"; continue; }
            @mkdir($dest . '/' . $uid, 0775, true);
            copy($src, $dest . '/' . $uid . '/' . $chapid . '.txt');
        }
    }

    /** The browser runner, as a pure function so tests drive it without a
     *  server. In situ the file's bottom maps $_POST to these booleans.
     *  @return array{0: string, 1: int} html + HTTP status */
    public static function runner(string $installRoot, string $settingsPrefix, string $token, bool $force, bool $run, bool $selfdelete, bool $buildBundle, ?string $selfFile = null): array
    {
        $hashFile = $installRoot . '/export-token.php';
        if (!is_file($hashFile)) {
            return [self::page('Export not authorized', '<p>Create <code>export-token.php</code> next to this file first (see the token command on your new archive), then reload.</p>'), 403];
        }
        $expected = trim((string) require $hashFile); // finding 11: admin-added whitespace must not break the compare
        if ($token === '' || !hash_equals($expected, hash('sha256', $token))) {
            return [self::page('Export not authorized', self::formHtml('')), 200]; // no oracle: same page shape
        }
        if ($selfdelete) {
            @unlink($hashFile);
            @unlink($selfFile ?? __FILE__); // finding 4: injected path so tests never unlink the repo file
            return [self::page('Cleaned up', '<p>Exporter and token file deleted. You can close this page.</p>'), 200];
        }
        $settings = dbquery('SELECT maintenance FROM ' . escapestring($settingsPrefix) . "fanfiction_settings WHERE sitekey = '" . escapestring(SITEKEY) . "'");
        $maintenance = (int) (dbassoc($settings)['maintenance'] ?? 0);
        if ($maintenance !== 1 && !$force) {
            return [self::page('Enable maintenance mode', '<p>Your archive is live and writable, so the export could capture an inconsistent snapshot. Enable maintenance mode (Admin &gt; Settings), then reload, or tick the force box if you accept the risk.</p>'), 409];
        }
        if (!$run) {
            return [self::page('Export ready', self::formHtml($token)), 200];
        }
        $outDir = $installRoot . '/out-' . bin2hex(random_bytes(6));
        mkdir($outDir, 0775, true);
        $exporter = new self($installRoot, $settingsPrefix, $outDir);
        $manifest = $exporter->export();
        if ($buildBundle) {
            // Staging dir so the tar's entry paths carry the bundle-relative
            // names exactly (archive.jsonl.gz, manifest.json, stories/...).
            $stage = $outDir . '/bundle';
            mkdir($stage . '/stories', 0775, true);
            rename($outDir . '/archive.jsonl.gz', $stage . '/archive.jsonl.gz');
            rename($outDir . '/manifest.json', $stage . '/manifest.json');
            if (is_dir($outDir . '/stories')) {
                // move stories/ contents into the stage (files are small chapter texts)
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outDir . '/stories', \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $rel = substr((string) $f->getPathname(), strlen($outDir . '/stories/'));
                    @mkdir(dirname($stage . '/stories/' . $rel), 0775, true);
                    rename((string) $f->getPathname(), $stage . '/stories/' . $rel);
                }
            }
            $tar = new \PharData($outDir . '/kiption-export.tar');
            $tar->buildFromDirectory($stage);
            // Do NOT use PharData::compress() here: under phar.readonly=1 it
            // leaves this process's phar cache serving corrupt entry data for
            // the new .tar.gz (the on-disk bytes are valid, but any
            // same-process PharData read of it, such as the bundle reader,
            // extracts garbage). gzencode of the flushed tar is deterministic
            // core PHP and reads correctly both in-process and fresh.
            unset($tar);
            $tarPath = $outDir . '/kiption-export.tar';
            file_put_contents($outDir . '/kiption-export.tar.gz', gzencode((string) file_get_contents($tarPath), 9));
            unlink($tarPath);
        }
        $counts = htmlspecialchars(json_encode($manifest['counts'], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        return [self::page('Download ready', "<p>Rows exported: <code>{$counts}</code></p>"
            . '<p>Download: <a href="out-' . basename($outDir) . '/kiption-export.tar.gz">kiption-export.tar.gz</a> '
            . '(treat it as a password file: it contains emails and legacy hashes).</p>'
            . '<form method="post"><input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
            . '<button name="selfdelete" value="1">Delete exporter and token now</button></form>'), 200];
    }

    private static function formHtml(string $token): string
    {
        $t = htmlspecialchars($token, ENT_QUOTES);
        // Gate form (empty token): a VISIBLE entry field, or the runbook's
        // "paste the token" step has no field to paste into. Authenticated
        // form (token already validated): keep the token hidden.
        $field = $token === ''
            ? '<label>Token: <input type="password" name="token" value="" autocomplete="off"></label><br>'
            : '<input type="hidden" name="token" value="' . $t . '">';
        return <<<HTML
        <form method="post">
          {$field}
          <label><input type="checkbox" name="force" value="1"> export without maintenance mode (risk an inconsistent snapshot)</label><br>
          <button name="run" value="1">Run export</button>
        </form>
        HTML;
    }

    private static function page(string $title, string $body): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES);
        return "<!doctype html><meta charset=\"utf-8\"><title>Kiption export: {$title}</title><h1>{$title}</h1>{$body}";
    }
}

if (PHP_SAPI !== 'cli' && !defined('PHPUNIT_KIP_TEST')
    && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (!defined('_BASEDIR')) define('_BASEDIR', dirname(__FILE__) . '/'); // finding 2: dbfunctions needs it
    $config = _BASEDIR . 'config.php';
    if (!is_file($config)) { http_response_code(500); echo "config.php not found; upload this file to the eFiction webroot."; exit; }
    require $config; // defines $dbconnect, $sitekey, $settingsprefix; pulls in dbfunctions
    // Finding 2: TABLEPREFIX/SITEKEY are header.php-derived in eFiction, NOT in
    // config.php; derive them here from the settings row or every query fatals.
    if (!defined('SITEKEY')) define('SITEKEY', (string) ($sitekey ?? ''));
    $pref = escapestring((string) ($settingsprefix ?? ''));
    $tpRow = dbassoc(dbquery("SELECT tableprefix FROM {$pref}fanfiction_settings WHERE sitekey = '" . escapestring(SITEKEY) . "'"));
    if (!defined('TABLEPREFIX')) define('TABLEPREFIX', (string) ($tpRow['tableprefix'] ?? ''));
    [$html, $status] = EfictionExporter::runner(
        dirname(__FILE__),
        $settingsprefix ?? '',
        $_POST['token'] ?? '',
        isset($_POST['force']),
        isset($_POST['run']),
        isset($_POST['selfdelete']),
        true,
    );
    http_response_code($status);
    echo $html;
}
