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
    private const WINDOW_SECONDS = 172800; // 48 hours

    public static function fresh(?string $updatedAt): bool
    {
        if ($updatedAt === null || $updatedAt === '') return false;
        $ts = strtotime($updatedAt);
        // Future timestamps count as not fresh: importers write
        // source-provided dates, and a date ahead of the clock must not hold
        // the mark open indefinitely.
        return $ts !== false && $ts <= time() && (time() - $ts) < self::WINDOW_SECONDS;
    }
}
