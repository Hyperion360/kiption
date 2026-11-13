<?php // tests/AdminNavTest.php
namespace App\Tests;

use App\AdminNav;
use App\Features\Manage\ManageController;
use PHPUnit\Framework\TestCase;

/** Which pages count as owner screens, and what the admin list and way back say about them. */
final class AdminNavTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminNav::reset();
        \App\Features::reset();
    }

    public function test_public_pages_that_share_a_word_with_owner_screens_are_not_owner_screens(): void
    {
        foreach (['/news', '/news/some-post', '/page/about', '/report/story', '/report/review', '/browse', '/', '/story/view/x'] as $path) {
            $this->assertSame([], ManageController::trail($path), "{$path} has no trail");
        }
        $this->assertNull(AdminNav::current('/news/some-post'));
        $this->assertNull(AdminNav::current('/page/about'));
        $this->assertNull(AdminNav::shell('/browse'));
    }

    public function test_writing_screens_that_share_a_word_with_public_pages_are_owner_screens(): void
    {
        $this->assertSame('/news', AdminNav::current('/news/edit/3'));
        $this->assertSame('/news', AdminNav::current('/news/remove/3'));
        $this->assertSame('/pages/manage', AdminNav::current('/page/edit/about'));
        $this->assertSame('/queue', AdminNav::current('/report/remove/5'), 'removing reported content is a queue step');
    }

    public function test_a_sub_screen_leads_back_through_its_list(): void
    {
        $this->assertSame(['/manage'], array_column(ManageController::trail('/tags'), 'url'), 'a listed screen leads back to the menu only');
        $this->assertSame([], ManageController::trail('/manage'));
        $trail = ManageController::trail('/tags/edit/3');
        $this->assertSame(['/manage', '/tags'], array_column($trail, 'url'));
    }

    public function test_the_text_list_is_the_parent_of_its_groups_not_the_messages_screen(): void
    {
        $this->assertSame('/text', AdminNav::current('/text/group/home'));
        $this->assertSame('/text/group/msg', AdminNav::current('/text/group/msg'));
    }

    public function test_trailing_slashes_do_not_change_the_answer(): void
    {
        $this->assertSame(AdminNav::current('/tags/edit/3'), AdminNav::current('/tags/edit/3/'));
        $this->assertSame('/manage', AdminNav::current('/manage/'));
    }

    public function test_the_database_group_follows_the_config_switch(): void
    {
        $urls = static fn (array $shell): array => array_merge(...array_map(static fn (array $g): array => array_column($g['links'], 'url'), $shell['groups']));
        $this->assertContains('/admin', $urls(AdminNav::shell('/queue')));
        AdminNav::init(['admin' => ['enabled' => false]]);
        $this->assertNotContains('/admin', $urls(AdminNav::shell('/queue')));
        AdminNav::init([]);
        $this->assertContains('/admin', $urls(AdminNav::shell('/queue')), 'on when the config is silent');
    }

    public function test_a_switched_off_feature_hides_its_screens_from_the_list_only(): void
    {
        $db = new \Kip\Database('sqlite::memory:');
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Features::init($db, []);
        \App\Features::toggle('news', false);
        $urls = [];
        foreach (AdminNav::shell('/queue')['groups'] as $g) foreach ($g['links'] as $l) $urls[] = $l['url'];
        $this->assertNotContains('/news', $urls);
        $this->assertNotContains('/news/new', $urls);
        $this->assertContains('/queue', $urls);
        $this->assertSame('/news', AdminNav::current('/news/edit/3'), 'the screen is still recognised if reached directly');
    }

    public function test_exactly_one_list_entry_and_at_most_one_tab_is_marked_current(): void
    {
        $shell = AdminNav::shell('/adminmembers');
        $marked = [];
        foreach ($shell['groups'] as $g) foreach ($g['links'] as $l) if ($l['current']) $marked[] = $l['url'];
        $this->assertSame(['/adminmembers'], $marked);
        $this->assertSame(['/adminmembers'], array_column(array_filter($shell['tabs'], static fn (array $t): bool => $t['current']), 'url'));
        $other = AdminNav::shell('/tags');
        $this->assertSame([], array_filter($other['tabs'], static fn (array $t): bool => $t['current']), 'a screen with no tab marks none');
    }
}
