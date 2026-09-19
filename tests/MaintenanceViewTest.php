<?php // tests/MaintenanceViewTest.php
namespace App\Tests;
use Kip\View;
use PHPUnit\Framework\TestCase;

final class MaintenanceViewTest extends TestCase
{
    public function test_maintenance_view_renders_standalone(): void
    {
        $html = (new View(dirname(__DIR__) . '/app/views'))->render('maintenance');
        $this->assertStringContainsString('Scheduled maintenance', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<html lang="en">', $html); // screen readers need the language
    }
}
