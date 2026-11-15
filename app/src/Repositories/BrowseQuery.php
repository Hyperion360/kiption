<?php // app/src/Repositories/BrowseQuery.php
declare(strict_types=1);
namespace App\Repositories;

/** What a reader asked of the filter page, already coerced. Any invalid value
 *  becomes its default and never an error page, like SearchController does.
 *  A word bound of 0 reads as "no bound", so an untouched form changes nothing. */
final class BrowseQuery
{
    public const PER_PAGE = 20;
    public const WORDS_CAP = 10_000_000;
    public const STATUSES = ['any', 'complete', 'wip'];
    public const SORTS = ['updated', 'created', 'words', 'title'];

    public function __construct(
        public readonly string $status,
        public readonly string $language,
        public readonly ?int $wmin,
        public readonly ?int $wmax,
        public readonly string $cat,
        public readonly string $sort,
        public readonly int $page,
        public readonly TagFilter $tags,
    ) {}

    public static function fromQuery(array $q): self
    {
        $status = self::text($q['status'] ?? '');
        $sort = self::text($q['sort'] ?? '');
        $language = self::text($q['language'] ?? '');
        $cat = self::text($q['cat'] ?? '');
        $wmin = self::words($q['wmin'] ?? '');
        $wmax = self::words($q['wmax'] ?? '');
        if ($wmin !== null && $wmax !== null && $wmin > $wmax) {
            [$wmin, $wmax] = [$wmax, $wmin];
        }
        $page = ctype_digit(self::text($q['page'] ?? '')) ? (int) self::text($q['page']) : 1;
        return new self(
            in_array($status, self::STATUSES, true) ? $status : 'any',
            preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $language) === 1 ? $language : '',
            $wmin,
            $wmax,
            preg_match('/^[a-z0-9-]{1,100}$/', $cat) === 1 ? $cat : '',
            in_array($sort, self::SORTS, true) ? $sort : 'updated',
            max(1, min($page, intdiv(PHP_INT_MAX, self::PER_PAGE) - 1)),
            TagFilter::fromQuery($q),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }

    /** True when any filter is on (the sort and page do not count). */
    public function filtered(): bool
    {
        return $this->status !== 'any' || $this->language !== '' || $this->wmin !== null || $this->wmax !== null
            || $this->cat !== '' || !$this->tags->isEmpty();
    }

    /** The query string that restores this exact filter, for the pager links
     *  (the page number is the caller's). Empty values are left out. */
    public function carry(): array
    {
        $out = [];
        if ($this->tags->include) { $out['tag'] = $this->tags->include; }
        if ($this->tags->exclude) { $out['xtag'] = $this->tags->exclude; }
        if ($this->tags->names) { $out['tags'] = implode(',', $this->tags->names); }
        if ($this->status !== 'any') { $out['status'] = $this->status; }
        if ($this->language !== '') { $out['language'] = $this->language; }
        if ($this->wmin !== null) { $out['wmin'] = $this->wmin; }
        if ($this->wmax !== null) { $out['wmax'] = $this->wmax; }
        if ($this->cat !== '') { $out['cat'] = $this->cat; }
        if ($this->sort !== 'updated') { $out['sort'] = $this->sort; }
        return $out;
    }

    private static function text(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private static function words(mixed $v): ?int
    {
        $v = self::text($v);
        if ($v === '' || !ctype_digit($v)) {
            return null;
        }
        $n = min((int) $v, self::WORDS_CAP);
        return $n > 0 ? $n : null;
    }
}
