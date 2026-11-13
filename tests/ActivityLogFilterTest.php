<?php // tests/ActivityLogFilterTest.php
namespace App\Tests;

use App\ActivityLog;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** The log's person and kind filters, the day grouping, and the indexes the
 *  members and queue pages lean on. */
final class ActivityLogFilterTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    private function log(string $who, string $action, string $at = '2026-03-04 10:00:00'): void
    {
        $this->db->query('INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (NULL, ?, ?, ?, ?)', [$who, $action, '', $at]);
    }

    /** @return list<string> */
    private function actions(string $who = '', string $kind = ''): array
    {
        return array_column(ActivityLog::recent($this->db, 50, 0, $who, $kind), 'action');
    }

    public function test_a_person_filter_matches_the_start_of_a_name_and_treats_wildcards_as_plain_letters(): void
    {
        $this->log('anna_b', 'queue.approve');
        $this->log('annaXb', 'queue.remove');
        $this->log('100%real', 'settings.save');
        $this->log('1000real', 'features.toggle');
        $this->assertSame(['queue.remove', 'queue.approve'], $this->actions('ann'), 'a prefix, newest first');
        $this->assertSame(['queue.approve'], $this->actions('anna_'), 'an underscore is not "any letter"');
        $this->assertSame(['settings.save'], $this->actions('100%'), 'a percent sign is not "anything"');
        $this->assertSame([], $this->actions('%'));
        $this->assertSame([], $this->actions('\\'));
        $this->assertCount(4, $this->actions(''), 'no filter shows everything');
    }

    public function test_each_kind_picks_its_own_actions_and_other_is_everything_left(): void
    {
        $this->log('a', 'auth.signin');
        $this->log('a', 'queue.approve');
        $this->log('a', 'members.lock');
        $this->log('a', 'settings.save');
        $this->log('a', 'story.add');
        $this->log('a', 'kudos.add');
        $this->log('a', 'queuefoo.x');
        $this->assertSame(['auth.signin'], $this->actions('', 'signin'));
        $this->assertSame(['queue.approve'], $this->actions('', 'moderation'), 'a name that merely starts with a kind word is not that kind');
        $this->assertSame(['members.lock'], $this->actions('', 'members'));
        $this->assertSame(['settings.save'], $this->actions('', 'setup'));
        $this->assertSame(['story.add'], $this->actions('', 'writing'));
        $this->assertEqualsCanonicalizing(['queuefoo.x', 'kudos.add'], $this->actions('', 'other'));
    }

    public function test_an_unknown_kind_is_ignored_and_a_kind_can_be_combined_with_a_person(): void
    {
        $this->log('amy', 'queue.approve');
        $this->log('bob', 'queue.remove');
        $this->log('amy', 'settings.save');
        $this->assertCount(3, $this->actions('', "x' OR 1=1 --"), 'a made-up kind narrows nothing');
        $this->assertCount(3, $this->actions('', 'constructor'), 'not even a lookalike of a PHP name');
        $this->assertSame(['queue.approve'], $this->actions('amy', 'moderation'));
        $this->assertSame([], $this->actions('bob', 'setup'));
    }

    public function test_paging_still_reports_one_extra_row_under_a_filter(): void
    {
        for ($i = 0; $i < 3; $i++) $this->log('amy', 'queue.approve');
        $this->log('bob', 'queue.approve');
        $this->assertCount(3, ActivityLog::recent($this->db, 2, 0, 'amy', 'moderation'), 'limit plus one tells the caller there is another page');
        $this->assertCount(1, ActivityLog::recent($this->db, 2, 2, 'amy', 'moderation'));
    }

    public function test_days_fold_only_neighbouring_repeats_by_the_same_person(): void
    {
        $rows = [
            ['action' => 'queue.approve', 'actor_name' => 'amy', 'created_at' => '2026-03-04 12:00:00'],
            ['action' => 'queue.approve', 'actor_name' => 'amy', 'created_at' => '2026-03-04 11:00:00'],
            ['action' => 'queue.approve', 'actor_name' => 'bob', 'created_at' => '2026-03-04 10:00:00'],
            ['action' => 'queue.approve', 'actor_name' => 'amy', 'created_at' => '2026-03-04 09:00:00'],
            ['action' => 'queue.approve', 'actor_name' => 'amy', 'created_at' => '2026-03-03 23:00:00'],
        ];
        $days = ActivityLog::byDay($rows);
        $this->assertSame(['2026-03-04', '2026-03-03'], array_keys($days));
        $this->assertSame([2, 1, 1], array_map('count', $days['2026-03-04']), 'amy twice, then bob, then amy again');
        $this->assertSame([1], array_map('count', $days['2026-03-03']), 'a repeat never folds across midnight');
    }

    public function test_an_unreadable_date_lands_in_its_own_day_instead_of_failing(): void
    {
        $days = ActivityLog::byDay([['action' => 'queue.approve', 'actor_name' => 'amy', 'created_at' => 'garbage']]);
        $this->assertSame([''], array_keys($days));
        $this->assertSame([], ActivityLog::byDay([]));
    }

    public function test_the_members_page_lookups_use_their_indexes(): void
    {
        $plan = fn (string $sql): string => implode(' ', array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql), 'detail'));
        $this->assertStringContainsString('idx_activity_log_actor', $plan("SELECT MAX(created_at) FROM activity_log WHERE actor_id = 5 AND action IN ('auth.signin', 'auth.link_used')"));
        $this->assertStringContainsString('idx_reports_story', $plan('SELECT COUNT(*) FROM reports r WHERE r.story_id = 5'));
    }
}
