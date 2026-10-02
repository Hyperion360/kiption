<?php // tests/RedirectsTest.php
namespace App\Tests;
use App\Redirects;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedirectsTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_safe_return(mixed $in, string $expected): void
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
            // browsers strip tab/newline from a Location URL, so /<TAB>/evil
            // becomes //evil: any control character or space is refused
            'tab protocol-relative rejected' => ["/\t/evil.example", '/'],
            'leading tab rejected'      => ["\t//evil.example", '/'],
            'space rejected'            => ['/ /evil.example', '/'],
            'DEL rejected'              => ["/\x7f/evil.example", '/'],
            'form feed rejected'        => ["/\x0c/evil.example", '/'],
            // ?return_to[]=x arrives as an array: fallback, never a TypeError 500
            'array rejected'            => [['/browse'], '/'],
            'null rejected'             => [null, '/'],
            'encoded path kept'         => ['/search?q=a%20b', '/search?q=a%20b'],
        ];
    }
}
