<?php // tests/AuthorImageIntakeTest.php
namespace App\Tests;

use App\AuthorImageIntake;
use PHPUnit\Framework\TestCase;

final class AuthorImageIntakeTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $f) { @unlink($f); @unlink($f . '.webp'); }
    }

    private function pngFile(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 4000; $i++) imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF)); // noisy, so it is large
        $f = tempnam(sys_get_temp_dir(), 'imgt');
        imagepng($im, $f, 0);
        return $this->made[] = $f;
    }

    private function needGd(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) $this->markTestSkipped('GD with WebP is not available');
    }

    public function test_a_big_png_becomes_a_smaller_webp_under_the_stored_cap(): void
    {
        $this->needGd();
        $src = $this->pngFile(2400, 1800);
        $r = (new AuthorImageIntake(storedCap: 2 * 1024 * 1024, gd: true))->prepare($src, 'pasted-image-1.png');
        $this->assertNull($r->refusalKey);
        $this->assertStringEndsWith('.webp', $r->name);
        $this->assertLessThan(filesize($src), filesize($r->file));
        $this->assertLessThanOrEqual(2 * 1024 * 1024, filesize($r->file));
    }

    public function test_without_gd_a_file_over_the_stored_cap_is_refused(): void
    {
        $src = $this->pngFile(2400, 1800);
        $r = (new AuthorImageIntake(storedCap: 1000, gd: false))->prepare($src, 'a.png');
        $this->assertSame('authorimages.too_big', $r->refusalKey);
    }

    public function test_without_gd_a_small_file_is_stored_as_sent(): void
    {
        $src = $this->pngFile(10, 10);
        $r = (new AuthorImageIntake(storedCap: 2 * 1024 * 1024, gd: false))->prepare($src, 'a.png');
        $this->assertNull($r->refusalKey);
        $this->assertSame($src, $r->file);
        $this->assertSame('a.png', $r->name);
    }

    public function test_an_undecodable_file_is_refused_even_though_the_magic_bytes_pass(): void
    {
        $this->needGd();
        $f = tempnam(sys_get_temp_dir(), 'imgt');
        $this->made[] = $f;
        file_put_contents($f, "\x89PNG\r\n\x1a\n" . random_bytes(80));
        $r = (new AuthorImageIntake(storedCap: 2097152, gd: true))->prepare($f, 'a.png');
        $this->assertSame('authorimages.err_type', $r->refusalKey);
        $this->assertFileDoesNotExist($f . '.webp');
    }

    public function test_the_output_over_the_stored_cap_is_refused_and_leaves_no_file(): void
    {
        $this->needGd();
        $src = $this->pngFile(2400, 1800);
        $r = (new AuthorImageIntake(storedCap: 500, gd: true))->prepare($src, 'a.png');
        $this->assertSame('authorimages.too_big', $r->refusalKey);
        $this->assertFileDoesNotExist($src . '.webp');
    }

    public function test_an_animated_gif_is_kept_as_uploaded_when_it_fits(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'imgt');
        $this->made[] = $f;
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
        file_put_contents($f, "GIF89a\x01\x00\x01\x00\x00\x00\x00" . $frame . $frame . ';');
        $this->assertTrue(\Kip\Image::isAnimated($f));
        $r = (new AuthorImageIntake(storedCap: 2097152, gd: true))->prepare($f, 'a.gif');
        $this->assertNull($r->refusalKey);
        $this->assertSame($f, $r->file);
        $this->assertSame('a.gif', $r->name);
    }
}
