<?php // app/src/Dates.php
declare(strict_types=1);

namespace App;

/**
 * The one place a view turns a stored timestamp into text. The owner picks the
 * style on /settings (date_format, time_format); every view calls this instead
 * of date(), so a change shows up everywhere at once.
 */
final class Dates
{
    /** style => [with year, without year] */
    private const DATE = [
        'short' => ['M j, Y', 'M j'],
        'long' => ['F j, Y', 'F j'],
        'dmy' => ['j M Y', 'j M'],
        'iso' => ['Y-m-d', 'Y-m-d'],
    ];
    private const TIME = ['12h' => 'g:i A', '24h' => 'H:i'];

    /** A readable date; $year false drops the year where the style allows. A blank or junk stamp reads as ''. */
    public static function format(?string $stamp, bool $year = true, bool $time = false): string
    {
        $t = $stamp === null || trim($stamp) === '' ? false : strtotime($stamp);
        if ($t === false) return '';
        $style = Settings::get('date_format');
        $pattern = (self::DATE[$style] ?? self::DATE['short'])[$year ? 0 : 1];
        $out = date($pattern, $t);
        if ($time) $out .= ' ' . date(self::TIME[Settings::get('time_format')] ?? self::TIME['12h'], $t);
        return $out;
    }

    /** How long a stamp is from now, in its largest whole unit ("2 days", "6 hours"). A blank or junk stamp reads as ''. */
    public static function span(?string $stamp, ?int $now = null): string
    {
        $t = $stamp === null || trim($stamp) === '' ? false : strtotime($stamp);
        if ($t === false) return '';
        $s = max(0, ($now ?? time()) - $t);
        foreach ([['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $size]) {
            $n = intdiv($s, $size);
            if ($n >= 1) return Lang::t('dates.span_' . $unit . ($n === 1 ? '_one' : ''), ['n' => $n]);
        }
        return Lang::t('dates.span_now');
    }

    /** The same, as something that already happened: "6 hours ago", or "just now" inside a minute. */
    public static function ago(?string $stamp, ?int $now = null): string
    {
        $span = self::span($stamp, $now);
        if ($span === '') return '';
        return $span === Lang::t('dates.span_now') ? Lang::t('dates.just_now') : Lang::t('dates.ago', ['span' => $span]);
    }

    /** Only the time of day, in the owner's 12 or 24 hour style. */
    public static function time(?string $stamp): string
    {
        $t = $stamp === null || trim($stamp) === '' ? false : strtotime($stamp);
        return $t === false ? '' : date(self::TIME[Settings::get('time_format')] ?? self::TIME['12h'], $t);
    }

    /** Date-style choices for the settings form: value => example for today. */
    public static function examples(): array
    {
        $now = time();
        $out = [];
        foreach (self::DATE as $k => [$full]) $out[$k] = date($full, $now);
        return $out;
    }

    public static function timeExamples(): array
    {
        $now = time();
        return array_map(static fn(string $p): string => date($p, $now), self::TIME);
    }
}
