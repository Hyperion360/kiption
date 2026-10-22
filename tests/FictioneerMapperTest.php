<?php // tests/FictioneerMapperTest.php
namespace App\Tests;
use App\Import\FictioneerMapper;
use PHPUnit\Framework\TestCase;

final class FictioneerMapperTest extends TestCase
{
    public function test_meta_list_reads_serialized_arrays_and_refuses_objects(): void
    {
        $this->assertSame(['a', 'b'], FictioneerMapper::metaList(serialize(['a', 'b'])));
        $this->assertSame(null, FictioneerMapper::metaList('O:8:"Evil":0:{}'), 'object payloads are garbage, never instantiated');
        $this->assertNull(FictioneerMapper::metaList('not-serializable'));
        $this->assertNull(FictioneerMapper::metaList(null));
    }

    public function test_visibility_maps_all_eras(): void
    {
        $this->assertSame('public', FictioneerMapper::visibility('publish', []));
        $this->assertSame('scheduled', FictioneerMapper::visibility('future', []));
        $this->assertSame('unlisted', FictioneerMapper::visibility('fcn_hidden', []), '5.35+ status');
        $this->assertSame('unlisted', FictioneerMapper::visibility('publish', ['fictioneer_story_hidden' => '1']), 'pre-5.35 meta flag');
        $this->assertSame('unlisted', FictioneerMapper::visibility('publish', ['fictioneer_chapter_hidden' => '1']));
        $this->assertSame('private', FictioneerMapper::visibility('draft', []));
        $this->assertSame('private', FictioneerMapper::visibility('pending', []));
        $this->assertSame('private', FictioneerMapper::visibility('private', []));
    }

    public function test_rating_labels_map_to_the_seeded_ladder(): void
    {
        $this->assertSame('General', FictioneerMapper::ratingLabel('Everyone'));
        $this->assertSame('Teen', FictioneerMapper::ratingLabel('teen'));
        $this->assertSame('Mature', FictioneerMapper::ratingLabel('Mature'));
        $this->assertSame('Explicit', FictioneerMapper::ratingLabel('Adult'));
        $this->assertNull(FictioneerMapper::ratingLabel('Weird'));
    }

    public function test_stricter_rating_only_ever_goes_up(): void
    {
        $this->assertSame('Mature', FictioneerMapper::stricterRating('Everyone', 'Mature'));
        $this->assertSame('Explicit', FictioneerMapper::stricterRating('Mature', 'Adult'));
        $this->assertSame('Teen', FictioneerMapper::stricterRating('Teen', 'Everyone'));
        $this->assertSame('Teen', FictioneerMapper::stricterRating('Teen', 'Nonsense'), 'unknown chapter labels change nothing');
    }

    public function test_completed_maps_statuses_and_unknowns(): void
    {
        $this->assertTrue(FictioneerMapper::completed('Completed'));
        $this->assertTrue(FictioneerMapper::completed('Oneshot'));
        $this->assertFalse(FictioneerMapper::completed('Ongoing'));
        $this->assertFalse(FictioneerMapper::completed('Hiatus'));
        $this->assertFalse(FictioneerMapper::completed('Cancelled'));
        $this->assertNull(FictioneerMapper::completed('Whatever'));
    }

    public function test_meta_description_reads_both_seo_eras(): void
    {
        $new = ['fictioneer_seo_fields' => serialize(['description' => 'New era blurb', 'title' => 'T'])];
        $this->assertSame('New era blurb', FictioneerMapper::metaDescription($new));
        $old = ['fictioneer_seo_description' => 'Old era blurb'];
        $this->assertSame('Old era blurb', FictioneerMapper::metaDescription($old));
        $fallback = ['fictioneer_story_short_description' => '<p>Short &amp; sweet</p>'];
        $this->assertSame('Short & sweet', FictioneerMapper::metaDescription($fallback));
    }

    public function test_summary_prefers_short_description_and_strips_html(): void
    {
        $meta = ['fictioneer_story_short_description' => '  A  blurb. '];
        $this->assertSame('A blurb.', FictioneerMapper::summary($meta, '<p>Long body</p>'));
        $this->assertSame('Long body', FictioneerMapper::summary([], '<p>Long body</p>'));
    }

    public function test_map_user_synthesizes_account_without_an_author_record(): void
    {
        $u = FictioneerMapper::mapUser('Anna Smith');
        $this->assertSame('Anna Smith', $u['penname']);
        $this->assertSame('anna_smith@wxr-import.invalid', $u['email']);
        $this->assertTrue($u['synthetic_email']);
        $this->assertSame('validated_author', $u['role']);
        $this->assertGreaterThan(50, strlen($u['password_hash']), 'locked with a real hash nobody knows');
    }

    public function test_map_user_prefers_the_author_record(): void
    {
        $u = FictioneerMapper::mapUser('anna', ['login' => 'anna', 'email' => 'Anna@Example.org', 'display_name' => 'Anna S.']);
        $this->assertSame('anna@example.org', $u['email']);
        $this->assertSame('Anna S.', $u['penname']);
        $this->assertFalse($u['synthetic_email']);
        $bad = FictioneerMapper::mapUser('anna', ['login' => 'anna', 'email' => 'not-an-email', 'display_name' => '']);
        $this->assertSame('anna@wxr-import.invalid', $bad['email'], 'an invalid email falls back to synthetic');
        $this->assertSame('anna', $bad['penname']);
    }

    public function test_weird_logins_still_synthesize(): void
    {
        $u = FictioneerMapper::mapUser('###');
        $this->assertSame('author@wxr-import.invalid', $u['email']);
        $this->assertSame('###', $u['penname']);
    }

    public function test_iso_dates_and_zero_dates(): void
    {
        $this->assertSame('2024-01-02T03:04:05Z', FictioneerMapper::iso('2024-01-02 03:04:05'));
        $this->assertNull(FictioneerMapper::iso('0000-00-00 00:00:00'));
        $this->assertNull(FictioneerMapper::iso(null));
    }

    public function test_tag_type_domains(): void
    {
        $this->assertSame('genre', FictioneerMapper::tagTypeFor('fcn_genre'));
        $this->assertSame('content', FictioneerMapper::tagTypeFor('fcn_character'));
        $this->assertSame('content', FictioneerMapper::tagTypeFor('fcn_content_warning'));
        $this->assertSame('content', FictioneerMapper::tagTypeFor('post_tag'));
        $this->assertNull(FictioneerMapper::tagTypeFor('fcn_fandom'), 'fandoms are categories');
        $this->assertNull(FictioneerMapper::tagTypeFor('category'));
    }

    public function test_plain_description_is_one_decoded_line(): void
    {
        $this->assertSame('Hand & picked.', FictioneerMapper::plainDescription("<p>Hand &amp;\n picked.</p>"));
    }
}
