<?php
namespace App;
use Kip\Http\{Request, Response};

/** The editor's JSON answers, shared by the story and chapter controllers. */
final class JsonReply
{
    public static function wanted(Request $request): bool
    {
        return str_contains(strtolower((string) $request->header('accept')), 'application/json');
    }

    public static function of(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status,
            $headers + ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
