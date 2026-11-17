<?php // tests/DatesTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use PHPUnit\Framework\TestCase;

/** The time-of-day half of Dates: the activity log renders it, and its
 *  guards must never print today's time for a missing or unreadable
 *  stamp. Runs on the default settings (12 hour style). */
#[Small]
final class DatesTest extends TestCase
{
    public function test_time_is_empty_for_missing_or_unreadable_stamps(): void
    {
        $this->assertSame('', \App\Dates::time(null));
        $this->assertSame('', \App\Dates::time(''));
        $this->assertSame('', \App\Dates::time('   '));
        $this->assertSame('', \App\Dates::time('garbage'));
    }

    public function test_time_renders_a_readable_stamp(): void
    {
        $this->assertNotSame('', \App\Dates::time('2026-03-04 10:00:00'));
        $this->assertMatchesRegularExpression('/^\d{1,2}:\d{2}/', \App\Dates::time('2026-03-04 10:00:00'));
    }

    public function test_ago_counts_minutes_hours_and_days_and_then_gives_the_date(): void
    {
        $now = strtotime('2026-11-17 12:00:00');
        $this->assertSame('1 minute', \App\Dates::ago('2026-11-17 11:59:50', $now));
        $this->assertSame('5 minutes', \App\Dates::ago('2026-11-17 11:55:00', $now));
        $this->assertSame('1 hour', \App\Dates::ago('2026-11-17 10:30:00', $now));
        $this->assertSame('5 hours', \App\Dates::ago('2026-11-17 07:00:00', $now));
        $this->assertSame('1 day', \App\Dates::ago('2026-11-16 10:00:00', $now));
        $this->assertSame('2 days', \App\Dates::ago('2026-11-15 11:00:00', $now));
        $this->assertSame(\App\Dates::format('2026-08-01 00:00:00'), \App\Dates::ago('2026-08-01 00:00:00', $now));
        $this->assertSame('', \App\Dates::ago('garbage', $now));
        $this->assertSame('1 minute', \App\Dates::ago('2026-11-17 12:05:00', $now), 'a clock a little ahead never reads negative');
    }
}
