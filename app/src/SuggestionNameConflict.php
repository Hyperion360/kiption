<?php // app/src/SuggestionNameConflict.php
declare(strict_types=1);
namespace App;

/** A pending name now belongs to another type or canonical tag. The admin
 *  must review that live tag instead of creating a case variant or silently
 *  redirecting a merge. */
final class SuggestionNameConflict extends \RuntimeException
{
}
