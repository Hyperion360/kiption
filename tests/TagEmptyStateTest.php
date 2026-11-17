<?php // tests/TagEmptyStateTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

#[Small]
final class TagEmptyStateTest extends TaxonomyCase
{
    public function test_tag_creation_waits_for_the_first_type(): void
    {
        $this->db->query('DELETE FROM story_tags');
        $this->db->query('DELETE FROM tags');
        $this->db->query('DELETE FROM tag_types');
        $admin = $this->admin();

        $html = $admin->get('/tags')->body;
        $this->assertStringNotContainsString('action="/tags/addtag"', $html);
        $this->assertStringContainsString('action="/tags/addtype"', $html);
        $this->assertStringContainsString('Add Relationships', $html);

        $response = $admin->postWithToken('/tags/addtype', ['name' => 'Relationships']);
        $this->assertSame(302, $response->status);
        $html = $admin->get('/tags')->body;
        $this->assertStringContainsString('action="/tags/addtag"', $html);
        $typeId = (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'Relationships'")['id'];
        $this->assertStringContainsString('<option value="' . $typeId . '">Relationships</option>', $html);
    }
}
