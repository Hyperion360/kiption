<?php // tests/MaintenanceGuardTest.php
namespace App\Tests;
use App\MaintenanceGuard;
use PHPUnit\Framework\TestCase;

final class MaintenanceGuardTest extends TestCase
{
    public function test_blocks_when_enabled_in_prod(): void
    {
        $this->assertTrue(MaintenanceGuard::blocks(
            ['maintenance' => true, 'env' => 'prod'], '/story/anything'));
    }

    public function test_never_blocks_in_dev(): void
    {
        $this->assertFalse(MaintenanceGuard::blocks(
            ['maintenance' => true, 'env' => 'dev'], '/story/anything'));
    }

    public function test_no_block_when_disabled(): void
    {
        $this->assertFalse(MaintenanceGuard::blocks(
            ['maintenance' => false, 'env' => 'prod'], '/story/anything'));
    }

    public function test_allow_prefixes_are_exempt(): void
    {
        $this->assertFalse(MaintenanceGuard::blocks(
            ['maintenance' => true, 'env' => 'prod', 'maintenance_allow' => ['/admin']],
            '/admin/queue'));
        $this->assertTrue(MaintenanceGuard::blocks(
            ['maintenance' => true, 'env' => 'prod', 'maintenance_allow' => ['/admin']],
            '/story/x'));
    }

    public function test_blocked_response_is_503_with_retry_after_hint(): void
    {
        // A bare 503 invites crawlers to retry immediately; Retry-After: 300
        // tells them to back off for five minutes while the page body
        // handles the humans.
        $res = MaintenanceGuard::response(['views' => dirname(__DIR__) . '/app/views']);
        $this->assertSame(503, $res->status);
        $this->assertSame('300', $res->headers['Retry-After']);
        $this->assertStringContainsString('Scheduled maintenance', $res->body);
    }
}
