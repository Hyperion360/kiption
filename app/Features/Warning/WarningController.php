<?php // app/Features/Warning/WarningController.php
namespace App\Features\Warning;
use Kip\Http\{Request, Response};

final class WarningController
{
    public function __construct(private Request $request) {}

    public function accept(): Response
    {
        $to = \App\Redirects::safeReturn($this->request->get['return_to'] ?? '');
        return Response::redirect($to)->withHeader('Set-Cookie', \App\Cookie::long(\App\AgeGate::COOKIE, '1'));
    }
}
