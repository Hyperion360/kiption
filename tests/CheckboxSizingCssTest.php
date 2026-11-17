<?php // tests/CheckboxSizingCssTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

// A customer saw search and filter checkboxes drawn as big empty squares.
// Root cause: ".filter-form .refine-grid :is(input, select)" set width:100%
// and min-height:44px on every input, checkboxes included, so the category
// boxes in the filter form grew from 18px to a full tap target. Any rule in
// the search or filter scopes that sizes a bare "input" must exclude
// checkboxes and radios (they keep the 18px box from the base rule).
final class CheckboxSizingCssTest extends TestCase
{
    /** @return list<array{string,string}> selector list and declarations */
    private function rules(): array
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('~([^{}@]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);
        return array_map(static fn (array $r): array => [trim($r[1]), $r[2]], $m);
    }

    /** True when some selector in the list would match a checkbox input. */
    private function hitsCheckbox(string $selectors): bool
    {
        // Expand ":is(a, b)" into separate selectors, then split the list.
        $expanded = [$selectors];
        while (preg_match('~:is\(([^()]*)\)~', $expanded[0], $mm)) {
            $next = [];
            foreach ($expanded as $sel) {
                foreach (explode(',', $mm[1]) as $alt) $next[] = preg_replace('~:is\([^()]*\)~', trim($alt), $sel, 1);
            }
            $expanded = $next;
        }
        foreach ($expanded as $list) foreach (explode(',', $list) as $sel) {
            $sel = trim($sel);
            if (!preg_match('#(^|[\s>+~])input((?::not\([^)]*\))|(?:\[[^\]]*\]))*$#', $sel)) continue;
            if (preg_match('~\[type=["\']?(?!checkbox|radio)~', $sel)) continue;   // another input type
            if (preg_match('~:not\(\[type=["\']?checkbox~', $sel) && preg_match('~:not\(\[type=["\']?radio~', $sel)) continue;
            return true;
        }
        return false;
    }

    public function test_search_and_filter_scopes_never_resize_a_checkbox(): void
    {
        $bad = [];
        foreach ($this->rules() as [$sel, $decl]) {
            if (!preg_match('~filter-form|refine-grid|search-form|search-rail|tag-chip|search-refine~', $sel)) continue;
            $sizes = preg_match('~(?<![-\w])width\s*:\s*(?!auto|18px)~', $decl) || preg_match('~min-height\s*:\s*(?!0\b)~', $decl);
            if ($sizes && $this->hitsCheckbox($sel)) $bad[] = $sel;
        }
        $this->assertSame([], $bad, 'These rules would stretch checkboxes and radios; guard them with :not([type="checkbox"]):not([type="radio"])');
    }

    public function test_detector_flags_the_original_bug(): void
    {
        $this->assertTrue($this->hitsCheckbox('.filter-form .refine-grid :is(input, select)'));
        $this->assertFalse($this->hitsCheckbox('.filter-form .refine-grid :is(input:not([type="checkbox"]):not([type="radio"]), select)'));
        $this->assertFalse($this->hitsCheckbox('.search-form .search-q input[type="search"]'));
    }
}
