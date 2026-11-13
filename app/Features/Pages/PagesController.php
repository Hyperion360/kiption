<?php // app/Features/Pages/PagesController.php
namespace App\Features\Pages;
use App\AdminScreen;
use Kip\Http\Response;
use Kip\Routing\Auth as AuthAttr;

/** Admin list of every page, with links to edit, delete and add. */
final class PagesController extends AdminScreen
{
    #[AuthAttr]
    public function manage(): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->page('pages/manage', 'pages.heading', '', [
            'rows' => $this->db->all('SELECT slug, title, updated_at FROM pages ORDER BY slug'),
            'protected' => \App\Features\Page\PageController::PROTECTED,
        ]);
    }
}
