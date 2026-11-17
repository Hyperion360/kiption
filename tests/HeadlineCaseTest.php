<?php // tests/HeadlineCaseTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/** Headings, titles, labels, links and buttons follow Chicago headline case:
 *  every word is capitalized except articles, short conjunctions and
 *  prepositions (never the first word). Sentences, fragments that sit inside
 *  a sentence, and parenthetical hints are left alone. */
#[Small]
final class HeadlineCaseTest extends TestCase
{
    private const SMALL = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet', 'as', 'at', 'by',
        'in', 'of', 'on', 'to', 'per', 'via', 'vs', 'from', 'with', 'into', 'over', 'than', 'if', 'under'];

    /** Keys whose value is a heading, label, link or button, and is not a sentence. */
    private const KEY = '/(^|\.|_)(heading|title|legend|label|link|submit|button|column_\w+)$|^text\.group\./';
    /** Not headings: a label that is not a heading, or a link that ends a sentence. */
    private const SKIP = ['auth.terms_label', 'series.membership_label',
        'settings.waiting_link', 'maintenance.banner_link', 'settings.penname_pointer_link',
        'look.sample_link', // the sample anchor's text on the Colors and Layout screen, not a link in running copy
        // "Sign In" is a phrasal verb and "On and Off" are adverbs, so they keep their capitals.
        'auth.link.title', 'auth.link.heading', 'features.heading', 'text.group.features',
        'auth.login.heading', 'auth.link.magic_heading', 'auth.link.magic_submit']; // "Log In" and "Sign In" are phrasal verbs too (see PHRASAL)

    /** Header, menu, footer, admin sidebar, queue and roster strings whose keys the pattern above cannot see.
     *  Each is a link, button, heading, label or option, so each follows headline case. */
    private const LISTED = [
        'nav.login', 'nav.logout', 'nav.join', 'nav.inbox', 'nav.write', 'nav.read', 'nav.browse', 'nav.recent', 'nav.authors', 'nav.series',
        'nav.library', 'nav.menu', 'nav.manage', 'nav.queue', 'nav.site_text', 'nav.post_news', 'nav.post_story', 'nav.view_profile',
        'nav.panel_categories', 'nav.panel_ways', 'nav.panel_tags', 'nav.all_categories', 'nav.filter_tags', 'nav.way_recent', 'nav.way_complete',
        'nav.start_heading', 'nav.start_story', 'foot.lists', 'foot.contact', 'foot.legal', 'foot.terms', 'foot.rules', 'foot.help', 'foot.powered_by',
        'account.your_stories', 'account.settings', 'auth.back_to_login',
        'adminmembers.profile', 'adminmembers.remove_move_to', 'adminmembers.role.validated_author', 'adminmembers.make.member',
        'adminmembers.make.validated_author', 'adminmembers.make.moderator', 'adminmembers.make.admin',
        'adminmembers.filter.waiting', 'adminmembers.status.unapproved',
        'queue.members', 'queue.opening', 'queue.report_text', 'queue.remove_why', 'queue.reason_none', 'queue.reason_more', 'queue.remove_content',
        'queue.preset_policy_label', 'queue.preset_content_label', 'queue.preset_spam_label', 'queue.preset_quality_label', 'queue.remove_confirm_btn',
        'search.nomatch_drop', 'settings.chapter_images_on', 'cloud.learn_more',
        'manage.now', 'manage.recent', 'manage.recent_all', 'manage.all_screens', 'manage.find', 'manage.find_key',
        'manage.group_stories', 'manage.group_pages', 'manage.group_members', 'manage.group_look', 'manage.group_settings', 'manage.group_advanced',
        'manage.queue', 'manage.images', 'manage.news_post', 'manage.page_new', 'manage.profilefields', 'manage.signupcheck', 'manage.activity',
        'manage.invites', 'manage.members', 'manage.mailusers', 'manage.news', 'manage.pages', 'manage.templates', 'manage.nav', 'manage.site_text',
        'manage.messages', 'manage.categories', 'manage.ratings', 'manage.tags', 'manage.tagqueue', 'manage.characters', 'manage.wordfilter',
        'manage.wrangling', 'manage.analytics', 'manage.settings', 'manage.features', 'manage.database', 'manage.look',
        'manage.cta_review', 'manage.cta_approve', 'manage.cta_look', 'manage.cta_sort',
    ];
    /** A phrasal verb keeps its particle capitalized in a heading ("Log In", "Sign Out"), so a lowercase one is sentence case. */
    private const PHRASAL = '/\b(log|sign) (in|out|up)\b/';

    public function test_ui_headings_use_chicago_headline_case(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        $checked = 0;
        foreach ($en as $key => $text) {
            if (!is_string($key) || !is_string($text) || !preg_match(self::KEY, $key)) continue;
            if (in_array($key, self::SKIP, true) || str_ends_with($key, '_aria') || str_starts_with($key, 'notifications.')) continue;
            if (preg_match('/[.?!]$/', $text) || str_word_count($text) > 9) continue;
            $checked++;
            $head = explode(' (', $text, 2)[0];
            $words = preg_split('/\s+/', trim($head));
            foreach ($words as $i => $word) {
                if ($word === '-') continue;
                $start = $i === 0 || $words[$i - 1] === '-'; // the first word, or the one after a dash
                $w = trim($word, ',;:"');
                if (!$start && in_array(strtolower($w), self::SMALL, true) && $w !== strtolower($w)) { $bad[] = "$key => $text"; break; } // a small word capitalized mid-title
                if ($w === '' || !preg_match('/^[a-z]/', $w)) continue; // capitalized, a placeholder, a number or a symbol
                if (str_contains($w, '.') || str_contains($w, '/') || str_contains($w, '@')) continue;
                if (!$start && in_array($w, self::SMALL, true)) continue;
                $bad[] = "$key => $text";
                break;
            }
        }
        $this->assertGreaterThan(150, $checked, 'the key pattern must keep matching the heading strings');
        $this->assertSame([], $bad, "These strings are not in Chicago headline case:\n" . implode("\n", $bad));
    }

    public function test_header_menu_footer_and_admin_labels_use_chicago_headline_case(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        foreach (self::LISTED as $key) {
            $this->assertArrayHasKey($key, $en, "$key is listed here but missing from the language pack");
            $text = $en[$key];
            $head = explode(' (', $text, 2)[0]; // a parenthetical hint stays as written
            foreach (preg_split('/\s+/', trim($head)) as $i => $word) {
                $w = trim($word, ',;:"?');
                if ($w === '' || !preg_match('/^[a-z]/', $w) || str_contains($w, '/')) continue; // capitalized, a number or a symbol
                if ($i > 0 && in_array($w, self::SMALL, true)) continue;
                $bad[] = "$key => $text";
                break;
            }
        }
        $this->assertSame([], $bad, "These strings are not in Chicago headline case:\n" . implode("\n", $bad));
    }

    public function test_log_in_and_sign_in_keep_their_particle_capitalized(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        foreach (['nav.login', 'nav.logout', 'auth.login.heading', 'auth.link.magic_heading', 'auth.link.magic_submit', 'auth.back_to_login'] as $key) {
            if (preg_match(self::PHRASAL, $en[$key])) $bad[] = "$key => {$en[$key]}";
        }
        $this->assertSame([], $bad, "A phrasal verb's particle is capitalized in a heading or link:\n" . implode("\n", $bad));
    }
}
