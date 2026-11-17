<?php // tests/SiteTextRulesTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\SiteText\Rules;
use PHPUnit\Framework\TestCase;

/**
 * Owner text is printed raw by hundreds of view sites, so these rules are the
 * XSS boundary. Every rule has one case that passes and one that fails.
 */
#[Medium]
final class SiteTextRulesTest extends TestCase
{
    protected function setUp(): void
    {
        \App\Lang::setCurrent('en');
    }

    public function test_accepts_plain_text_with_ampersands_and_apostrophes(): void
    {
        $this->assertNull(Rules::check('nav.login', "Sign in & read, it's quick"));
        $this->assertNull(Rules::check('nav.login', 'Entrar'));
    }

    public function test_rejects_unknown_and_reserved_keys(): void
    {
        $this->assertNotNull(Rules::check('nav.no_such_key', 'Hello'));
        $this->assertNotNull(Rules::check('_dir', 'rtl'));
        $this->assertNotNull(Rules::check('_name', 'Español'));
        $this->assertNotNull(Rules::check('', 'Hello'));
        $this->assertNull(Rules::check('nav.login', 'Hello'));
    }

    public function test_rejects_invalid_utf8(): void
    {
        $this->assertNotNull(Rules::check('nav.login', "Log \xC3\x28 in"));
        $this->assertNull(Rules::check('nav.login', 'Conexión'));
    }

    public function test_rejects_control_characters(): void
    {
        $this->assertNotNull(Rules::check('nav.login', "Log\x00in"));
        $this->assertNotNull(Rules::check('nav.login', "Log\x1Bin"));
        $this->assertNull(Rules::check('nav.login', "Log\tin"));
    }

    public function test_length_cap_scales_with_default(): void
    {
        $this->assertNull(Rules::check('nav.login', str_repeat('a', 500)));
        $this->assertNotNull(Rules::check('nav.login', str_repeat('a', 501)));
        $long = \App\Lang::base()['maintenance.body'];
        $this->assertGreaterThan(100, strlen($long));
        $this->assertNull(Rules::check('maintenance.body', str_repeat('a', 500)));
        $this->assertNotNull(Rules::check('maintenance.body', str_repeat('a', max(500, 2 * mb_strlen($long)) + 1)));
    }

    public function test_length_counts_characters_not_bytes(): void
    {
        $this->assertNull(Rules::check('nav.login', str_repeat('é', 500)));
        $this->assertNotNull(Rules::check('nav.login', str_repeat('é', 501)));
    }

    public function test_rejects_angle_bracket_open(): void
    {
        $this->assertNotNull(Rules::check('nav.login', '<script>alert(1)</script>'));
        $this->assertNotNull(Rules::check('nav.login', 'a < b'));
        $this->assertNotNull(Rules::check('chapter.markdown_help', 'Markdown: > for quotes <b>'));
        $this->assertNull(Rules::check('nav.login', 'a &lt; b'));
    }

    public function test_allows_gt_where_default_has_it(): void
    {
        $this->assertNull(Rules::check('chapter.markdown_help', 'Markdown: > for quotes'));
    }

    public function test_rejects_gt_where_default_lacks_it(): void
    {
        $this->assertNotNull(Rules::check('nav.login', 'Log in >'));
    }

    public function test_rejects_quote_where_default_lacks_it(): void
    {
        $this->assertNotNull(Rules::check('nav.login', 'Log "in"'));
        $this->assertNull(Rules::check('nav.login', "Log 'in'"));
    }

    public function test_allows_a_quote_where_default_has_it(): void
    {
        $defaults = ['q.key' => 'Say "hi"'];
        $this->assertNull(Rules::check('q.key', 'Di "hola"', $defaults));
        $this->assertNotNull(Rules::check('q.key', 'Di <hola>', $defaults));
    }

    public function test_rejects_newlines_the_default_lacks(): void
    {
        $this->assertNotNull(Rules::check('nav.login', "Log\nin"));
        $this->assertNotNull(Rules::check('nav.login', "Log\rin"));
        $this->assertNotNull(Rules::check('nav.login', "Log\r\nin"));
    }

    public function test_allows_newlines_where_default_has_one(): void
    {
        $defaults = ['n.key' => "one\ntwo"];
        $this->assertNull(Rules::check('n.key', "uno\ndos", $defaults));
        $this->assertNull(Rules::check('n.key', "uno\n\ndos", $defaults));
        $this->assertNotNull(Rules::check('n.key', "uno\rdos", $defaults), 'a bare CR is never allowed');
        $this->assertNotNull(Rules::check('n.key', "uno\r\ndos", $defaults), 'CRLF is never allowed');
    }

    public function test_requires_every_placeholder(): void
    {
        $this->assertSame('{n} reads + kudos', \App\Lang::base()['analytics.top_count']);
        $this->assertNull(Rules::check('analytics.top_count', '{n} lecturas'));
        $this->assertNotNull(Rules::check('analytics.top_count', 'lecturas'));
        $this->assertNotNull(Rules::check('analytics.top_count', '{m} lecturas'));
    }

    public function test_rejects_placeholders_the_default_lacks(): void
    {
        $this->assertNotNull(Rules::check('nav.login', 'Log in {name}'));
        $this->assertNull(Rules::check('nav.login', 'Log in { name'));
    }

    public function test_every_shipped_default_passes_its_own_rules(): void
    {
        $bad = [];
        foreach (\App\Lang::base() as $key => $text) {
            if (str_starts_with((string) $key, '_')) continue;
            $reason = Rules::check((string) $key, (string) $text);
            if ($reason !== null) $bad[] = $key . ' => ' . $reason;
        }
        $this->assertSame([], $bad);
    }

    public function test_rules_survive_an_owner_override_of_the_current_language(): void
    {
        // The defaults come from the shipped English pack, never from whatever
        // pack is active, so a stored override can't widen what is allowed.
        $dir = sys_get_temp_dir() . '/kiption-rules-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/xx.php', '<?php return ["nav.login" => "Go <b>"];');
        \App\Lang::addPackPath('xx', $dir . '/xx.php');
        \App\Lang::setCurrent('xx');
        try {
            $this->assertNotNull(Rules::check('nav.login', 'Go <b>'));
        } finally {
            \App\Lang::setCurrent('en');
            unlink($dir . '/xx.php');
            rmdir($dir);
        }
    }
}
