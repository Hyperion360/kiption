<?php // tests/LanguagesTest.php
namespace App\Tests;

use App\Languages;
use PHPUnit\Framework\TestCase;

final class LanguagesTest extends TestCase
{
    public function test_english_is_the_default_and_listed_first(): void
    {
        self::assertSame('en', Languages::DEFAULT);
        self::assertSame('en', array_key_first(Languages::options()));
    }

    public function test_every_listed_code_passes_the_story_language_rule(): void
    {
        foreach (array_keys(Languages::options()) as $code) {
            self::assertSame(1, preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $code), $code);
            self::assertLessThanOrEqual(10, strlen($code), $code);
        }
    }

    public function test_a_stored_code_outside_the_list_stays_selectable(): void
    {
        self::assertArrayNotHasKey('tlh', Languages::options());
        self::assertArrayHasKey('tlh', Languages::options('tlh'));
    }

    public function test_a_listed_code_is_not_duplicated(): void
    {
        self::assertSame(count(Languages::options()), count(Languages::options('en')));
    }
}
