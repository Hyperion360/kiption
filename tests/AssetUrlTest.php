<?php
declare(strict_types=1);

namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;

use App\AssetUrl;
use PHPUnit\Framework\TestCase;

#[Medium]
final class AssetUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        AssetUrl::reset();
    }

    public function test_defaults_to_self_hosted_asset_paths(): void
    {
        self::assertMatchesRegularExpression('#^/assets/reader\.css\?v=[a-f0-9]{10}$#', AssetUrl::for('reader.css'));
        self::assertMatchesRegularExpression('#^/assets/skins/classic\.css\?v=[a-f0-9]{10}$#', AssetUrl::for('skins/classic.css'));
        self::assertSame(AssetUrl::for('reader.css'), AssetUrl::for('reader.css'), 'the version is stable between calls');
        self::assertSame('/assets/no-such-file.css', AssetUrl::for('no-such-file.css'), 'a missing file gets no version');
        self::assertSame('/favicon.ico', AssetUrl::icon('favicon.ico'));
        self::assertSame('/favicon.svg', AssetUrl::icon('favicon.svg'));
        self::assertSame('/apple-touch-icon.png', AssetUrl::icon('apple-touch-icon.png'));
    }

    public function test_release_digest_versions_every_asset_path(): void
    {
        $digest = str_repeat('a', 64);
        AssetUrl::init(['asset_digest' => $digest]);
        self::assertSame('/assets/releases/' . $digest . '/app.js', AssetUrl::for('app.js'));
        self::assertSame('/assets/releases/' . $digest . '/skins/classic.css', AssetUrl::for('skins/classic.css'));
        self::assertSame('/assets/releases/' . $digest . '/favicon.ico', AssetUrl::icon('favicon.ico'));
        self::assertSame('/assets/releases/' . $digest . '/favicon.svg', AssetUrl::icon('favicon.svg'));
        self::assertSame('/assets/releases/' . $digest . '/apple-touch-icon.png', AssetUrl::icon('apple-touch-icon.png'));
    }

    public function test_invalid_digest_and_path_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetUrl::init(['asset_digest' => '../escape']);
    }

    public function test_asset_names_cannot_escape_the_asset_tree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetUrl::for('../config.php');
    }

    public function test_only_known_site_icons_can_use_icon_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetUrl::icon('../private.svg');
    }

    public function test_all_asset_references_use_versioned_helper_and_dynamic_loader_base(): void
    {
        foreach (['app/views/layout.php', 'app/skins/classic/views/layout.php',
            'app/skins/manuscript/views/layout.php', 'app/Features/Story/views/whole.php'] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $path);
            self::assertStringNotContainsString('href="/assets/', $source, $path);
            self::assertStringNotContainsString('src="/assets/', $source, $path);
            self::assertStringNotContainsString('href="/favicon.', $source, $path);
            self::assertStringNotContainsString('href="/apple-touch-icon.png', $source, $path);
        }
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        self::assertStringNotContainsString("script.src = '/assets/'", $script);
        self::assertStringContainsString('document.currentScript.src', $script);
        self::assertStringContainsString("'?v=' + encodeURIComponent(scriptsV)", $script, 'on-demand scripts carry the version');
    }

    public function test_scripts_version_is_stable_on_plain_installs_and_empty_on_releases(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{10}$/', AssetUrl::scriptsVersion());
        self::assertSame(AssetUrl::scriptsVersion(), AssetUrl::scriptsVersion());
        AssetUrl::init(['asset_digest' => str_repeat('b', 64)]);
        self::assertSame('', AssetUrl::scriptsVersion());
    }

    public function test_every_layout_stamps_the_scripts_version_on_app_js(): void
    {
        foreach (['app/views/layout.php', 'app/skins/classic/views/layout.php', 'app/skins/manuscript/views/layout.php'] as $path) {
            self::assertStringContainsString('data-v="<?= \\App\\AssetUrl::scriptsVersion() ?>"', (string) file_get_contents(dirname(__DIR__) . '/' . $path), $path);
        }
    }
}
