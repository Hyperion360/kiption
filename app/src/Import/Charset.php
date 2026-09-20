<?php // app/src/Import/Charset.php
namespace App\Import;

final class Charset
{
    /** @param string[] $samples */
    public static function detect(array $samples, string $override): string
    {
        if ($override === 'latin1' || $override === 'utf8') return $override;
        $valid = 0;
        foreach ($samples as $s) {
            if (mb_check_encoding((string) $s, 'UTF-8')) $valid++;
        }
        return count($samples) === 0 || $valid / max(1, count($samples)) >= 0.9 ? 'utf8' : 'latin1';
    }

    /** latin1 mode converts wholesale. In utf8 mode already-valid input returns
     *  untouched; invalid bytes are substituted by mb_convert_encoding ('?' on
     *  this build: the substitution COUNT is the contract, not the glyph). */
    public static function toUtf8(string $value, string $mode, Report $r): string
    {
        if ($mode === 'utf8') {
            if (mb_check_encoding($value, 'UTF-8')) return $value;
            $r->substitutions++;
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8'); // substitution char
        }
        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
