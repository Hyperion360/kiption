<?php // tests/FrontControllerPassthroughTest.php
namespace App\Tests;

use App\Tests\Support\DevServerHttp;
use PHPUnit\Framework\TestCase;

/**
 * The dev-server passthrough in public/index.php: php -S with the front
 * controller as the router (the stable-wrapper layouts), where PHP's own
 * static file table never runs and the front controller labels every real
 * file itself. Every static type the app ships or writes must serve with the
 * Content-Type a production webserver sends, .php and the private setup
 * names must never serve as files, and everything else must still reach the
 * application. The private tree carries a pending setup token, so "reached
 * the application" is the setup gate's 503 and a leak would be a 200.
 */
final class FrontControllerPassthroughTest extends TestCase
{
    use DevServerHttp;

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    public function test_every_static_type_serves_with_its_content_type_and_private_names_never_serve(): void
    {
        $home = $this->tmptree('kiption-passthrough-');
        $shared = $home . '/shared';
        $public = $home . '/public';
        foreach ([$shared, $public . '/assets', $public . '/uploads', $public . '/.git'] as $dir) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($shared . '/setup-token.txt', bin2hex(random_bytes(32)) . "\n");

        // One probe per extension the map knows: the shipped assets, every
        // extension Storage may write into /uploads, and the real-file
        // artifacts written beside index.php.
        $served = [
            'assets/reader.css' => ['body{color:red}', 'text/css; charset=utf-8'],
            'assets/app.js' => ['console.log(1)', 'text/javascript; charset=utf-8'],
            'assets/mark.svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'],
            'assets/serif.woff2' => ['wOF2', 'font/woff2'],
            'uploads/0123456789abcdef.png' => ["\x89PNG\r\n\x1a\n", 'image/png'],
            'uploads/0123456789abcdef.jpg' => ["\xFF\xD8\xFF", 'image/jpeg'],
            'uploads/0123456789abcdef.jpeg' => ["\xFF\xD8\xFF", 'image/jpeg'],
            'uploads/0123456789abcdef.gif' => ['GIF89a', 'image/gif'],
            'uploads/0123456789abcdef.webp' => ['RIFF....WEBPVP8 ', 'image/webp'],
            'robots.txt' => ["User-agent: *\nAllow: /\n", 'text/plain; charset=utf-8'],
            'sitemap.xml' => ['<?xml version="1.0" encoding="UTF-8"?><sitemapindex/>', 'application/xml; charset=utf-8'],
            'favicon.ico' => ["\x00\x00\x01\x00", 'image/x-icon'],
        ];
        foreach ($served as $rel => [$body]) {
            file_put_contents($public . '/' . $rel, $body);
        }
        // .well-known is Apache's one dotfile carve-out (ACME challenges),
        // so the mirrored refusal must let a real known-type file under it
        // serve; the passthrough only ever serves known static types.
        mkdir($public . '/.well-known', 0775, true);
        file_put_contents($public . '/.well-known/probe.txt', 'challenge-bytes');
        $served['.well-known/probe.txt'] = ['challenge-bytes', 'text/plain; charset=utf-8'];
        // The never-serve set: PHP source, the private setup state misuploaded
        // into the docroot, and dotted path segments (.htaccess's two by-name
        // refusals, the second at any depth).
        $refused = [
            'setup.php' => '<?php echo "setup source";',
            'deployment.php' => '<?php return ["secret" => 1];',
            'setup-token.txt' => 'misplaced-token',
            'setup-complete.lock' => 'complete-marker',
            'setup.lock' => 'lock-marker',
            'setup-attempts' => 'attempt-counter',
            'uploads/setup-token.txt' => 'nested-token',
            '.env' => 'SECRET=1',
            '.git/config' => '[core] bare = false',
            '.git/notes.txt' => 'a servable extension under a dotted segment',
        ];
        foreach ($refused as $rel => $body) {
            file_put_contents($public . '/' . $rel, $body);
        }

        $router = $home . '/router.php';
        file_put_contents($router, '<?php
define("KIPTION_ROOT", ' . var_export(dirname(__DIR__), true) . ');
define("KIPTION_SHARED_DIR", ' . var_export($shared, true) . ');
define("KIPTION_PUBLIC_DIR", ' . var_export($public, true) . ');
return require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';');

        $port = $this->freePort(8098);
        $server = $this->startServer($port, $public, $router);
        try {
            foreach ($served as $rel => [$body, $type]) {
                [$status, $gotType, , $gotBody] = $this->httpFetch($port, '/' . $rel);
                $this->assertSame(200, $status, "/{$rel} must serve as a file");
                $this->assertSame($type, $gotType, "/{$rel} Content-Type");
                $this->assertSame($body, $gotBody, "/{$rel} body must be the file's bytes");
            }
            // The passthrough mirrors the .htaccess rules' nosniff on real
            // files: both serving paths carry the same guard.
            [, , $headers] = $this->httpFetch($port, '/assets/reader.css');
            $this->assertSame('nosniff', $headers['x-content-type-options'] ?? '', 'the file path sends nosniff like Apache does');
            foreach ($refused as $rel => $body) {
                [$status, , , $gotBody] = $this->httpFetch($port, '/' . $rel);
                $this->assertSame(503, $status, "/{$rel} must fall through to the application, never serve as a file");
                $this->assertStringNotContainsString($body, $gotBody, "/{$rel} leaked its content");
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private function tmptree(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0775, true));
        $this->tmpDirs[] = $dir;
        return $dir;
    }
}
