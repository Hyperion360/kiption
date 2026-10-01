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
}
