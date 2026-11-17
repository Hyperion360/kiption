<?php // tests/Support/Hashes.php
namespace App\Tests\Support;

/** Real password hashes for test fixtures, computed once per process.
 *
 *  Same algorithm and cost as production (PASSWORD_DEFAULT), so login, rehash
 *  and verify behave exactly as they do live. Only the repeat work is skipped:
 *  a fixture user created in every setUp asked bcrypt for the same answer
 *  thousands of times. Tests that need a specific algorithm or cost call
 *  password_hash() directly. */
final class Hashes
{
    /** @var array<string,string> */
    private static array $memo = [];

    public static function of(string $plain): string
    {
        return self::$memo[$plain] ??= password_hash($plain, PASSWORD_DEFAULT);
    }
}
