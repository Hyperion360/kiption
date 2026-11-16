<?php // app/src/SuggestionTypeMismatch.php
declare(strict_types=1);
namespace App;

/** A mergeInto() whose chosen target tag lives in another tag type: the
 *  owner decision that the same name in two types is not allowed, and a
 *  suggestion can only fold into a tag of its own type. The controller maps
 *  this to a 422. */
final class SuggestionTypeMismatch extends \RuntimeException
{
}
