<?php
namespace App;

/** The checks every chapter save shares, whether it arrives from the chapter
 *  editor or from the new-story editor that writes chapter 1 with its story. */
final class ChapterChecks
{
    /** The owner's per-chapter word limits (0 means none): a friendly message with the count, or null when fine. */
    public static function wordLimitError(string $content): ?string
    {
        $min = (int) Settings::get('min_words');
        $max = (int) Settings::get('max_words');
        if ($min === 0 && $max === 0) return null;
        $n = Markdown::wordCount($content);
        if ($min > 0 && $n < $min) return Lang::t('chapter.too_short', ['n' => number_format($n), 'min' => number_format($min)]);
        if ($max > 0 && $n > $max) return Lang::t('chapter.too_long', ['n' => number_format($n), 'max' => number_format($max)]);
        return null;
    }

    /** New image references must come from the author's own library, and none
     *  may be an unresolved editor placeholder. Unchanged ones always pass. */
    public static function imageError(\Kip\Database $db, int $userId, string $stored, string $submitted): ?string
    {
        $key = ChapterImages::refusal($db, $userId, $stored, $submitted, Settings::on('chapter_images_on'));
        return $key === null ? null : Lang::t($key);
    }

    /** A chapter going live (or scheduled to) may not show an image whose file is gone. A draft
     *  keeps any text, so the author can finish it. The message names the first broken address. */
    public static function missingImageError(\Kip\App $app, string $content, bool $draft): ?string
    {
        if ($draft) return null;
        $dir = (string) ($app->config('uploads')['dir'] ?? dirname(__DIR__, 2) . '/public/uploads');
        $gone = ChapterImages::missing($dir, $content);
        return $gone === [] ? null : Lang::t('chapter.err_image_missing', ['src' => $gone[0]]);
    }

    /** The schedule input's normalizer. The zone is OPTIONAL in the accepted
     *  shape because the form's datetime-local input submits without one; a
     *  zone-less value reads as UTC (the third createFromFormat argument pins
     *  that, whatever the host default timezone is). Every accepted value is
     *  canonicalized to Y-m-d\TH:i:s\Z UTC so releaseDue's lexicographic
     *  publish_at <= now compares exact instants: raw offsets compare wrong in
     *  both directions. Empty returns null (the immediate path unchanged);
     *  junk throws for the 422. */
    public static function canonicalPublishAt(string $raw): ?string
    {
        if ($raw === '') return null;
        if (!preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$#', $raw)) {
            throw new \InvalidArgumentException('publish_at');
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw, $utc);
            $errs = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
                return $dt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            }
        }
        throw new \InvalidArgumentException('publish_at'); // shape matched, instant did not (2099-13-45T99:99)
    }
}
