<?php // tests/CharacterTagHelpTextTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

// Characters hang off a category (fandom); tags are shared by every category.
// A customer found the split unclear, so each place an author or owner meets
// it says why. These pins keep the explanation from being edited away.
final class CharacterTagHelpTextTest extends TestCase
{
    private array $en;
    protected function setUp(): void { $this->en = require dirname(__DIR__) . '/app/lang/en.php'; }

    public function test_every_surface_explains_the_category_scope(): void
    {
        foreach (['story.characters_help', 'characters.help', 'tagsadmin.help', 'manage.characters_what', 'manage.tags_what'] as $key) {
            $this->assertMatchesRegularExpression('/category/i', $this->en[$key], $key);
        }
        $this->assertStringContainsString('Tags', $this->en['story.characters_help']);
        $this->assertStringContainsString($this->en['characters.tags_screen'], $this->en['characters.help'], 'the help still links the Tags screen');
    }

    public function test_help_text_has_no_em_dashes(): void
    {
        foreach (['story.characters_help', 'characters.help', 'tagsadmin.help'] as $key) {
            $this->assertStringNotContainsString("\u{2014}", $this->en[$key]);
        }
    }
}
