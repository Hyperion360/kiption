<?php // app/src/Cookie.php
namespace App;

/** Set-Cookie directive builder: the year lifetime and the attribute string
 *  live here once (review: twelve hand-built copies across four controllers
 *  could drift, and none carried Secure on HTTPS deployments, where a
 *  network attacker could set or read the long-lived preference cookies).
 *  Values are whitelisted at every call site; this only shapes the directive.
 *  Secure derives from the same signals public/index.php uses for the
 *  session cookie: $_SERVER['HTTPS'], plus X-Forwarded-Proto only when
 *  KIP_TRUSTED_PROXY opts in (config.php reads the same env). */
final class Cookie
{
    public const YEAR = 31536000;

    /** A one-year HttpOnly cookie; '' $value is the clear idiom (Max-Age=0). */
    public static function long(string $name, string $value): string
    {
        return self::shape($name, $value, true);
    }

    /** A one-year client-writable preference cookie: the long() shape WITHOUT
     *  HttpOnly, so the enhancement layer's document.cookie can update the
     *  same-named cookie the server minted (browsers refuse to overwrite an
     *  HttpOnly one, and the instant-apply reader rewrites these on every
     *  control move). Same clear idiom, same Secure derivation. */
    public static function pref(string $name, string $value): string
    {
        return self::shape($name, $value, false);
    }

    private static function shape(string $name, string $value, bool $httpOnly): string
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((bool) \getenv('KIP_TRUSTED_PROXY') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $directive = $value === ''
            ? $name . '=; Max-Age=0'
            : $name . '=' . $value . '; Max-Age=' . self::YEAR;
        return $directive . '; Path=/; ' . ($httpOnly ? 'HttpOnly; ' : '') . 'SameSite=Lax' . ($secure ? '; Secure' : '');
    }
}
