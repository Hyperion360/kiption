<?php // tests/support/EfictionInstall.php
final class EfictionInstall
{
    public PDO $pdo;
    public string $prefix = 'fx_';

    public function __construct(private string $root) { $this->build(); }

    private function build(): void
    {
        $this->pdo = new PDO('sqlite:' . $this->root . '/efiction.sqlite');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p = $this->prefix;
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_authors (uid INTEGER PRIMARY KEY AUTOINCREMENT, penname TEXT, realname TEXT, email TEXT, bio TEXT, image TEXT, date TEXT, admincreated TEXT DEFAULT '0', password TEXT DEFAULT '0')");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_authorprefs (uid INTEGER PRIMARY KEY, newreviews INTEGER, newrespond INTEGER, ageconsent INTEGER, alertson INTEGER, tinyMCE INTEGER, sortby INTEGER, storyindex INTEGER, validated INTEGER, userskin TEXT, level INTEGER, categories TEXT, contact INTEGER, stories INTEGER)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_stories (sid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, summary TEXT, storynotes TEXT, catid TEXT, classes TEXT, charid TEXT, rid TEXT, date TEXT, updated TEXT, uid INTEGER, coauthors TEXT, featured TEXT, validated TEXT, completed TEXT, rr TEXT, wordcount INTEGER, rating INTEGER, reviews INTEGER, count INTEGER)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_chapters (chapid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, inorder INTEGER, notes TEXT, storytext TEXT, endnotes TEXT, validated TEXT, wordcount INTEGER, rating INTEGER, reviews INTEGER, sid INTEGER, uid INTEGER, count INTEGER)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_series (seriesid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, summary TEXT, uid INTEGER, isopen INTEGER, catid TEXT, rating INTEGER, classes TEXT, characters TEXT, reviews INTEGER, numstories INTEGER, challenges TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_inseries (seriesid INTEGER, sid INTEGER, subseriesid INTEGER DEFAULT 0, confirmed INTEGER DEFAULT 0, inorder INTEGER, PRIMARY KEY (sid, seriesid, subseriesid))");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_reviews (reviewid INTEGER PRIMARY KEY AUTOINCREMENT, item INTEGER, chapid INTEGER, reviewer TEXT, uid INTEGER, review TEXT, date TEXT, rating INTEGER, respond TEXT, type TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_favorites (uid INTEGER, item INTEGER, type TEXT, comments TEXT, UNIQUE (item, type, uid))");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_categories (catid INTEGER PRIMARY KEY AUTOINCREMENT, parentcatid INTEGER, category TEXT, description TEXT, image TEXT, locked TEXT, leveldown INTEGER, displayorder INTEGER, numitems INTEGER)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_classtypes (classtype_id INTEGER PRIMARY KEY, classtype_name TEXT UNIQUE, classtype_title TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_classes (class_id INTEGER PRIMARY KEY, class_type INTEGER, class_name TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_characters (charid INTEGER PRIMARY KEY, catid INTEGER, charname TEXT, bio TEXT, image TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_ratings (rid INTEGER PRIMARY KEY, rating TEXT, ratingwarning TEXT, warningtext TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_coauthors (sid INTEGER, uid INTEGER, PRIMARY KEY (sid, uid))");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_news (nid INTEGER PRIMARY KEY, author TEXT, title TEXT, story TEXT, time TEXT, comments INTEGER)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_comments (cid INTEGER PRIMARY KEY, nid INTEGER, uid INTEGER, comment TEXT, time TEXT)");
        // finding 7: the five tables the companion maps from
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_authorfields (field_id INTEGER PRIMARY KEY, field_type TEXT, field_name TEXT, field_title TEXT, field_options TEXT, field_order INTEGER, field_profile TEXT DEFAULT '0')");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_authorinfo (uid INTEGER, field_id INTEGER, info TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_log (log_id INTEGER PRIMARY KEY, log_action TEXT, log_uid INTEGER, log_ip INTEGER, log_timestamp TEXT, log_type TEXT)");
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_messages (message_id INTEGER PRIMARY KEY, message_name TEXT, message_title TEXT, message_text TEXT)");
        // REAL 3.5.5 columns (install/install.php:632); the 9b shape with
        // pl_type/pl_pageid was invented and exists nowhere in the source
        $this->pdo->exec("CREATE TABLE {$p}fanfiction_pagelinks (link_id INTEGER PRIMARY KEY, link_name TEXT, link_text TEXT, link_key TEXT, link_url TEXT, link_target TEXT, link_access INTEGER)");
        $this->pdo->exec("CREATE TABLE fxs_fanfiction_settings (sitekey TEXT PRIMARY KEY, tableprefix TEXT, store TEXT, storiespath TEXT, maintenance INTEGER, language TEXT)");
        // adversarial seed: penname case collision, admincreated ghost, zero dates,
        // 'No Review' sentinel, response block appended, store=files chapter
        $this->pdo->exec("INSERT INTO fxs_fanfiction_settings VALUES ('FXKEY', 'fx_', 'files', 'stories', 1, 'en')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_authors (uid, penname, email, password, date, admincreated) VALUES (1, 'Legacy Author', 'legacy@example.test', '" . md5('oldpassword') . "', '2010-05-01 10:00:00', '0')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_authors (uid, penname, email, password, date, admincreated) VALUES (2, 'legacy author ', 'second@example.test', '" . md5('otherpassword') . "', '0000-00-00 00:00:00', '1')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_authorprefs (uid, validated, level, newreviews, newrespond) VALUES (1, 1, 0, 1, 1)");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_stories (sid, title, summary, catid, classes, rid, date, updated, uid, validated, completed, wordcount, count) VALUES (7, 'Legacy Tale', 'An old story.', '1', '3', '5', '2011-01-01 00:00:00', '2012-06-15 00:00:00', 1, '1', '1', 900, 4242)");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_chapters (chapid, title, inorder, storytext, validated, wordcount, sid, uid) VALUES (10, 'One', 1, NULL, '1', 900, 7, 1)");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_reviews (reviewid, item, chapid, reviewer, uid, review, date, rating, respond, type) VALUES (99, 7, 10, '0', 2, 'Lovely.<br><br><i>Author''s Response: Thanks!</i>', '2012-01-01 00:00:00', 10, '1', 'ST')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_reviews (reviewid, item, chapid, reviewer, uid, review, date, rating, respond, type) VALUES (100, 7, 0, 'Guest Reader', 0, 'No Review', '2012-02-01 00:00:00', 5, '0', 'ST')");
        mkdir($this->root . '/stories/1', 0775, true);
        file_put_contents($this->root . '/stories/1/10.txt', 'The stored <b>chapter</b> text.');
        // seeds for the finding-7 tables: an EAV profile row, a custom page
        // pair (messages row + its viewpage pagelink, the custpages.php shape),
        // a log row
        $this->pdo->exec("INSERT INTO {$p}fanfiction_authorfields (field_id, field_name, field_title) VALUES (1, 'bio', 'Biography')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_authorinfo (uid, field_id, info) VALUES (1, 1, 'I write things.')");
        // a custom page per the REAL linkage: admin/custpages.php mints a
        // messages row (message_name/message_title/message_text) plus a
        // pagelink whose link_url = 'viewpage.php?page={message_name}'
        // (viewpage.php:35 resolves the body by message_name)
        $this->pdo->exec("INSERT INTO {$p}fanfiction_messages (message_id, message_name, message_title, message_text) VALUES (1, 'about', 'About', 'Welcome to the archive.')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_log (log_id, log_action, log_uid, log_ip, log_timestamp, log_type) VALUES (1, 'SR:7', 2, NULL, '2012-03-01 00:00:00', 'SR')");
        $this->pdo->exec("INSERT INTO {$p}fanfiction_pagelinks (link_id, link_name, link_text, link_url, link_target, link_access) VALUES (1, 'about_link', 'About', 'viewpage.php?page=about', '0', 0)");
    }

    public function installShims(): void
    {
        // The exporter calls the install's own globals; the shim provides them
        // over the fixture PDO. dbquery takes interpolated SQL (the eFiction way).
        // IDEMPOTENT: multiple fixtures per process must not redeclare.
        $GLOBALS['efi_pdo'] = $this->pdo;
        if (!function_exists('dbquery')) {
            eval(<<<'PHP'
                function dbquery(string $sql): PDOStatement { global $efi_pdo; return $efi_pdo->query($sql); }
                function dbassoc(PDOStatement $q): ?array { $r = $q->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
                function dbnumrows(PDOStatement $q): int { return $q->rowCount(); }
                function dbinsertid(): int { global $efi_pdo; return (int) $efi_pdo->lastInsertId(); }
                function escapestring(string $s): string { global $efi_pdo; return substr($efi_pdo->quote($s), 1, -1); }
            PHP);
        }
        if (!defined('TABLEPREFIX')) define('TABLEPREFIX', $this->prefix);
        if (!defined('SITEKEY')) define('SITEKEY', 'FXKEY');
        if (!defined('STORIESPATH')) define('STORIESPATH', $this->root . '/stories');
        // NOTE: because the shims are per-process singletons, each test FILE uses
        // its own fresh fixture but shares the shim; $GLOBALS['efi_pdo'] is
        // repointed at the newest fixture, so tests must not interleave fixtures
        // mid-assertion (the test classes below never do).
    }

    public function count(string $table): int { return (int) $this->pdo->query('SELECT COUNT(*) c FROM ' . $this->prefix . $table)->fetch(PDO::FETCH_ASSOC)['c']; }
    public function one(string $sql): array { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
    public function settings(): array { return $this->one("SELECT * FROM fxs_fanfiction_settings WHERE sitekey = 'FXKEY'"); }
}
