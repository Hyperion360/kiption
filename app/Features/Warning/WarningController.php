<?php // app/Features/Warning/WarningController.php
namespace App\Features\Warning;
use Kip\Http\{Request, Response};
use Kip\Routing\Post;

final class WarningController
{
    public function __construct(private Request $request) {}

    /** The age gate's continue action. POST, not GET: a year-long preference
     *  cookie must not be settable by a third-party page's cross-site
     *  <img> (a GET link would fire without any user gesture on their
     *  page). The gate view posts a same-origin form; the framework's
     *  guest POST gate carries the origin proof. */
    #[Post]
    public function accept(): Response
    {
        $to = \App\Redirects::safeReturn($this->request->postStr('return_to'));
        return Response::redirect($to)->withHeader('Set-Cookie', \App\Cookie::long(\App\AgeGate::COOKIE, '1'));
    }
}
