<?php // tests/AgeGateTest.php
namespace App\Tests;
use App\AgeGate;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

/** The adult-rating gate every reading surface asks: only an adult-rated
 *  story without the age acknowledgement is blocked. */
final class AgeGateTest extends TestCase
{
    private function request(array $cookies = []): Request
    {
        return new Request('GET', '/', [], [], $cookies);
    }

    public function test_an_unacknowledged_reader_is_blocked_from_an_adult_story(): void
    {
        $this->assertTrue(AgeGate::blocks(['is_adult' => 1], $this->request()));
        $this->assertTrue(AgeGate::blocks(['is_adult' => '1'], $this->request()), 'SQLite hands the flag back as a string');
    }

    public function test_the_acknowledgement_cookie_opens_an_adult_story(): void
    {
        $this->assertFalse(AgeGate::blocks(['is_adult' => 1], $this->request([AgeGate::COOKIE => '1'])));
    }

    public function test_a_non_adult_or_unrated_row_is_never_blocked(): void
    {
        $this->assertFalse(AgeGate::blocks(['is_adult' => 0], $this->request()));
        $this->assertFalse(AgeGate::blocks([], $this->request()), 'a row without the column reads as not adult');
    }
}
