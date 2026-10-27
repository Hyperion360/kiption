<?php // app/src/Freshness.php
declare(strict_types=1);

namespace App;

/**
 * The freshness encoding's single window (the Magic Ink batch): member
 * renders mark stories updated inside it, guests never see the mark, so a
 * cached page cannot lie. Views call fresh(); the member gate stays at the
 * call site, where loggedIn lives.
 */
final class Freshness
{
    public const WINDOW_SECONDS = 172800; // 48 hours

    public static function fresh(?string $updatedAt): bool
    {
        if ($updatedAt === null || $updatedAt === '') return false;
        $ts = strtotime($updatedAt);
        return $ts !== false && (time() - $ts) < self::WINDOW_SECONDS;
    }
}
