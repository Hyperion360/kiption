<?php // tests/SlugTest.php
namespace App\Tests;
use App\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function test_make_normalizes(): void
    {
        $this->assertSame('the-rabbit-hole', Slug::make('  The Rabbit--Hole! '));
        $this->assertSame('story', Slug::make('???'));
        $this->assertSame('story', Slug::make(''));
    }

    public function test_unique_appends_suffixes(): void
    {
        $taken = ['a-story' => true, 'a-story-2' => true];
        $this->assertSame('a-story-3', Slug::unique(fn(string $s): bool => isset($taken[$s]), 'a-story'));
        $this->assertSame('free', Slug::unique(fn(string $s): bool => false, 'free'));
    }

    public function test_unique_storm_throws(): void
    {
        // 50 collisions is a bug or an attack, never a silent overwrite.
        $this->expectException(\RuntimeException::class);
        Slug::unique(fn(string $s): bool => true, 'a-story');
    }
}
