<?php
namespace App;

use Kip\{App as KipApp, Http\Request, Session};

/** The view variables the chapter editor page needs, built once for every
 *  caller: the chapter editor (new chapter, edit chapter, draft story) and the
 *  new-story page, which is the same page with an empty chapter. */
final class ChapterEditorView
{
    /** The editor's own status labels. */
    public static function writerLabels(): array
    {
        $labels = [];
        foreach (['text', 'mode', 'source', 'formatted', 'editSelection', 'scene', 'tooLarge', 'unavailable', 'inputFallback', 'unconverted', 'words', 'selection', 'unsaved', 'saved', 'saving', 'focus', 'typewriter', 'compare', 'archive', 'recovery',
            'enable', 'disable', 'retry', 'restore', 'replaceUnsaved', 'useDraft', 'discard', 'copy',
            'recoveryUnavailable', 'recoveryProtected', 'recoveryOff', 'recoveryTab', 'recoveryOffer', 'recoveryOfferDetail', 'recoveryOfferDetailOne', 'recoveryOfferDetailSame',
            'recoveryConflict', 'recoveryDiscarded', 'recoveryRestored', 'recoveryWritten',
            'recoveryClearFailed', 'recoveryRemoveFailed', 'recoveryProtectedRetained', 'copyFallback', 'copied',
            'modeBlocked', 'modeReview', 'convert', 'keepSource'] as $label) {
            $labels[$label] = Lang::t('writer.' . $label);
        }
        return $labels;
    }

    /** @param array $o row (what the page shows), baseRow (what is saved), position (null: no chapter yet),
     *  error, currentRevision, baseRevision, conflict, writerChapter, writerCheckUrl, writerProtected, contents, title
     *  @return array<string, mixed> */
    public static function vars(Request $request, Session $session, KipApp $app, int $uid, array $o): array
    {
        $row = $o['row'];
        $baseRow = $o['baseRow'];
        $error = $o['error'];
        $writerServer = [];
        foreach (['title', 'content', 'notes_before', 'notes_after', 'publish_at'] as $field) {
            $writerServer[$field] = (string) ($baseRow[$field] ?? '');
        }
        $writerServer['publish_at'] = substr($writerServer['publish_at'], 0, 16);
        $head = Seo\Head::make(
            siteName: (string) $app->config('site_name', 'Kiption'),
            ogImage: (string) $app->config('og_image', ''),
            baseUrl: rtrim((string) $app->config('base_url', ''), '/'),
        );
        return [
            'title' => $o['title'],
            'head' => $head->withTitle($o['title'])->withCanonical($request->path)->withNoindex(),
            'theme' => Theme::current($request),
            'request' => $request,
            'navFile' => (string) $app->config('nav_file', ''),
            'path' => $request->path,
            'story' => $row,
            'contents' => $o['contents'],
            'baseRow' => $baseRow,
            'baseRevision' => $o['baseRevision'],
            'currentRevision' => $o['currentRevision'],
            'writerOwner' => $uid,
            'writerChapter' => $o['writerChapter'],
            'writerProtected' => $o['writerProtected'],
            'writerServer' => $writerServer,
            'writerCheckUrl' => $o['writerCheckUrl'],
            'conflict' => $o['conflict'],
            'position' => $o['position'],
            'csrf' => $session->csrfToken(),
            'error' => $error,
            'writerLabels' => self::writerLabels(),
            'imagesOn' => Settings::on('chapter_images_on') && Settings::on('uploads_on'),
            'notice' => $error !== null ? ''
                : match (Saved::message($request)) {
                    'admin.saved_saved' => Lang::t('chapter.saved'),
                    'chapter.saved_draft' => Lang::t('chapter.saved_draft'),
                    default => '',
                },
            'loggedIn' => true,
        ];
    }
}
