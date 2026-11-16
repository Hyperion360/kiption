<?php
namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/** Read the revision the real edit form offers, including explicit replacement. */
final class ChapterForm
{
    public static function revision(string $body, string $field = 'base_revision'): string
    {
        Assert::assertSame(1, preg_match('/name="' . preg_quote($field, '/') . '" value="([a-f0-9]{64})"/', $body, $match));
        return $match[1];
    }
}
