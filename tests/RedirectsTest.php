<?php // tests/RedirectsTest.php
namespace App\Tests;
use App\Redirects;
use PHPUnit\Framework\TestCase;

final class RedirectsTest extends TestCase
{
    /** @dataProvider cases */
    public function test_safe_return(string $in, string $expected): void
    {
        $this->assertSame($expected, Redirects::safeReturn($in));
    }

    public static function cases(): array
    {
        return [
            'relative path kept'        => ['/browse/recent', '/browse/recent'],
            'bare slash kept'           => ['/', '/'],
            'story path kept'           => ['/story/read/the-rabbit-hole/2', '/story/read/the-rabbit-hole/2'],
            'protocol-relative rejected' => ['//evil.example/x', '/'],
            'absolute url rejected'     => ['https://evil.example/x', '/'],
            'scheme-relative rejected'  => ['http://evil.example', '/'],
            'empty rejected'            => ['', '/'],
            'missing slash rejected'    => ['browse', '/'],
            'backslash scheme rejected' => ['/\\evil.example', '/'],
            'header injection rejected' => ["/browse\r\nSet-Cookie: x=1", '/'],
        ];
    }
}
