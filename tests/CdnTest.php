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

    /** Point error_log at a temp file; the closure reads what was logged. */
    private function captureErrorLog(): \Closure
    {
        $file = tempnam(sys_get_temp_dir(), 'kiption-cdnlog-');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $file);
        $this->logFile = $file;
        return static fn (): string => (string) file_get_contents($file);
    }

    private string $previousLog = '';
    private string $logFile = '';

    protected function tearDown(): void
    {
        if ($this->logFile !== '') {
            ini_set('error_log', $this->previousLog);
            @unlink($this->logFile);
            $this->logFile = '';
        }
    }

    /** A Cdn whose transport answers the queued HTTP statuses in order and
     *  records each request body into $sent. */
    private function cdnReturning(array $statuses, array &$sent = []): Cdn
    {
        return new Cdn(['enabled' => true, 'zone_id' => 'zone123', 'api_token' => 'secret-token'],
            function (string $url, string $body, string $token) use (&$statuses, &$sent): int {
                $sent[] = $body;
                return (int) array_shift($statuses);
            });
    }

    public function test_non_2xx_logs_status_without_token(): void
    {
        $log = $this->captureErrorLog();
        $this->assertFalse($this->cdnReturning([500])->purge('x.example/'));
        $this->assertStringContainsString('cdn purge failed: status=500 host=x.example/', $log());
        $this->assertStringNotContainsString('secret-token', $log());
        $this->assertStringNotContainsString('zone123', $log());
    }

    public function test_429_logs_rate_limited(): void
    {
        $log = $this->captureErrorLog();
        $this->assertFalse($this->cdnReturning([429])->purge('x.example/'));
        $this->assertStringContainsString('cdn purge rate-limited', $log());
    }

    public function test_prefix_400_falls_back_to_hosts_once(): void
    {
        $sent = [];
        $this->assertTrue($this->cdnReturning([400, 200], $sent)->purge('x.example/'));
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('"prefixes"', $sent[0]);
        $this->assertSame('{"hosts":["x.example"]}', $sent[1]);
    }

    public function test_a_second_400_is_logged_and_not_retried_again(): void
    {
        $log = $this->captureErrorLog();
        $sent = [];
        $this->assertFalse($this->cdnReturning([400, 400, 200], $sent)->purge('x.example/'));
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('cdn purge failed: status=400', $log());
    }
}
