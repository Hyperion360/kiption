<?php // app/Features/Look/Tests/RulesTest.php
namespace App\Features\Look\Tests;
use App\Look\{Presets, Rules};
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    private function ok(array $over = []): array
    {
        return $over + ['accent' => '#1f4f42', 'bg' => '#f8f7f3', 'ink' => '#1c2320',
            'width' => 'standard', 'size' => 'standard', 'density' => 'standard', 'header' => 'standard'];
    }

    public function test_a_clean_submission_normalizes_hex_to_lowercase(): void
    {
        $r = Rules::check($this->ok(['accent' => '#1F4F42']));
        $this->assertSame([], $r['errors']);
        $this->assertSame('#1f4f42', $r['values']['accent']);
    }

    /** CSS injection: none of these may ever pass, whatever the field. */
    public function test_css_injection_payloads_are_rejected(): void
    {
        foreach (['#fff;}body{display:none}', "#1f4f42\n", '#1f4f42/**/', 'url(http://evil.test/x)',
                  'expression(alert(1))', '#1f4f42;background:url(//e)', '</style><script>x</script>',
                  'red', 'rgb(1,2,3)', '#12345', '#1234567', "\0#1f4f42", 'javascript:1'] as $payload) {
            foreach (['accent', 'bg', 'ink'] as $field) {
                $r = Rules::check($this->ok([$field => $payload]));
                $this->assertArrayHasKey($field, $r['errors'], $field . ' accepted ' . var_export($payload, true));
                $this->assertSame([], $r['values'], 'no partial values on error');
            }
        }
    }

    public function test_presets_accept_only_known_names(): void
    {
        foreach (['width', 'size', 'density', 'header'] as $group) {
            foreach (['', 'WIDE', 'wide ', '640px', 'wide;}', '0', 'constructor', '../x'] as $bad) {
                $this->assertArrayHasKey($group, Rules::check($this->ok([$group => $bad]))['errors'], $group . ' ' . $bad);
            }
        }
        $this->assertSame([], Rules::check($this->ok(['width' => 'wide', 'header' => 'tinted']))['errors']);
    }

    public function test_every_listed_preset_name_passes(): void
    {
        foreach (array_keys(Presets::GROUPS) as $group) {
            foreach (Presets::names($group) as $name) {
                $this->assertSame([], Rules::check($this->ok([$group => $name]))['errors'], "$group $name");
            }
        }
        $this->assertSame('', Presets::css('width', 'nope'));
        $this->assertSame([], Presets::names('nope'));
    }

    public function test_array_and_missing_values_are_errors_not_exceptions(): void
    {
        $this->assertArrayHasKey('accent', Rules::check($this->ok(['accent' => ['#1f4f42']]))['errors']);
        $this->assertArrayHasKey('width', Rules::check($this->ok(['width' => ['wide']]))['errors']);
        $in = $this->ok(); unset($in['bg']);
        $this->assertSame('look.err_missing', Rules::check($in)['errors']['bg'][0]);
        $in = $this->ok(); unset($in['header']);
        $this->assertSame('look.err_missing', Rules::check($in)['errors']['header'][0]);
    }

    public function test_unreadable_text_is_blocked_with_the_ratio(): void
    {
        $r = Rules::check($this->ok(['ink' => '#cccccc', 'bg' => '#ffffff']));
        $this->assertSame('look.err_contrast_text', $r['errors']['ink'][0]);
        $this->assertGreaterThan(1.0, (float) $r['errors']['ink'][1]['ratio']);
        $this->assertSame([], $r['values']);
    }

    public function test_unreadable_accent_is_blocked_with_the_ratio(): void
    {
        $r = Rules::check($this->ok(['accent' => '#f0f0f0']));
        $this->assertSame('look.err_contrast_accent', $r['errors']['accent'][0]);
        $same = Rules::check($this->ok(['accent' => '#777777', 'bg' => '#777777', 'ink' => '#000000']));
        $this->assertSame('look.err_contrast_accent', $same['errors']['accent'][0]);
        $this->assertSame('1.0', $same['errors']['accent'][1]['ratio']);
    }

    public function test_ratio_formatting_does_not_leak_locale(): void
    {
        $r = Rules::check($this->ok(['ink' => '#cccccc', 'bg' => '#ffffff']));
        $this->assertMatchesRegularExpression('/^\d+\.\d$/D', $r['errors']['ink'][1]['ratio']);
    }

    public function test_the_gates_sit_exactly_on_their_floors(): void
    {
        // #767676 on white is 4.54 (passes text); #777777 is 4.48 (blocked).
        $this->assertSame([], Rules::check($this->ok(['bg' => '#ffffff', 'ink' => '#767676']))['errors']);
        $this->assertArrayHasKey('ink', Rules::check($this->ok(['bg' => '#ffffff', 'ink' => '#777777']))['errors']);
        // the ratio shown never rounds a failing value up to the floor
        $this->assertSame('4.4', Rules::check($this->ok(['bg' => '#ffffff', 'ink' => '#777777']))['errors']['ink'][1]['ratio']);
    }
}
