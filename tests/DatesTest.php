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

    public function test_span_and_ago_read_in_the_largest_whole_unit(): void
    {
        $now = strtotime('2026-03-10 12:00:00');
        $this->assertSame('less than a minute', \App\Dates::span('2026-03-10 11:59:40', $now));
        $this->assertSame('5 minutes', \App\Dates::span('2026-03-10 11:55:00', $now));
        $this->assertSame('1 hour', \App\Dates::span('2026-03-10 11:00:00', $now));
        $this->assertSame('6 hours', \App\Dates::span('2026-03-10 06:00:00', $now));
        $this->assertSame('1 day', \App\Dates::span('2026-03-09 11:00:00', $now));
        $this->assertSame('2 days', \App\Dates::span('2026-03-08 11:00:00', $now));
        $this->assertSame('6 hours ago', \App\Dates::ago('2026-03-10 06:00:00', $now));
        $this->assertSame('just now', \App\Dates::ago('2026-03-10 11:59:50', $now));
        $this->assertSame('', \App\Dates::ago('garbage', $now));
        $this->assertSame('', \App\Dates::span(null, $now));
    }
}
