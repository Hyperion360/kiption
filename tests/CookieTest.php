<?php // tests/CookieTest.php
namespace App\Tests;
use App\Cookie;
use PHPUnit\Framework\TestCase;

// The one Set-Cookie directive builder every preference cookie goes through
// (review: twelve hand-built strings across four controllers could drift, and
// none carried Secure on HTTPS deployments). The clear idiom is the empty
// value; the CLI/test SAPI has no HTTPS, so the default shape is the plain
// attribute string the existing pins already assert.
final class CookieTest extends TestCase
{
    public function test_long_shapes_and_the_secure_variant(): void
    {
        $this->assertSame('theme=night; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax', Cookie::long('theme', 'night'));
        $this->assertSame('theme=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', Cookie::long('theme', ''),
            'the empty value is the clear idiom');
        $backup = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        try {
            $this->assertSame('age_ok=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax; Secure',
                Cookie::long('age_ok', '1'),
                'the same derivation index.php uses for the session cookie appends Secure');
        } finally {
            if ($backup === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $backup; }
        }
    }

    /** The client-writable variant the instant-apply reader needs: the long()
     *  directive shape WITHOUT HttpOnly, because browsers refuse to let
     *  document.cookie overwrite an HttpOnly cookie - and prefs.js rewrites
     *  the theme and reader cookies on every control move. */
    public function test_pref_shapes_drop_httponly_only(): void
    {
        $this->assertSame('reader=21-sans-airy-spaced-wide-pages; Max-Age=31536000; Path=/; SameSite=Lax',
            Cookie::pref('reader', '21-sans-airy-spaced-wide-pages'),
            'identical to long() except the HttpOnly leaf');
        $this->assertSame('theme=; Max-Age=0; Path=/; SameSite=Lax', Cookie::pref('theme', ''),
            'the empty value is the same clear idiom');
        $backup = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        try {
            $this->assertSame('theme=night; Max-Age=31536000; Path=/; SameSite=Lax; Secure',
                Cookie::pref('theme', 'night'),
                'the Secure derivation is identical to long()');
        } finally {
            if ($backup === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $backup; }
        }
    }

    /** Defense in depth: every caller whitelists today, but the shape itself
     *  refuses anything that could end the directive or start a header, so a
     *  future caller passing user text cannot inject one (qa-full /pentest). */
    public function test_shape_refuses_header_breaking_names_and_values(): void
    {
        foreach ([['theme', "night\r\nSet-Cookie: x=1"], ['theme', 'night; Domain=evil.example'], ['the me', 'night'], ['theme', 'a,b']] as [$name, $value]) {
            try {
                Cookie::long($name, $value);
                $this->fail("accepted {$name}={$value}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // every value the app mints still passes
        foreach (['night', '19-serif-regular-indented-medium-scroll', '1', 'en', ''] as $ok) {
            $this->assertStringStartsWith('theme=', Cookie::pref('theme', $ok));
        }
    }
}
