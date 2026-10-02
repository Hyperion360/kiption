<?php // app/Features/Reader/Prefs.php
namespace App\Features\Reader;
use Kip\Http\Request;

/** Typography preferences, one compact cookie ("19-serif-regular-indented-
 *  medium-scroll"). Cookie-only by design: the perusertheme DB row covers
 *  theme; text prefs are device-local (YAGNI on server sync). */
final class Prefs
{
    public const COOKIE = 'reader';
    public const SIZES = [16, 17, 18, 19, 20, 21, 22, 23, 24];
    public const TYPEFACES = ['serif', 'sans'];
    public const SPACINGS = ['snug', 'regular', 'airy'];
    public const PARAGRAPHS = ['indented', 'spaced'];
    public const WIDTHS = ['narrow', 'medium', 'wide'];
    public const MODES = ['scroll', 'pages'];

    public function __construct(
        public readonly int $size = 19,
        public readonly string $typeface = 'serif',
        public readonly string $spacing = 'regular',
        public readonly string $paragraphs = 'indented',
        public readonly string $width = 'medium',
        public readonly string $mode = 'scroll',
    ) {}

    /** @param ?Request $request nullable so the layout can call it before the
     *  envelope task has taught every controller to pass request. */
    public static function current(?Request $request): self
    {
        $raw = $request?->cookies[self::COOKIE] ?? '';
        // PHP parses Cookie: reader[]=x into an array; substr_count would
        // TypeError on it. Non-string or wrong segment count reads as
        // "no cookie": all defaults.
        if (!\is_string($raw) || substr_count($raw, '-') !== 5) { return new self(); }
        return self::fromParts(...explode('-', $raw));
    }

    /** The one whitelist-or-default rule, for the cookie's six segments and
     *  the settings form's six fields alike: each value off its whitelist
     *  falls back to the constructor default. */
    public static function fromParts(string $size, string $typeface, string $spacing, string $paragraphs, string $width, string $mode): self
    {
        $d = new self();
        $pick = static fn (mixed $v, array $allowed, mixed $default): mixed => in_array($v, $allowed, true) ? $v : $default;
        return new self(
            $pick((int) $size, self::SIZES, $d->size),
            $pick($typeface, self::TYPEFACES, $d->typeface),
            $pick($spacing, self::SPACINGS, $d->spacing),
            $pick($paragraphs, self::PARAGRAPHS, $d->paragraphs),
            $pick($width, self::WIDTHS, $d->width),
            $pick($mode, self::MODES, $d->mode),
        );
    }

    public function cookieValue(): string
    {
        return implode('-', [$this->size, $this->typeface, $this->spacing, $this->paragraphs, $this->width, $this->mode]);
    }

    /** Reading-column attributes for <html>; empty string when every pref is
     *  default so cookieless cached pages stay byte-stable. */
    public function dataAttrs(): string
    {
        $d = new self();
        $a = '';
        if ($this->size !== $d->size) { $a .= ' data-size="' . $this->size . '"'; }
        if ($this->typeface !== $d->typeface) { $a .= ' data-typeface="' . $this->typeface . '"'; }
        if ($this->spacing !== $d->spacing) { $a .= ' data-spacing="' . $this->spacing . '"'; }
        if ($this->paragraphs !== $d->paragraphs) { $a .= ' data-paragraphs="' . $this->paragraphs . '"'; }
        if ($this->width !== $d->width) { $a .= ' data-width="' . $this->width . '"'; }
        if ($this->mode !== $d->mode) { $a .= ' data-mode="' . $this->mode . '"'; }
        return $a;
    }
}
