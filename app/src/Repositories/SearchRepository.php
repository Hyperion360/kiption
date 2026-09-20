<?php // app/src/Repositories/SearchRepository.php
namespace App\Repositories;
use Kip\Database;

final class SearchRepository
{
    private const MAX_TOKENS = 8;
    private ?bool $fts = null;

    public function __construct(private Database $db) {}

    /** Memoized FTS5 probe (the Auth::kindSplit precedent). */
    public function ftsAvailable(): bool
    {
        if ($this->fts === null) {
            try { $this->db->one('SELECT rowid FROM stories_fts LIMIT 1'); $this->fts = true; }
            catch (\PDOException) { $this->fts = false; }
        }
        return $this->fts;
    }

    /** FTS5 MATCH input must never see raw user syntax: split on whitespace,
     *  strip quotes, wrap each token in double quotes (implicit AND), cap at
     *  MAX_TOKENS. Empty result means "do not run a query at all". */
    public static function matchExpression(string $q): string
    {
        $tokens = preg_split('/\s+/', trim($q)) ?: [];
        $tokens = array_values(array_filter(array_map(
            fn (string $t): string => trim(str_replace('"', ' ', $t)),
            $tokens
        ), fn (string $t): bool => $t !== ''));
        $tokens = array_slice($tokens, 0, self::MAX_TOKENS);
        return implode(' ', array_map(fn (string $t): string => '"' . $t . '"', $tokens));
    }

    /** LIKE pattern with %, _, backslash escaped (the fallback path). */
    public static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($q)) . '%';
    }
}
