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

    /** The browser runner (token gate, maintenance check, bundle assembly,
     *  self-delete) lands with Task 4 and replaces this stub. It must answer
     *  501 in situ until then so nothing half-works before the real runner. */
    public static function runner(string $installRoot, string $settingsPrefix, string $token, bool $force, bool $run, bool $selfdelete, bool $buildBundle, ?string $selfFile = null): array
    {
        return ['not implemented', 501];
    }
}

// The directly-executed entry (browser). Task 4 replaces this placeholder with
// the in-situ bootstrap (config.php include, _BASEDIR, SITEKEY/TABLEPREFIX
// derivation per finding 2) and the real runner call; until then the stub
// answers 501. Never fires under CLI or phpunit (PHP_SAPI guard first).
if (!defined('PHPUNIT_KIP_TEST') && isset($_SERVER['REQUEST_URI']) && PHP_SAPI !== 'cli'
    && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [$html, $status] = EfictionExporter::runner(dirname(__FILE__), '', $_POST['token'] ?? '', isset($_POST['force']), isset($_POST['run']), isset($_POST['selfdelete']), false);
    http_response_code($status);
    echo $html;
}
