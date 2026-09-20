<?php // tests/CharsetTest.php
namespace App\Tests;
use App\Import\Charset;
use App\Import\Report;
use PHPUnit\Framework\TestCase;

final class CharsetTest extends TestCase
{
    public function test_detection_ratio_and_override(): void
    {
        $this->assertSame('utf8', Charset::detect(['héllo wörld', 'plain ascii'], 'auto'));
        $this->assertSame('latin1', Charset::detect(["h\xE9llo w\xF6rld", 'plain'], 'auto'), 'one of two invalid -> latin1');
        $this->assertSame('latin1', Charset::detect(['anything'], 'latin1'), 'override wins');
        $this->assertSame('utf8', Charset::detect(["h\xE9llo"], 'utf8'));
    }

    public function test_to_utf8_converts_and_counts(): void
    {
        $r = new Report();
        $this->assertSame('héllo', Charset::toUtf8("h\xE9llo", 'latin1', $r));
        $this->assertSame(0, $r->substitutions);
        $already = Charset::toUtf8('héllo', 'utf8', $r);
        $this->assertSame('héllo', $already);
        // utf8 mode with invalid bytes: substitution counted, never a crash
        $bad = Charset::toUtf8("bad \xB1byte", 'utf8', $r);
        $this->assertNotSame("bad \xB1byte", $bad);
        $this->assertSame(1, $r->substitutions);
    }

    public function test_repaired_double_encoding_stays_honest(): void
    {
        $r = new Report();
        // latin1 view of UTF-8 bytes (the classic swamp): converting yields the original
        $utf8 = 'héllo';
        $swamp = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        $this->assertSame('héllo', Charset::toUtf8($swamp, 'latin1', $r));
    }
}
