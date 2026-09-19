<?php // app/src/Controllers/ThemeController.php
namespace App\Controllers;
use Kip\Http\{Request, Response};

final class ThemeController
{
    public function __construct(private Request $request) {}

    public function light(): Response
    {
        return $this->switch('light');
    }

    public function dark(): Response
    {
        return $this->switch('dark');
    }

    private function switch(string $theme): Response
    {
        $to = \App\Redirects::safeReturn($this->request->get['return_to'] ?? '');
        return Response::redirect($to)->withHeader(
            'Set-Cookie',
            \App\Theme::COOKIE . '=' . $theme . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'
        );
    }
}
