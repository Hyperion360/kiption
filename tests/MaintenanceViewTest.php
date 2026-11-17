<?php // tests/MaintenanceViewTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;
use Kip\View;
use PHPUnit\Framework\TestCase;

#[Small]
final class MaintenanceViewTest extends TestCase
{
    public function test_maintenance_view_renders_standalone(): void
    {
        $html = (new View(dirname(__DIR__) . '/app/views'))->render('maintenance');
        $this->assertStringContainsString('Scheduled Maintenance', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<html lang="en">', $html); // screen readers need the language
    }
}
