<?php // tests/CdnTest.php
namespace App\Tests;
use App\Cdn;
use PHPUnit\Framework\TestCase;

final class CdnTest extends TestCase
{
    /** @var list<array{url:string, body:string, token:string}> */
    private array $calls = [];

    private function double(bool $throws = false): \Closure
    {
        return function (string $url, string $body, string $token) use ($throws): bool {
            $this->calls[] = ['url' => $url, 'body' => $body, 'token' => $token];
            if ($throws) throw new \RuntimeException('edge on fire');
            return true;
        };
    }

    public function test_enabled_purges_the_zone_by_prefix(): void
    {
        $cdn = new Cdn(['enabled' => true, 'zone_id' => 'zone123', 'api_token' => 'sekrit'], $this->double());
        $this->assertTrue($cdn->purge('archive.example.test'));
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone123/purge_cache', $this->calls[0]['url']);
        $this->assertSame('{"prefixes":["archive.example.test"]}', $this->calls[0]['body']);
        $this->assertSame('sekrit', $this->calls[0]['token']);
    }

    public function test_disabled_makes_zero_calls(): void
    {
        $cdn = new Cdn(['enabled' => false, 'zone_id' => 'zone123', 'api_token' => 'sekrit'], $this->double());
        $this->assertFalse($cdn->purge('archive.example.test'));
        $this->assertSame([], $this->calls, 'default-off means not one HTTP call');
        // Missing keys behave the same as disabled: nothing to talk to.
        $bare = new Cdn(['enabled' => true], $this->double());
        $this->assertFalse($bare->purge('archive.example.test'));
        $this->assertSame([], $this->calls, 'enabled without zone/token/prefix is a no-op, not a broken call');
    }

    public function test_a_throwing_transport_never_escapes(): void
    {
        $cdn = new Cdn(['enabled' => true, 'zone_id' => 'zone123', 'api_token' => 'sekrit'], $this->double(throws: true));
        // If purge() let the throw escape, this test errors uncaught right
        // here, which is the failure signal: the write that queued the purge
        // already succeeded, so nothing may propagate.
        $this->assertFalse($cdn->purge('archive.example.test'), 'a failed purge reports false, never throws');
        $this->assertCount(1, $this->calls, 'the transport was attempted exactly once');
    }
}
