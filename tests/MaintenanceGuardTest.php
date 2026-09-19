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
}
