<?php // tests/LangContextTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Interface text is printed raw in element text all over the views, so the
 * only places it can do harm are the non-text contexts. This scan walks every
 * template with a small HTML state machine and fails when Lang::t is echoed
 * into a script or style body, a single-quoted or unquoted attribute, a bare
 * tag, or an event-handler attribute, or into a double-quoted attribute
 * without escaping.
 */
final class LangContextTest extends TestCase
{
    private const SAFE_WRAP = '/(->e\(|htmlspecialchars\(|json_encode\(|rawurlencode\(|urlencode\()/';
    private const JSON_FLAGS = ['JSON_HEX_TAG', 'JSON_HEX_APOS', 'JSON_HEX_QUOT', 'JSON_HEX_AMP'];

    /** @return list<string> one line per unsafe print site found in $src */
    private static function unsafeSites(string $src): array
    {
        $found = [];
        $state = 'text';
        $raw = null;
        $tokens = token_get_all($src);
        $n = count($tokens);
        $attrName = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) continue;
            if ($t[0] === T_INLINE_HTML) {
                $h = $t[1];
                $len = strlen($h);
                for ($j = 0; $j < $len; $j++) {
                    $c = $h[$j];
                    if ($state === 'text') {
                        if ($c === '<' && preg_match('/^<[a-zA-Z\/!]/', substr($h, $j, 3)) === 1) {
                            $raw = preg_match('/^<(script|style)\b/i', substr($h, $j, 10), $m) === 1 ? strtolower($m[1]) : null;
                            $state = 'tag';
                            $attrName = '';
                        }
                    } elseif ($state === 'tag') {
                        if ($c === '>') {
                            $state = $raw !== null ? 'raw:' . $raw : 'text';
                        } elseif ($c === '"') {
                            $state = 'dq';
                            $attrName = self::attrBefore($h, $j);
                        } elseif ($c === "'") {
                            $state = 'sq';
                            $attrName = self::attrBefore($h, $j);
                        }
                    } elseif ($state === 'dq') {
                        if ($c === '"') $state = 'tag';
                    } elseif ($state === 'sq') {
                        if ($c === "'") $state = 'tag';
                    } elseif (str_starts_with($state, 'raw:')) {
                        $rt = substr($state, 4);
                        if ($c === '<' && stripos(substr($h, $j, 9), '</' . $rt) === 0) {
                            $state = 'tag';
                            $raw = null;
                        }
                    }
                }
                continue;
            }
            if ($t[0] !== T_OPEN_TAG_WITH_ECHO) continue;
            $expr = '';
            $k = $i + 1;
            while ($k < $n && !(is_array($tokens[$k]) && $tokens[$k][0] === T_CLOSE_TAG)) {
                $expr .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $k++;
            }
            $i = $k;
            if (!str_contains($expr, 'Lang::t')) continue;
            $wrapped = preg_match(self::SAFE_WRAP, $expr) === 1;
            $site = $state . ': ' . trim($expr);
            if ($state === 'text') continue;
            if ($state === 'dq') {
                $handler = preg_match('/^on[a-z]+$/i', $attrName) === 1;
                $cssSafe = $attrName === 'style' && str_contains($expr, "str_replace([\"'\", '\\\\'], ''");
                if ($cssSafe) continue; // quotes and backslashes stripped before escaping
                $jsSafe = str_contains($expr, 'json_encode(') && str_contains($expr, '->e(');
                foreach (self::JSON_FLAGS as $flag) $jsSafe = $jsSafe && str_contains($expr, $flag);
                if (!$wrapped || ($handler && !$jsSafe)) $found[] = $site;
                continue;
            }
            $found[] = $site; // script/style body, sq, unquoted, bare tag
        }
        return $found;
    }

    private static function attrBefore(string $h, int $quotePos): string
    {
        $start = max(0, $quotePos - 40);
        return preg_match('/([a-zA-Z-]+)=$/', substr($h, $start, $quotePos - $start), $m) === 1 ? $m[1] : '';
    }

    public function test_scanner_flags_a_raw_print_in_a_script(): void
    {
        $this->assertNotSame([], self::unsafeSites("<script>var a = <?= \\App\\Lang::t('x') ?>;</script>"));
    }

    public function test_scanner_flags_a_single_quoted_attribute(): void
    {
        $this->assertNotSame([], self::unsafeSites("<a title='<?= \$this->e(\\App\\Lang::t('x')) ?>'>"));
    }

    public function test_scanner_flags_an_unescaped_double_quoted_attribute(): void
    {
        $this->assertNotSame([], self::unsafeSites("<a title=\"<?= \\App\\Lang::t('x') ?>\">"));
    }

    public function test_scanner_flags_an_event_handler_with_html_escaping_only(): void
    {
        $this->assertNotSame([], self::unsafeSites("<b onclick=\"return confirm('<?= \$this->e(\\App\\Lang::t('x')) ?>')\">"));
    }

    public function test_scanner_flags_a_concatenated_handler(): void
    {
        $this->assertNotSame([], self::unsafeSites("<b<?= ' onclick=\"x(\\'' . \\App\\Lang::t('x') . '\\')\"' ?>>"));
    }

    public function test_scanner_flags_bare_json_in_a_handler(): void
    {
        // json_encode's own double quotes would close the attribute
        $this->assertNotSame([], self::unsafeSites(
            "<b onclick=\"return confirm(<?= json_encode(\\App\\Lang::t('x'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)\">"
        ));
    }

    public function test_scanner_wants_all_four_json_hex_flags_in_a_handler(): void
    {
        $this->assertNotSame([], self::unsafeSites(
            "<b onclick=\"return confirm(<?= \$this->e(json_encode(\\App\\Lang::t('x'), JSON_HEX_TAG)) ?>)\">"
        ));
    }

    public function test_scanner_accepts_the_safe_shapes(): void
    {
        $this->assertSame([], self::unsafeSites("<p><?= \\App\\Lang::t('x') ?></p>"));
        $this->assertSame([], self::unsafeSites("<a title=\"<?= \$this->e(\\App\\Lang::t('x')) ?>\">"));
        $this->assertSame([], self::unsafeSites(
            "<b onclick=\"return confirm(<?= \$this->e(json_encode(\\App\\Lang::t('x'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)\">"
        ));
    }

    public function test_no_template_prints_interface_text_into_an_unsafe_context(): void
    {
        $root = dirname(__DIR__) . '/app';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || !str_contains($path, '/views/') || str_contains($path, '/Tests/')) continue;
            foreach (self::unsafeSites((string) file_get_contents($path)) as $site) {
                $bad[] = substr($path, strlen($root) + 1) . ' => ' . $site;
            }
        }
        $this->assertSame([], $bad, "Unsafe Lang::t print sites:\n" . implode("\n", $bad));
    }
}
