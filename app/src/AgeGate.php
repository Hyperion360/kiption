<?php // app/src/AgeGate.php
namespace App;
use Kip\Http\Request;

/** The adult-rating gate, defined once: a story rated adult stays behind the
 *  warning page until the reader has acknowledged it, which sets the age_ok
 *  cookie (WarningController). Story pages, the reader fragment and the
 *  reader's bookmark and progress writes all ask the same question. */
final class AgeGate
{
    public const COOKIE = 'age_ok';

    /** @param array<string,mixed> $story a row carrying the rating's is_adult */
    public static function blocks(array $story, Request $request): bool
    {
        return (int) ($story['is_adult'] ?? 0) === 1 && ($request->cookies[self::COOKIE] ?? null) === null;
    }
}
