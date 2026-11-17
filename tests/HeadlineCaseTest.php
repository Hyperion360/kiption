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
        'in', 'of', 'on', 'to', 'per', 'via', 'vs', 'from', 'with', 'into', 'over', 'than', 'if', 'under',
        // Prepositions stay lowercase whatever their length (Chicago).
        'onto', 'upon', 'about', 'above', 'across', 'against', 'along', 'among', 'around', 'behind', 'below',
        'beneath', 'beside', 'between', 'beyond', 'during', 'except', 'inside', 'near', 'outside', 'past', 'since', 'through',
        'throughout', 'toward', 'until', 'within', 'without'];

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

    /** Every string classified as a heading, title, nav link, button, form label, column head, tag, menu item, tab or section
     *  name that was converted to headline case in the 2026-11 sweep. A new label added here is checked in full: first and last
     *  word capitalized, small words lowercase, the word after a hyphen or a colon capitalized. Sentences, help text, flash
     *  messages, placeholders, counts and activity-log cells are not listed; they stay sentences. */
    private const LABELS = [
        'taxonomy.back', 'categories.back', 'ratings.back', 'templates.back', 'wrangling.back', 'text.back', 'posting.back', 'reader.back_to_story',
        'user.contact_back', 'feed.whole_archive', 'feed.by_category', 'feed.atom', 'feed.rss', 'home.all_recent', 'auth.login.forgot',
        'auth.register.penname', 'auth.register.confirm_password', 'auth.register.invite_code', 'auth.reset.new_password',
        'auth.register.leave_empty', 'auth.forgot.send', 'account.penname_save', 'account.support_save', 'account.email_save',
        'account.password_save', 'account.save_prefs', 'browse.beta_reader', 'user.beta_reader', 'browse.filter_wip', 'story.wip',
        'story.round_robin', 'story.registered_only', 'story.you_left_kudos', 'story.leave_kudos', 'story.recommend', 'story.recommend_remove',
        'story.leave_coauthor', 'story.report', 'story.owner_tools', 'story.feature', 'story.unfeature', 'story.move', 'story.support',
        'story.author_reply', 'story.post_review', 'story.post_review_guest', 'review.post_comment', 'story.leave_review', 'characters.any_category',
        'characters.description', 'story.gate_continue', 'reader.where_left_off', 'reader.add_note', 'reader.edit_note', 'reader.continue',
        'reader.time_left', 'reader.focus_mode', 'reader.exit_focus', 'reader.contents_bookmarks', 'reader.size', 'reader.spacing', 'reader.width',
        'reader.mode', 'reader.end_of_chapter', 'reader.end_of_chapter_titled', 'reader.comment_on_chapter', 'story.upload_cover',
        'series.upload_cover', 'story.add_coauthor', 'story.add_chapter', 'story.delete_story', 'chapter.new', 'chapter.edit', 'chapter.note_before',
        'chapter.note_after', 'chapter.delete', 'account.section_email', 'account.section_security', 'account.section_fields', 'account.beta_pref',
        'account.sort_recent', 'account.sort_alpha', 'account.lang_follow', 'account.notify_review', 'account.notify_response',
        'account.notify_favorites', 'account.your_series', 'account.new_series', 'account.following', 'account.muted', 'account.continue_reading',
        'account.find_something', 'account.start_one', 'account.marked', 'account.cycle_mode', 'account.current_password', 'account.new_password',
        'account.confirm_new_password', 'account.new_email', 'account.delete_stories_legend', 'account.delete_keep', 'account.delete_remove',
        'account.delete_back', 'adminmembers.remove_stories_legend', 'adminmembers.remove_move', 'adminmembers.remove_remove', 'navlinks.new',
        'navlinks.edit', 'categories.parent', 'categories.move_to', 'categories.choose', 'categories.move_and_delete', 'ratings.warning',
        'ratings.move_to', 'ratings.choose', 'ratings.move_and_delete', 'news.post_comment', 'news.new_post', 'news.edit_post', 'news.delete',
        'page.new', 'page.edit', 'page.delete', 'pages.add', 'mailusers.send', 'mailusers.edit_again', 'mailusers.back', 'search.any_category',
        'search.any_rating', 'series.new', 'series.edit', 'series.m_open', 'series.m_moderated', 'series.m_closed', 'series.move_up',
        'series.move_down', 'series.story_slug', 'series.add_yours', 'lists.new', 'lists.edit', 'lists.index', 'lists.story_slug', 'lists.add_item',
        'lists.move_up', 'lists.move_down', 'challenges.new', 'challenges.edit', 'challenges.m_open', 'challenges.m_moderated',
        'challenges.m_closed', 'challenges.prompt_text', 'challenges.add_prompt', 'challenges.move_up', 'challenges.move_down',
        'challenges.story_slug', 'challenges.add_yours', 'top.favorited', 'top.kudos', 'top.reviewed', 'contact.name', 'contact.email',
        'contact.send', 'wrangling.canonical', 'wrangling.choose', 'tagqueue.in_stories', 'tagsadmin.rename_type', 'tagsadmin.delete_type',
        'invites.create', 'invites.created_by', 'invites.used_by', 'signupcheck.enabled', 'signupcheck.answer', 'profilefields.type_url',
        'profilefields.type_yesno', 'profilefields.add', 'profilefields.up', 'profilefields.down', 'profilefields.delete_heading',
        'profilefields.delete_confirm', 'profilefields.keep', 'activity.filter_clear', 'activity.kind_signin', 'activity.kind_moderation',
        'activity.kind_members', 'activity.kind_setup', 'activity.kind_writing', 'activity.kind_other', 'features.turn_on', 'features.turn_off',
        'features.comments.name', 'features.contact.name', 'features.stats.name', 'features.lists.name', 'features.toplists.name',
        'features.exports.name', 'features.directory.name', 'features.digest.name', 'features.analytics.name', 'features.releases.name',
        'features.roundrobin.name', 'features.pms.name', 'features.mute.name', 'features.wrangling.name', 'features.peruserlang.name',
        'features.perusertheme.name', 'settings.ui_lang', 'settings.group_footer', 'settings.group_maintenance', 'settings.site_name',
        'settings.registration_mode', 'settings.validation_required', 'settings.items_per_page', 'settings.powered_by', 'settings.submissions_open',
        'settings.min_words', 'settings.max_words', 'settings.coauthors_on', 'settings.series_on', 'settings.uploads_on', 'settings.default_sort',
        'settings.sort_recent', 'settings.sort_alpha', 'settings.reviews_on', 'settings.guest_reviews', 'settings.rating_only',
        'settings.profiles_public', 'settings.tag_policy_off', 'settings.tag_policy_suggest', 'settings.tag_policy_free', 'settings.date_format',
        'settings.time_format', 'settings.skin', 'settings.adult_rta', 'settings.footer_copyright', 'settings.footer_links', 'settings.og_image',
        'settings.maintenance_on', 'wordfilter.save', 'admin.confirm_heading', 'analytics.total_chapters', 'analytics.reads_by_day',
        'analytics.kudos_by_day', 'analytics.favorites_by_day', 'analytics.members_by_day', 'analytics.top_stories', 'analytics.top_authors',
        'text.section.visitors', 'text.section.members', 'text.section.admins', 'text.section.shared', 'text.add_language', 'text.ltr', 'text.rtl',
        'text.add', 'top.rated', 'analytics.new_members', 'settings.save', 'auth.to_post_register', 'images.in_use'
    ];

    /** Labels that hold a particle ("Signing In") or a literal format hint ("Label | address"), which keep their written form. */
    private const LABEL_EXEMPT = ['activity.kind_signin', 'settings.footer_links'];

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

    public function test_every_classified_label_follows_chicago_headline_case_in_full(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        foreach (self::LABELS as $key) {
            if (in_array($key, self::LABEL_EXEMPT, true)) continue;
            $this->assertArrayHasKey($key, $en, "$key is listed as a label but missing from the language pack");
            $text = $en[$key];
            $head = explode(' (', $text, 2)[0]; // a parenthetical hint stays as written
            $words = preg_split('/\s+/', trim($head));
            $last = count($words) - 1;
            foreach ($words as $i => $word) {
                $core = trim($word, ',;:"?');
                if ($core === '' || str_contains($core, '{') || str_contains($core, '/') || str_contains($core, '|') || !preg_match('/[A-Za-z]/', $core)) continue;
                $first = $i === 0 || str_ends_with($words[$i - 1], ':');
                $isSmall = in_array(strtolower($core), self::SMALL, true);
                $mustBeLower = $isSmall && !$first && $i !== $last;
                if ($mustBeLower ? $core !== strtolower($core) : !preg_match('/^[^a-z]*[A-Z0-9]/', $core)) { $bad[] = "$key => $text"; break; }
                if (str_contains($core, '-')) { // the part after a hyphen is capitalized too
                    foreach (array_slice(explode('-', $core), 1) as $part) {
                        if ($part !== '' && preg_match('/^[a-z]/', $part)) { $bad[] = "$key => $text"; break 2; }
                    }
                }
            }
        }
        $this->assertGreaterThan(250, count(self::LABELS), 'the label list must stay complete');
        $this->assertSame([], $bad, "These labels are not in Chicago headline case:\n" . implode("\n", $bad));
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
