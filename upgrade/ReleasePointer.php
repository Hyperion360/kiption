<?php // upgrade/ReleasePointer.php
declare(strict_types=1);

namespace Upgrade;

/**
 * The private release pointer: the one validator and the one writer every
 * stable upgrade consumer shares (the coordinator, the private CLI, the
 * restore helper). The pointer is a plain-text file at site/current holding
 * exactly the active release ID; a release ID is 64 lowercase hex (the
 * SHA-256 of that release's RELEASE.json bytes), so a valid ID can never
 * carry a path, and the referenced directory must resolve inside
 * site/releases/ before it is honored. The two-line pointer bootstrap inside
 * each stable web wrapper stays self-contained on purpose (each stable file
 * must remain independently uploadable); everything above the bootstrap uses
 * this class, so the read/switch rules live in exactly one place.
 */
final class ReleasePointer
{
    public const POINTER = 'current';

    /** The active release ID, or null when the pointer is missing, malformed, or escapes the releases root. */
    public static function active(string $siteRoot): ?string
    {
        $raw = @file_get_contents($siteRoot . '/' . self::POINTER);
        if ($raw === false) {
            return null;
        }
        $id = trim($raw);
        return self::valid($siteRoot, $id) ? $id : null;
    }

    /**
     * Atomically point the site at $id: a 0600 temporary file in the
     * pointer's own directory, then rename. A refused ID never touches the
     * live pointer; a failed write cleans its temporary.
     */
    public static function switch(string $siteRoot, string $id): void
    {
        if (!self::valid($siteRoot, $id)) {
            throw new \InvalidArgumentException('refusing a release ID that is not an installed release');
        }
        $pointer = $siteRoot . '/' . self::POINTER;
        $tmp = $pointer . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $id . "\n") === false) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write the release pointer');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $pointer)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot switch the release pointer');
        }
    }

    /** Exactly 64 lowercase hex, and realpath(releases/<id>) stays inside the releases root. */
    public static function valid(string $siteRoot, string $id): bool
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $id) !== 1) {
            return false;
        }
        $releases = realpath($siteRoot . '/releases');
        if ($releases === false) {
            return false;
        }
        $release = realpath($releases . '/' . $id);
        return is_string($release) && str_starts_with($release, $releases . DIRECTORY_SEPARATOR);
    }
}
