<?php // app/src/Controllers/WarningController.php
namespace App\Controllers;
use Kip\Http\{Request, Response};

final class WarningController
{
    public function __construct(private Request $request) {}

    public function accept(): Response
    {
        $to = \App\Redirects::safeReturn($this->request->get['return_to'] ?? '');
        return Response::redirect($to)->withHeader(
            'Set-Cookie',
            'age_ok=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'
        );
    }
}
