<?php // tests/HtaccessContractTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * public/.htaccess's serving contract, pinned as source: no test host can
 * execute mod_mime/mod_headers (the suite's servers are php -S), so deleting
 * a policy line would otherwise pass the whole suite. The file ships in every
 * release ZIP, so these lines are the production behavior for real files.
 */
final class HtaccessContractTest extends TestCase
{
    private string $ht = '';

    protected function setUp(): void
    {
        $this->ht = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');
    }

    public function test_static_types_are_pinned(): void
    {
        $this->assertStringContainsString('AddType image/webp .webp', $this->ht);
        $this->assertStringContainsString('AddType text/javascript .js', $this->ht);
    }

    public function test_nosniff_covers_real_files(): void
    {
        $this->assertStringContainsString('Header set X-Content-Type-Options nosniff', $this->ht);
    }

    public function test_cache_lifetimes(): void
    {
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=14400"', $this->ht, 'unversioned assets');
        // One day, not a year: uploads are the one static shape a moderator
        // can remove, and a held copy serves until its entry expires.
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=86400"', $this->ht, 'images');
        $this->assertStringNotContainsString('31536000', $this->ht, 'no year-long retention on moderatable content');
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=3600"', $this->ht, 'robots and sitemaps');
    }

    /** Each type family carries its own lifetime: bare substring checks
     *  would pass with the extension lists swapped between blocks, so the
     *  Header line is asserted INSIDE its FilesMatch span. */
    public function test_each_type_family_carries_its_own_lifetime(): void
    {
        foreach (['css|js' => '14400', 'png|jpe?g|gif|webp|ico|svg' => '86400', 'xml|txt' => '3600'] as $ext => $age) {
            $this->assertSame(1, preg_match(
                '#<FilesMatch "\.\(' . $ext . '\)\$">\s*Header set Cache-Control "public, max-age=' . $age . '"#',
                $this->ht), "the {$ext} block and only it carries max-age={$age}");
        }
    }

    /** The page cache's files are internal targets, never URLs: a direct
     *  /cache/... request must be refused under Apache, including the
     *  percent-encoded spelling (the core decodes /%63ache/ straight onto
     *  the real files) and uppercase forms (case-insensitive hosts). */
    public function test_direct_cache_url_access_is_refused(): void
    {
        // The raw request line guard: literal and %-encoded spellings, case-insensitive,
        // immediately followed by its own [F] rule (a bare cond would chain onto
        // the ^cache rule below and rewire which requests it refuses).
        $this->assertSame(1, preg_match(
            '#RewriteCond %\{THE_REQUEST\} \\\\s/\(\?:c\|%63\)\(\?:a\|%61\)\(\?:c\|%63\)\(\?:h\|%68\)\(\?:e\|%65\)\(\?:/\|%2f\|%2F\) \[NC\]\s*\n\s*RewriteRule \^ - \[F\]#',
            $this->ht), 'the raw-request-line guard carries its own [F] rule');
        // The per-directory guard (matched against the decoded path) with the fast-path exemption.
        $this->assertStringContainsString('RewriteCond %{ENV:KIP_STATIC_HIT} ^$', $this->ht);
        $this->assertStringContainsString('RewriteRule ^cache(/|$) - [F]', $this->ht);
    }

    /** The private setup state must stay refused by name in both languages:
     *  the Apache alternation here and the front controller's array (the
     *  cross-check keeps the two from drifting apart on a rename). */
    public function test_private_setup_names_are_refused_in_both_serving_paths(): void
    {
        $names = ['deployment.php', \App\Setup\SetupGate::TOKEN, \App\Setup\SetupGate::COMPLETE, \App\Setup\SetupGate::LOCK, \App\Setup\SetupGate::ATTEMPTS];
        // .htaccess writes the names regex-escaped (deployment\.php), so the
        // alternation is matched with its escapes stripped.
        $unescaped = str_replace('\\', '', $this->ht);
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $unescaped, "{$name} must stay in the .htaccess refusal");
        }
        $index = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        foreach ($names as $name) {
            $this->assertStringContainsString("'{$name}'", $index, "{$name} must stay in the passthrough refusal");
        }
    }
}
