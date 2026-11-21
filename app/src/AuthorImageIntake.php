<?php // app/src/AuthorImageIntake.php
namespace App;

use Kip\{Image, ImageException};

/** What bytes an author's upload becomes. Pure policy: no request, no database, no quota. */
final class AuthorImageIntake
{
    public function __construct(private int $storedCap, private ?bool $gd = null) {}

    /** @return object{file:string,name:string,refusalKey:?string} the file to store and the upload name carrying its extension */
    public function prepare(string $tmp, string $clientName): object
    {
        $ok = static fn(string $file, string $name): object => (object) ['file' => $file, 'name' => $name, 'refusalKey' => null];
        $no = static fn(string $key): object => (object) ['file' => '', 'name' => '', 'refusalKey' => $key];
        $fits = fn(string $f): bool => (int) filesize($f) <= $this->storedCap;

        // No GD, or an animation a still re-encode would flatten: keep the original if it fits.
        if (!($this->gd ?? Image::available()) || Image::isAnimated($tmp)) {
            return $fits($tmp) ? $ok($tmp, $clientName) : $no('authorimages.too_big');
        }
        $out = $tmp . '.webp';
        try {
            Image::toWebp($tmp, $out);
        } catch (ImageException) {
            @unlink($out);
            return $no('authorimages.err_type');
        }
        if (!$fits($out)) { @unlink($out); return $no('authorimages.too_big'); }
        return $ok($out, 'image.webp');
    }
}
