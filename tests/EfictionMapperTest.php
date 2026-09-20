<?php // tests/EfictionMapperTest.php
namespace App\Tests;
use App\Import\EfictionMapper;
use App\Import\Report;
use PHPUnit\Framework\TestCase;

final class EfictionMapperTest extends TestCase
{
    private string $root = '';
    private \EfictionInstall $fx;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/tests/support/EfictionInstall.php';
        $this->root = sys_get_temp_dir() . '/kiption-map-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->fx = new \EfictionInstall($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function row(string $table, string $where): array
    {
        return $this->fx->pdo->query("SELECT * FROM {$this->fx->prefix}{$table} WHERE {$where}")->fetch(\PDO::FETCH_ASSOC);
    }

    public function test_user_mapping_hash_roles_and_collisions(): void
    {
        $r = new Report();
        $u1 = EfictionMapper::mapUser($this->row('fanfiction_authors', 'uid = 1'), $this->row('fanfiction_authorprefs', 'uid = 1'), [], '|1|', $r);
        $this->assertSame('Legacy Author', $u1['penname']);
        $this->assertSame(md5('oldpassword'), $u1['legacy_md5']);
        $this->assertSame('validated_author', $u1['role']);
        $this->assertNotNull($u1['email_verified_at']);
        // ghost admin-created with zero date: unactivated member
        $u2 = EfictionMapper::mapUser($this->row('fanfiction_authors', 'uid = 2'), null, [], '|1|', $r);
        $this->assertSame('legacy author', $u2['penname'], 'trimmed only');
        $this->assertNull($u2['email_verified_at']);
        $this->assertSame('member', $u2['role']);
        $this->assertSame(2, $r->passwordLegacy, 'both fixture users carry 32-char md5s');
        // case collision suffixing: the base keeps its OWN case, the suffix disambiguates
        // (plan-bug fix: the drafted row email 'x@e.test' collided with its own
        // taken-emails context '|1|x@e.test|'; the one-char change below keeps the
        // context verbatim and also proves a PREFIX of a taken email does not
        // falsely collide, which is what the pipe delimiters are for)
        $u3 = EfictionMapper::mapUser(['uid' => 3, 'penname' => 'LEGACY AUTHOR', 'email' => 'x@e.tes', 'password' => '0', 'admincreated' => '0', 'date' => '2020-01-01 00:00:00'], null, ['legacy author'], '|1|x@e.test|', $r);
        $this->assertSame('LEGACY AUTHOR-2', $u3['penname']);
    }

    public function test_user_email_collision_rejects(): void
    {
        $r = new Report();
        $out = EfictionMapper::mapUser(['uid' => 4, 'penname' => 'Other', 'email' => 'legacy@example.test', 'password' => '0', 'admincreated' => '0', 'date' => '2020-01-01 00:00:00'], null, [], '|legacy@example.test|', $r);
        $this->assertNull($out);
        $this->assertSame(1, $r->rejects['email collision'] ?? 0);
    }

    public function test_story_mapping_csv_joins_and_dates(): void
    {
        $r = new Report();
        $ctx = ['categories' => ['1' => 'legacy-cat'], 'ratings' => ['5' => 'Teen'], 'classes' => ['3' => 'romance']];
        $s = EfictionMapper::mapStory($this->row('fanfiction_stories', 'sid = 7'), $ctx, $r);
        $this->assertSame('Legacy Tale', $s['title']);
        $this->assertSame(['legacy-cat'], $s['categories']);
        $this->assertSame(['romance'], $s['tags']);
        $this->assertSame('Teen', $s['rating_label']);
        $this->assertSame(1, $s['completed'], 'fixture story 7 is complete');
        $this->assertSame(4242, $s['legacy_reads']);
        // multi-rating rid CSV
        $s2 = EfictionMapper::mapStory(['sid' => 8, 'title' => 'T', 'summary' => null, 'catid' => ' 1 , 9 ', 'classes' => '0', 'charid' => '0', 'rid' => '5,6', 'date' => '0000-00-00 00:00:00', 'updated' => '2013-01-01 00:00:00', 'uid' => 1, 'validated' => '1', 'completed' => '0', 'featured' => '0', 'rr' => '0', 'wordcount' => 10, 'rating' => null, 'count' => 0, 'coauthors' => '0', 'storynotes' => null], $ctx, $r);
        $this->assertSame(1, $r->multiRatingStories);
        $this->assertSame(['legacy-cat'], $s2['categories'], 'space-padded CSV tokens resolve');
        $this->assertTrue($s2['created_fallback'], 'zero date marks the fallback');
    }

    public function test_review_mapping_sentinels_and_response_extraction(): void
    {
        $r = new Report();
        $rev = EfictionMapper::mapReview($this->row('fanfiction_reviews', 'reviewid = 99'), $r);
        $this->assertSame('Lovely.', $rev['body']);
        $this->assertSame('Thanks!', $rev['response']);
        $this->assertNotNull($rev['responded_at']);
        $this->assertSame(10, $rev['rating']);
        $this->assertSame(1, $r->responseBlocksExtracted);
        $only = EfictionMapper::mapReview($this->row('fanfiction_reviews', 'reviewid = 100'), $r);
        $this->assertNull($only['body'], "'No Review' sentinel becomes NULL");
        $this->assertSame('Guest Reader', $only['guest_name']);
        $this->assertSame(5, $only['rating']);
        $miss = EfictionMapper::mapReview(['reviewid' => 101, 'item' => 7, 'chapid' => 0, 'reviewer' => '0', 'uid' => 2, 'review' => 'No tail block here', 'date' => '2012-05-01 00:00:00', 'rating' => null, 'respond' => '1', 'type' => 'SE'], $r);
        $this->assertNull($miss['response']);
        $this->assertSame(1, $r->responseMisses);
        $this->assertNotNull($miss['series_item']); // SE -> series target
    }
}
