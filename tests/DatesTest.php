<?php // tests/DatesTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** The time-of-day half of Dates: the activity log renders it, and its
 *  guards must never print today's time for a missing or unreadable
 *  stamp. Runs on the default settings (12 hour style). */
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
}
