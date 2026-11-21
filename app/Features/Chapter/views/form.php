<?php // app/Features/Chapter/views/form.php: the chapter editor page (Kiption Editor v2). ?>
<?php $this->layout('layout_bare');
$t = static fn (string $key, array $args = []): string => \App\Lang::t($key, $args);
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
$mode = (string) ($mode ?? '');
$newStory = $mode === 'new-story';
$storyMode = $mode !== '';
$slug = (string) $story['slug'];
$isNew = $position === null;
$numbers = array_map(static fn (array $c): int => (int) $c['position'], $contents ?? []);
$number = $isNew ? ($numbers === [] ? 1 : max($numbers) + 1) : (int) $position;
$roman = \App\Features\Reader\Roman::numeral($number);
$publishAt = trim((string) ($story['publish_at'] ?? ''));
$future = $publishAt !== '' && substr($publishAt, 0, 16) > gmdate('Y-m-d\TH:i');
$state = ($isNew || (int) ($story['draft'] ?? 0) === 1) ? 'draft' : ($future ? 'scheduled' : 'published');
$live = $state !== 'draft';
$storyTitleName = $mode === 'draft-story' ? 'story_title' : 'title';
$chapterTitleName = $newStory ? 'chapter_title' : 'title';
$openRow = (string) ($openRow ?? '');
$focusField = (string) ($focusField ?? '');
$failed = ($error ?? null) !== null;
$saved = !$failed && ($notice ?? '') !== '';
$done = $saved && $live && $notice === $t('chapter.saved') ? $state : '';
$words = \App\Markdown::wordCount((string) ($story['content'] ?? ''));
$passwordOn = $writerProtected && !($story['access_clear'] ?? false);
$hasBefore = trim((string) ($story['notes_before'] ?? '')) !== '';
$hasAfter = trim((string) ($story['notes_after'] ?? '')) !== '';
$writerLabels['typewriter'] = $t('ed.typewriter');
$writerLabels['focus'] = $t('ed.focus');
$edLabels = [
    'saved' => $t('ed.status_saved'), 'changes' => $t('ed.status_changes'), 'draft' => $t('ed.status_draft'),
    'words' => $t('ed.words'), 'wordsOf' => $t('ed.words_of'), 'eyebrow' => $t('ed.eyebrow'),
    'stateDraft' => $t('chapter.status_draft'), 'statePublished' => $t('chapter.status_published'),
    'stateScheduled' => $t('chapter.status_scheduled'), 'statePreview' => $t('ed.state_preview'),
    'publish' => $t($storyMode ? 'ed.publish_story' : 'ed.publish_chapter'), 'update' => $t('ed.update'), 'publishNow' => $t('ed.publish_now'),
    'scheduleFor' => $t('ed.schedule_for'), 'saveChanges' => $t('ed.save_changes'), 'notYet' => $t('ed.not_yet'),
    'cancel' => $t('common.cancel'), 'ready' => $t('ed.ready_title'), 'settings' => $t('ed.settings_title'),
    'tz' => $t('ed.tz_hint'), 'preview' => $t('ed.preview'), 'back' => $t('ed.back_to_draft'),
    'show' => $t('ed.password_show'), 'hide' => $t('ed.password_hide'), 'size' => $t('ed.size_px'),
    'addBefore' => $t('ed.add_note_before'), 'editBefore' => $t('ed.edit_note_before'),
    'addAfter' => $t('ed.add_note_after'), 'editAfter' => $t('ed.edit_note_after'),
    'notes' => $t('ed.notes_summary'), 'on' => $t('ed.recovery_on'), 'off' => $t('ed.recovery_off'),
    'whenNow' => $t('ed.when_now'), 'whoAll' => $t('ed.who_everyone'), 'whoPw' => $t('ed.who_password'),
    'copied' => $t('ed.link_copied'), 'focus' => $t('ed.focus_banner'), 'source' => $t('ed.source'),
    'doneLive' => $t('ed.done_live'), 'doneScheduled' => $t('ed.done_scheduled'),
    'doneLiveText' => $t('ed.done_live_text', ['n' => $roman]), 'doneScheduledText' => $t('ed.done_scheduled_text', ['n' => $roman]),
    'none' => $t('ed.none'),
    'themeNames' => ['paper' => $t('theme.paper'), 'sepia' => $t('theme.sepia'), 'night' => $t('theme.night'), 'auto' => $t('theme.auto')], 'themeLabel' => $t('nav.theme_toggle'),
];
$check = static fn (string $id, string $label, bool $on, string $hint = ''): string => '<button type="button" role="radio" class="ed-radio" id="' . $id . '" aria-checked="' . ($on ? 'true' : 'false') . '">'
    . '<span class="ed-radio-dot" aria-hidden="true"></span><span class="ed-radio-text"><span class="ed-radio-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
    . ($hint === '' ? '' : '<span class="ed-radio-hint">' . htmlspecialchars($hint, ENT_QUOTES) . '</span>') . '</span></button>';
$swatch = static fn (string $v): string => '<button type="button" role="radio" class="ed-swatch" data-theme-value="' . $v . '" aria-checked="false"><span class="ed-swatch-chip ed-swatch-' . $v . '" aria-hidden="true">' . htmlspecialchars(\App\Lang::t('ed.swatch_sample'), ENT_QUOTES) . '</span><span class="ed-swatch-name">' . htmlspecialchars(\App\Lang::t('theme.' . $v), ENT_QUOTES) . '</span></button>';
$seg = static function (string $key, string $title, string $note, array $opts): string {
    $q = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES);
    $out = '<div class="ed-field"><div class="ed-field-head"><span class="ed-cap">' . $q($title) . '</span>'
        . ($note !== '' ? '<span class="ed-note-text">' . $q($note) . '</span>' : '')
        . '</div><div class="ed-seg" role="radiogroup" aria-label="' . $q($title) . '">';
    foreach ($opts as $value => $label) {
        $out .= '<button type="button" role="radio" class="ed-seg-btn" data-setting="' . $key . '" data-value="' . $value . '" aria-checked="false">' . $q($label) . '</button>';
    }
    return $out . '</div></div>';
};
?>
<form data-content-required="<?= $this->e($t('chapter.content_required')) ?>" id="chapter-editor-form" class="ed" data-js-module="writer-status draft-form writer-comfort ed-conflict" data-writer-labels="<?= $this->e(json_encode($writerLabels, JSON_THROW_ON_ERROR)) ?>"
  data-ed-labels="<?= $this->e(json_encode($edLabels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>"
  data-ed-state="<?= $this->e($state) ?>" data-ed-number="<?= $this->e($roman) ?>" data-ed-done="<?= $this->e($done) ?>"
  data-ed-link="<?= $isNew ? '' : $this->e('/story/read/' . $slug . '/' . $number) ?>"
  data-writer-owner="<?= $this->e((string) $writerOwner) ?>"
  data-writer-chapter="<?= $this->e($writerChapter) ?>"
  data-writer-base="<?= $this->e($currentRevision) ?>"
  data-writer-protected="<?= $writerProtected ? '1' : '0' ?>"
<?php if ($newStory) {
    $writerFields = ['chapter_title', 'content', 'notes_before', 'notes_after', 'publish_at', 'title', 'summary', 'notes'];
    $writerServer = array_fill_keys($writerFields, '');
} ?>
<?php if ($storyMode): ?>
  data-ed-mode="<?= $this->e($mode) ?>" data-ed-title-field="<?= $this->e($chapterTitleName) ?>" data-ed-open="<?= $this->e($openRow) ?>" data-ed-focus="<?= $this->e($focusField) ?>"
<?php endif; ?>
<?php if ($newStory): ?>
  data-writer-fields="<?= $this->e(json_encode($writerFields, JSON_THROW_ON_ERROR)) ?>"
<?php endif; ?>
  data-writer-server="<?= $this->e(json_encode($writerServer, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"
  data-writer-check-url="<?= $this->e($writerCheckUrl) ?>"
  data-writer-saved="<?= $saved ? '1' : '0' ?>"
  data-writer-rejected="<?= $failed ? '1' : '0' ?>" method="post" action="<?= $newStory ? '/story/create' : ($isNew
    ? '/chapter/create/' . $this->e($slug)
    : '/chapter/update/' . $this->e($slug) . '/' . (int) $position) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
<?php if (!$isNew): ?>
  <input type="hidden" name="base_revision" value="<?= $this->e($baseRevision) ?>">
<?php endif; ?>

  <header class="ed-head">
    <div class="ed-bar">
      <a class="ed-brand" href="/"><?= $this->e($t('ed.wordmark')) ?></a>
      <span class="ed-divider" aria-hidden="true"></span>
      <div class="ed-crumb">
<?php if ($newStory): ?>
        <span class="ed-story" data-ed-story-echo><?= $this->e($t('ed.new_story_crumb')) ?></span>
<?php else: ?>
        <a class="ed-story" href="/story/edit/<?= $this->e($slug) ?>" title="<?= $this->e($t('ed.story_link')) ?>"><?= $this->e($story['story_title']) ?></a>
<?php endif; ?>
<?php if ($newStory): ?>
        <span class="ed-chapter-name"><?= $this->e($roman) ?><span data-ed-title-sep> · </span><span data-ed-title-echo data-ed-title-empty="<?= $this->e($t('ed.untitled_chapter')) ?>"><?= $this->e(($story['title'] ?? '') !== '' ? $story['title'] : $t('ed.untitled_chapter')) ?></span></span>
<?php else: ?>
        <span class="ed-chapter-name"><?= $this->e($roman) ?> · <span data-ed-title-echo><?= $this->e($story['title'] ?? '') ?></span></span>
<?php endif; ?>
      </div>
      <div class="ed-actions">
        <span class="ed-status" role="status" aria-live="polite" aria-atomic="true"><span class="ed-dot" aria-hidden="true"></span><span data-writer-state><?= $this->e($failed ? $writerLabels['unsaved'] : ($live ? $edLabels['saved'] : $edLabels['draft'])) ?></span><span class="visually-hidden" data-ed-live></span></span>
<?php if (!$newStory): ?>
        <button type="button" class="ed-tool js-only" data-ed-panel="contents" aria-pressed="false" aria-controls="ed-contents" aria-label="<?= $this->e($t('ed.contents')) ?>"><span class="ed-icon ed-icon-lines" aria-hidden="true"><i></i><i></i><i></i></span><span class="ed-tool-label"><?= $this->e($t('ed.contents')) ?></span></button>
<?php endif; ?>
        <button type="button" class="ed-tool js-only" data-ed-panel="manuscript" aria-pressed="false" aria-controls="ed-manuscript" aria-label="<?= $this->e($t('ed.manuscript_settings')) ?>"><span class="ed-icon ed-icon-aa" aria-hidden="true">A<small>a</small></span><span class="ed-tool-label"><?= $this->e($t('ed.manuscript')) ?></span></button>
        <button type="button" class="ed-tool js-only" data-ed-theme-toggle aria-label="<?= $this->e($t('nav.theme_toggle')) ?>"><span class="ed-icon ed-icon-theme" aria-hidden="true"></span><span class="ed-tool-label" data-ed-theme-label><?= $this->e($t('theme.paper')) ?></span></button>
        <div class="ed-more js-only">
          <button type="button" class="ed-tool ed-tool-dots" data-ed-more aria-haspopup="menu" aria-expanded="false" aria-controls="ed-menu" aria-label="<?= $this->e($t('ed.more')) ?>"><i></i><i></i><i></i></button>
          <div class="ed-menu" id="ed-menu" role="menu" aria-label="<?= $this->e($t('ed.more')) ?>" hidden>
            <p class="ed-menu-cap"><?= $this->e($t('ed.menu_notes')) ?></p>
            <button type="button" role="menuitem" data-ed-act="note-before"><?= $this->e($t($hasBefore ? 'ed.edit_note_before' : 'ed.add_note_before')) ?></button>
            <button type="button" role="menuitem" data-ed-act="note-after"><?= $this->e($t($hasAfter ? 'ed.edit_note_after' : 'ed.add_note_after')) ?></button>
            <span class="ed-menu-rule" role="separator"></span>
            <button type="button" role="menuitemcheckbox" class="ed-menu-rec" data-ed-act="recovery" aria-checked="false"><span class="ed-menu-rec-text"><span><?= $this->e($t('ed.recovery')) ?></span><small data-ed-rec-hint><?= $this->e($t('ed.recovery_hint')) ?></small></span><span class="ed-menu-rec-state" data-ed-rec-state><?= $this->e($t('ed.recovery_off')) ?></span></button>
            <button type="button" role="menuitem" class="ed-menu-src js-only" data-ed-act="source" aria-keyshortcuts="Control+/ Meta+/"><span><?= $this->e($t('ed.markdown_source')) ?></span><kbd data-ed-combo><?= $this->e($t('ed.key_source_keys')) ?></kbd></button>
            <button type="button" role="menuitem" data-ed-act="help"><?= $this->e($t('ed.formatting_help')) ?></button>
<?php if (!$isNew): ?>
            <span class="ed-menu-rule" role="separator"></span>
            <button type="button" role="menuitem" class="ed-menu-danger" data-ed-act="delete"><?= $this->e($t('chapter.delete')) ?></button>
<?php endif; ?>
            <span class="ed-menu-rule" role="separator"></span>
            <a role="menuitem" class="ed-menu-link" href="#menu" data-ed-act="site-menu"><?= $this->e($t('nav.menu')) ?></a>
          </div>
        </div>
        <span class="ed-divider ed-divider-gap js-only" aria-hidden="true"></span>
        <button type="button" class="ed-btn ed-preview js-only" data-ed-preview aria-pressed="false"><?= $this->e($t('ed.preview')) ?></button>
        <button type="submit" class="ed-btn ed-primary writer-save" data-ed-primary aria-haspopup="dialog"><?= $this->e($t($live ? 'ed.update' : ($storyMode ? 'ed.publish_story' : 'ed.publish_chapter'))) ?></button>
      </div>
    </div>
  </header>

  <div class="ed-focus-bar js-only" data-ed-focus-bar><span><span class="ed-brand-small"><?= $this->e($t('ed.wordmark')) ?></span> · <?= $this->e($newStory ? $t('ed.new_story_crumb') : $story['story_title']) ?> · <?= $this->e($roman) ?> · <span data-ed-title-echo data-ed-title-empty="<?= $this->e($t('ed.untitled_chapter')) ?>"><?= $this->e(($story['title'] ?? '') !== '' ? $story['title'] : $t('ed.untitled_chapter')) ?></span></span><span data-ed-focus-words aria-hidden="true"></span></div>
  <p class="ed-focus-hint js-only" aria-hidden="true"><?= $this->e($t('ed.focus_mode')) ?> · <kbd><?= $this->e($t('ed.focus_key')) ?></kbd> <?= $this->e($t('ed.focus_to_exit')) ?></p>

  <div class="ed-body">
<?php if (!$newStory): ?>
    <nav class="ed-panel ed-contents js-only" id="ed-contents" aria-label="<?= $this->e($t('ed.chapters')) ?>" hidden>
      <div class="ed-panel-head"><p class="ed-cap"><?= $this->e($t('ed.chapters')) ?></p><button type="button" class="ed-link-btn ed-panel-close-phone" data-ed-panel-close="contents"><?= $this->e($t('ed.close')) ?></button></div>
      <ol class="ed-rows">
<?php foreach ($contents as $c): ?>
        <li><a class="ed-row-link" href="/chapter/edit/<?= $this->e($slug) ?>/<?= (int) $c['position'] ?>"<?= $c['current'] ? ' aria-current="page"' : '' ?>><span class="ed-row-n"><?= $this->e(\App\Features\Reader\Roman::numeral((int) $c['position'])) ?></span><span class="ed-row-text"><span class="ed-row-title"><?= $this->e($c['title']) ?></span><span class="ed-row-meta"><?= $this->e($t('ed.row_meta', ['status' => $c['status'], 'words' => $t('ed.row_words', ['n' => number_format((int) $c['words'])])])) ?></span></span></a></li>
<?php endforeach; ?>
<?php if ($isNew): ?>
        <li><span class="ed-row-link" aria-current="page"><span class="ed-row-n"><?= $this->e($roman) ?></span><span class="ed-row-text"><span class="ed-row-title" data-ed-title-echo><?= $this->e($story['title'] ?? '') ?></span><span class="ed-row-meta"><?= $this->e($t('chapter.status_draft')) ?></span></span></span></li>
<?php endif; ?>
      </ol>
      <a class="ed-outline-btn" href="/chapter/new/<?= $this->e($slug) ?>"><span aria-hidden="true">+</span> <?= $this->e($t('ed.new_chapter')) ?></a>
    </nav>
<?php endif; ?>

    <div class="ed-main">
      <div class="ed-col" data-ed-col<?= ($conflict ?? false) ? ' data-ed-conflicted' : '' ?>>
<?php if ($failed && !($conflict ?? false)): ?>
        <div class="ed-banner ed-banner-error" role="alert"><span class="ed-banner-mark" aria-hidden="true"></span><div class="ed-banner-body"><strong class="ed-banner-title"><?= $this->e($t($newStory ? 'ed.error_title_story' : 'ed.error_title')) ?></strong><p><?= $this->e($error) ?><?php if ($focusField !== ''): ?> <a class="nojs-only" href="#<?= $this->e($focusField) ?>"><?= $this->e($t('ed.go_to_field')) ?></a><?php endif; ?></p><div class="ed-banner-actions js-only"><?php if ($error === $t('chapter.publish_invalid')): ?><button type="button" class="ed-btn-sm ed-btn-dark" data-ed-act="fix-schedule"><?= $this->e($t('ed.fix_schedule')) ?></button><?php endif; ?><button type="button" class="ed-btn-sm" data-ed-dismiss><?= $this->e($t('ed.dismiss')) ?></button></div></div></div>
<?php endif; ?>
<?php if ($saved): ?>
        <div class="ed-banner ed-banner-notice" role="status"><span class="ed-banner-mark" aria-hidden="true"></span><div class="ed-banner-body"><p><?= $this->e($notice) ?></p></div></div>
<?php endif; ?>
        <div class="ed-recovery-slot" data-writer-recovery-slot></div>
<?php if ($conflict ?? false):
    // Both versions are already in hand (the writer's post and the archive row the 409 read), so the marks cost no query.
    $mine = $story; $theirs = $baseRow;
    $val = static fn (array $row, string $f): string => $f === 'publish_at'
        ? substr(str_replace('T', ' ', (string) ($row[$f] ?? '')), 0, 16) : (string) ($row[$f] ?? '');
    $mineBlocks = \App\LineDiff::blocks($val($mine, 'content'));
    $theirBlocks = \App\LineDiff::blocks($val($theirs, 'content'));
    [$mineFlags, $theirFlags] = \App\LineDiff::compare($mineBlocks, $theirBlocks);
    $proseDiffers = in_array(true, $mineFlags, true) || in_array(true, $theirFlags, true);
    $mark = static function (string $html, bool $differs) use ($t): string {
        if (!$differs) return $html;
        $cue = '<span class="visually-hidden">' . htmlspecialchars($t('ed.conflict_differs'), ENT_QUOTES) . '</span>';
        $html = rtrim($html);
        return preg_match('#^<p>((?:(?!</?p>).)*)</p>$#s', $html, $m) === 1
            ? '<p><mark class="ed-mark">' . $cue . $m[1] . '</mark></p>'
            : '<div class="ed-mark ed-mark-block">' . $cue . $html . '</div>';
    };
    $markText = static fn (string $text, bool $differs): string => $differs
        ? '<mark class="ed-mark"><span class="visually-hidden">' . htmlspecialchars($t('ed.conflict_differs'), ENT_QUOTES) . '</span>' . htmlspecialchars($text, ENT_QUOTES) . '</mark>'
        : htmlspecialchars($text, ENT_QUOTES);
    $cards = [
        ['label' => 'chapter.your_text', 'meta' => 'ed.conflict_mine_meta', 'row' => $mine, 'blocks' => $mineBlocks, 'flags' => $mineFlags, 'copy' => 'mine', 'note' => 'ed.conflict_replace_note'],
        ['label' => 'chapter.archive_version', 'meta' => 'ed.conflict_theirs_meta', 'row' => $theirs, 'blocks' => $theirBlocks, 'flags' => $theirFlags, 'copy' => 'theirs', 'note' => 'ed.conflict_archive_note'],
    ];
    $otherOf = ['mine' => $theirs, 'theirs' => $mine];
    $conflictLabels = ['copied' => $t('ed.conflict_copied'), 'copyFailed' => $t('ed.conflict_copy_failed'), 'usedArchive' => $t('ed.conflict_used_archive')];
?>
        <section class="ed-conflict" aria-labelledby="chapter-conflict-heading" data-ed-conflict data-ed-current="<?= $this->e($currentRevision) ?>" data-ed-conflict-labels="<?= $this->e(json_encode($conflictLabels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
<?php if ((int) ($story['is_draft'] ?? 0) === 1): ?>
          <input type="hidden" name="draft" value="1">
<?php endif; ?>
          <div class="ed-conflict-head">
            <p class="ed-eyebrow"><?= $this->e($t('ed.eyebrow', ['n' => $roman, 'state' => $t('ed.conflict_eyebrow')])) ?></p>
            <h2 id="chapter-conflict-heading"><?= $this->e($t('ed.conflict_title')) ?></h2>
            <p class="ed-lede"><?= $this->e($t('ed.conflict_lede')) ?><?= $proseDiffers ? '' : ' ' . $this->e($t('ed.conflict_text_same')) ?></p>
          </div>
          <div class="ed-versions" role="group" aria-label="<?= $this->e($t('ed.conflict_compare')) ?>">
<?php foreach ($cards as $card):
    $diffCount = count(array_filter($card['flags']));
    $cardWords = \App\Markdown::wordCount($val($card['row'], 'content'));
?>
            <div class="ed-version" data-ed-version="<?= $card['copy'] ?>">
<?php
    $savedAt = $card['copy'] === 'theirs' ? (string) $val($card['row'], 'saved_at') : '';
    $savedTs = $savedAt === '' ? false : strtotime($savedAt);
    $when = $savedTs === false ? '' : ($savedTs >= strtotime('today') ? $t('ed.conflict_at', ['time' => \App\Dates::time($savedAt)]) : \App\Dates::format($savedAt, false, true));
    $meta = trim(preg_replace('/\s+/', ' ', $t($card['meta'], ['words' => $t('ed.row_words', ['n' => number_format($cardWords)]), 'when' => '{when}'])));
    [$metaPre, $metaPost] = array_pad(explode('{when}', $meta, 2), 2, '');
?>
              <div class="ed-version-head"><strong><?= $this->e($t($card['label'])) ?></strong><span><?= $this->e(rtrim($metaPre)) ?><?php if ($when !== ''): ?> <time datetime="<?= $this->e(gmdate('c', (int) $savedTs)) ?>"><?= $this->e($when) ?></time><?php endif; ?><?= $this->e($metaPost) ?></span></div>
<?php if ($val($mine, 'title') !== '' || $val($theirs, 'title') !== ''): $v = $val($card['row'], 'title'); ?>
              <div class="ed-cmp ed-cmp-title"><span class="ed-cap"><?= $this->e($t('chapter.title')) ?></span>
                <p class="ed-cmp-value"><?= $v === '' ? '<em>' . $this->e($t('ed.conflict_empty')) . '</em>' : $markText($v, $v !== $val($otherOf[$card['copy']], 'title')) ?></p>
              </div>
<?php endif; ?>
              <div class="ed-cmp">
<?php if ($diffCount > 0): ?>
                <p class="ed-diff-count"><?= $this->e($t($diffCount === 1 ? 'ed.conflict_diff_one' : 'ed.conflict_diff_count', ['n' => $diffCount, 'total' => count($card['blocks'])])) ?></p>
<?php endif; ?>
                <div class="ed-prose">
<?php foreach ($card['blocks'] as $i => $block): ?>
                  <?= $mark(\App\Markdown::render($block), $card['flags'][$i]) ?>

<?php endforeach; ?>
                </div>
              </div>
<?php foreach (['notes_before' => 'chapter.note_before', 'notes_after' => 'chapter.note_after', 'publish_at' => 'chapter.publish_at'] as $field => $labelKey):
    $v = $val($card['row'], $field); $other = $val($otherOf[$card['copy']], $field);
    if ($v === '' && $other === '') continue;
    $differs = $v !== $other;
?>
              <div class="ed-cmp ed-cmp-field"><span class="ed-cap"><?= $this->e($t($labelKey)) ?></span>
<?php if ($v === ''): ?>
                <p class="ed-cmp-value"><em><?= $this->e($t('ed.conflict_empty')) ?></em><?= $differs ? '<span class="visually-hidden"> ' . $this->e($t('ed.conflict_differs')) . '</span>' : '' ?></p>
<?php elseif ($field === 'publish_at'): ?>
                <p class="ed-cmp-value"><?= $markText($v, $differs) ?></p>
<?php else: ?>
                <div class="ed-prose ed-prose-note"><?= $mark(\App\Markdown::render($v), $differs) ?></div>
<?php endif; ?>
              </div>
<?php endforeach; ?>
              <div class="ed-version-actions">
<?php if ($card['copy'] === 'mine'): ?>
                <button class="ed-btn ed-primary ed-conflict-btn" type="submit" name="accept_revision" value="<?= $this->e($currentRevision) ?>"><?= $this->e($t('chapter.replace_shown')) ?></button>
<?php else: ?>
                <a class="ed-btn ed-conflict-btn" href="/chapter/edit/<?= $this->e($slug) ?>/<?= (int) $position ?>" data-ed-use-archive><?= $this->e($t('chapter.use_archive')) ?></a>
<?php endif; ?>
                <button type="button" class="ed-link-btn ed-conflict-copy js-only" data-ed-conflict-copy="<?= $card['copy'] ?>"><?= $this->e($t('ed.conflict_copy_' . $card['copy'])) ?></button>
                <p class="ed-version-note"><?= $this->e($t($card['note'])) ?></p>
              </div>
            </div>
<?php endforeach; ?>
          </div>
        </section>
<?php endif; ?>

        <section class="ed-note" data-ed-note="before"<?= $hasBefore ? ' data-shown' : '' ?>>
          <div class="ed-note-head"><label class="ed-cap" for="ed-notes-before"><?= $this->e($t('chapter.note_before')) ?></label><button type="button" class="ed-link-btn js-only" data-ed-remove-note="before"><?= $this->e($t('ed.remove_note')) ?></button></div>
          <textarea id="ed-notes-before" name="notes_before" rows="2" placeholder="<?= $this->e($t('ed.note_before_ph')) ?>"><?= $this->e($story['notes_before'] ?? '') ?></textarea>
        </section>

        <div class="ed-title-block">
          <p class="ed-eyebrow" data-ed-eyebrow><?= $this->e($t('ed.eyebrow', ['n' => $roman, 'state' => $t('chapter.status_' . $state)])) ?></p>
          <label class="ed-title-wrap"><span class="visually-hidden"><?= $this->e($t('chapter.title')) ?></span>
            <input class="ed-title" name="<?= $chapterTitleName ?>" maxlength="200" placeholder="<?= $this->e($t('ed.title_placeholder')) ?>" value="<?= $this->e($story['title'] ?? '') ?>"></label>
          <span class="ed-rule" aria-hidden="true"></span>
        </div>

        <div class="ed-write" data-ed-write>
          <label class="writer-body"><span class="visually-hidden"><?= $this->e($t('chapter.text')) ?></span> <textarea name="content" id="ed-f-content" rows="18" required autocomplete="off" aria-describedby="writer-count" data-js-module="paste epigraph-boot"<?php if ($imagesOn) : ?> data-epigraph-upload-url="/authorimages/upload" data-epigraph-csrf="<?= $this->e($csrf) ?>"<?php endif; ?> data-writer-labels="<?= $this->e(json_encode($writerLabels, JSON_THROW_ON_ERROR)) ?>" data-editor-labels="<?= $this->e(json_encode([
              'bold' => $t('chapter.editor_bold'),
              'italic' => $t('chapter.editor_italic'),
              'link' => $t('chapter.editor_link'),
              'quote' => $t('chapter.editor_quote'),
              'break' => $t('chapter.editor_break'),
          ], JSON_UNESCAPED_UNICODE)) ?>"><?= $this->e(\App\Markdown::normalizeSceneBreaks((string) ($story['content'] ?? ''))) ?></textarea></label>
        </div>

        <section class="ed-note ed-note-after" data-ed-note="after"<?= $hasAfter ? ' data-shown' : '' ?>>
          <div class="ed-note-head"><label class="ed-cap" for="ed-notes-after"><?= $this->e($t('chapter.note_after')) ?></label><button type="button" class="ed-link-btn js-only" data-ed-remove-note="after"><?= $this->e($t('ed.remove_note')) ?></button></div>
          <textarea id="ed-notes-after" name="notes_after" rows="2" placeholder="<?= $this->e($t('ed.note_after_ph')) ?>"><?= $this->e($story['notes_after'] ?? '') ?></textarea>
        </section>
      </div>
    </div>

    <aside class="ed-panel ed-manuscript js-only" id="ed-manuscript" aria-label="<?= $this->e($t('ed.manuscript_settings')) ?>" hidden>
      <div class="ed-panel-head"><h2><?= $this->e($t('ed.manuscript')) ?></h2><button type="button" class="ed-link-btn" data-ed-panel-close="manuscript"><?= $this->e($t('ed.close')) ?></button></div>
      <div class="ed-field"><span class="ed-cap"><?= $this->e($t('ed.theme')) ?></span>
        <div class="ed-swatches" role="radiogroup" aria-label="<?= $this->e($t('ed.theme')) ?>"><?= $swatch('paper') . $swatch('sepia') . $swatch('night') . $swatch('auto') ?></div>
      </div>
      <div class="ed-field"><div class="ed-field-head"><span class="ed-cap"><?= $this->e($t('ed.scene_break')) ?></span></div>
        <div class="ed-seg ed-seg-break" role="radiogroup" aria-label="<?= $this->e($t('ed.scene_break')) ?>">
          <button type="button" role="radio" class="ed-seg-btn" data-setting="brk" data-value="squares" aria-checked="false" aria-label="<?= $this->e($t('ed.break_squares')) ?>"><span class="ed-glyph" aria-hidden="true">▪ ▪ ▪</span><span><?= $this->e($t('ed.break_squares')) ?></span></button>
          <button type="button" role="radio" class="ed-seg-btn" data-setting="brk" data-value="asterism" aria-checked="false" aria-label="<?= $this->e($t('ed.break_asterism')) ?>"><span class="ed-glyph" aria-hidden="true">⁂</span><span><?= $this->e($t('ed.break_asterism')) ?></span></button>
          <button type="button" role="radio" class="ed-seg-btn" data-setting="brk" data-value="asterisks" aria-checked="false" aria-label="<?= $this->e($t('ed.break_asterisks')) ?>"><span class="ed-glyph" aria-hidden="true">* * *</span><span><?= $this->e($t('ed.break_asterisks')) ?></span></button>
        </div>
      </div>
      <?= $seg('para', $t('ed.paragraphs'), $t('ed.paragraphs_note'), ['indented' => $t('ed.para_indented'), 'spaced' => $t('ed.para_spaced')]) ?>
      <?= $seg('face', $t('ed.typeface'), '', ['serif' => $t('ed.face_serif'), 'sans' => $t('ed.face_sans'), 'mono' => $t('ed.face_mono')]) ?>
      <?= $seg('width', $t('ed.line_width'), '', ['narrow' => $t('ed.width_narrow'), 'medium' => $t('ed.width_medium'), 'wide' => $t('ed.width_wide')]) ?>
      <?= $seg('smart', $t('ed.smart_punctuation'), $t('ed.smart_punctuation_note'), ['true' => $t('ed.smart_on'), 'false' => $t('ed.smart_off')]) ?>
      <div class="ed-field"><div class="ed-field-head"><span class="ed-cap"><?= $this->e($t('ed.text_size')) ?></span><span class="ed-size-label" data-ed-size-label><?= $this->e($t('ed.size_px', ['n' => 20])) ?></span></div>
        <div class="ed-size"><button type="button" data-ed-size="-1" aria-label="<?= $this->e($t('ed.smaller')) ?>" class="ed-size-down">A</button><span class="ed-size-sample"><?= $this->e($t('ed.size_sample')) ?></span><button type="button" data-ed-size="1" aria-label="<?= $this->e($t('ed.larger')) ?>" class="ed-size-up">A</button></div>
      </div>
      <div class="ed-keys" id="ed-keys">
        <span class="ed-cap"><?= $this->e($t('ed.markdown_title')) ?></span>
<?php foreach ([['ed.key_scene', '***'], ['ed.key_italic', '*word*'], ['ed.key_bold', '**word**'], ['ed.key_link', $t('ed.key_link_keys')], ['ed.key_quote', '> '], ['ed.key_sub', '## '], ['ed.key_source', $t('ed.key_source_keys')], ['ed.key_focus', $t('ed.key_focus_keys')]] as [$what, $key]): ?>
        <div class="ed-key"><span><?= $this->e($t($what)) ?></span><kbd><?= $this->e($key) ?></kbd></div>
<?php endforeach; ?>
        <p class="ed-hint"><?= $this->e($t('chapter.markdown_help')) ?></p>
        <p class="ed-hint"><?= $this->e($t('ed.editor_hint')) ?></p>
        <p class="ed-hint"><?= $this->e($t('chapter.source_shortcut')) ?></p>
        <p class="ed-hint"><?= $this->e($t('chapter.paste_hint')) ?></p>
      </div>
    </aside>
  </div>

  <footer class="ed-foot">
    <div class="ed-hints js-only" aria-hidden="true">
      <span><kbd>***</kbd><?= $this->e($t('ed.hint_scene')) ?></span>
      <span><kbd>/</kbd><?= $this->e($t('ed.hint_blocks')) ?></span>
      <span><kbd><?= $this->e($t('ed.hint_italic')) ?></kbd><kbd><?= $this->e($t('ed.hint_bold')) ?></kbd></span>
    </div>
    <div class="ed-count">
      <output id="writer-count" data-writer-count aria-live="off"><?= $this->e($t('writer.words', ['n' => $words])) ?></output>
      <button type="button" data-writer-count-selection hidden><?= $this->e($t('writer.count_selection')) ?></button>
      <span data-writer-selection role="status" aria-live="polite" aria-atomic="true"></span>
    </div>
    <div class="ed-tools writer-tools" data-writer-tools></div>
  </footer>

  <div class="ed-scrim js-only" data-ed-scrim hidden></div>

  <section class="ed-pub<?= $storyMode ? ' ed-pub-story' : '' ?>" id="ed-pub" aria-labelledby="ed-pub-title" data-ed-pub>
    <div class="ed-pub-form" data-ed-pub-form>
      <div class="ed-pub-head">
<?php if ($storyMode): ?>
        <button type="button" class="ed-pub-close js-only" data-ed-cancel aria-label="<?= $this->e($t('ed.close')) ?>"><span aria-hidden="true">×</span></button>
<?php endif; ?>
        <p class="ed-cap" data-ed-pub-kicker><?= $this->e($t($newStory ? 'ed.new_story_kicker' : 'ed.publish_kicker', ['n' => $roman, 'words' => $t('ed.row_words', ['n' => number_format($words)])])) ?></p>
        <h2 id="ed-pub-title" data-ed-pub-title><?= $this->e($t($live ? 'ed.settings_title' : 'ed.ready_title')) ?></h2>
      </div>
      <div class="ed-rows-pub">
<?php if ($storyMode):
    $chosenRating = (int) ($values['rating_id'] ?? 0);
    $chosenLanguage = (string) ($values['language'] ?? \App\Languages::DEFAULT);
    $languageOptions = \App\Languages::options($chosenLanguage);
    $ratingLabel = '';
    foreach ($ratings as $r) { if ((int) $r['a'] === $chosenRating) $ratingLabel = (string) $r['b']; }
    $languageLabel = $chosenLanguage === '' ? $t('story.language_unset') : (string) ($languageOptions[$chosenLanguage] ?? $chosenLanguage);
    $chosenCategories = array_map('strval', $selectedCategories);
    $categoryNames = [];
    foreach ($categories as $c) { if (in_array((string) $c['a'], $chosenCategories, true)) $categoryNames[] = (string) $c['b']; }
    $storyTitleValue = (string) ($values['title'] ?? '');
    $rowOpen = static fn (string $name): string => $name === $openRow ? ' data-open' : '';
    $rowHead = static fn (string $key, string $label, string $value, string $valueAttr): string => '<button type="button" class="ed-prow-head js-only" aria-expanded="false" aria-controls="ed-row-' . $key . '"><span class="ed-prow-name">' . htmlspecialchars($label, ENT_QUOTES) . '</span><span class="ed-prow-value" ' . $valueAttr . '>' . htmlspecialchars($value, ENT_QUOTES) . '</span><span class="ed-chev" aria-hidden="true"></span></button>';
?>
        <div class="ed-prow" data-ed-row="story">
          <?= $rowHead('story', $t('ed.row_story'), $storyTitleValue !== '' ? $storyTitleValue : $t('ed.untitled_value'), 'data-ed-value="story" data-ed-empty="' . $this->e($t('ed.untitled_value')) . '"') ?>
          <div class="ed-prow-body" id="ed-row-story">
            <p class="ed-group-label nojs-only"><?= $this->e($t('ed.row_story')) ?></p>
            <label class="ed-flabel" for="ed-f-title"><?= $this->e($t('ed.story_title')) ?></label>
            <input class="ed-input" id="ed-f-title" name="<?= $storyTitleName ?>" maxlength="200" required value="<?= $this->e($storyTitleValue) ?>" data-ed-story-title>
            <p class="ed-radio-hint" id="ed-hint-story"><?= $this->e($t('ed.story_title_hint')) ?></p>
            <p class="ed-row-error" role="alert" id="ed-err-story" data-ed-error="story"<?= $openRow === 'story' ? '' : ' hidden' ?>><?= $this->e($t('ed.need_story')) ?></p>
            <label class="ed-flabel" for="ed-f-summary"><?= $this->e($t('common.summary')) ?></label>
            <textarea class="ed-input ed-area" id="ed-f-summary" name="summary" maxlength="1000" rows="3"><?= "\n" . $this->e($values['summary'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="ed-prow" data-ed-row="rating">
          <?= $rowHead('rating', $t('ed.row_rating'), $ratingLabel !== '' ? $ratingLabel : $t('ed.choose_one'), 'data-ed-value="rating" data-ed-empty="' . $this->e($t('ed.choose_one')) . '"') ?>
          <div class="ed-prow-body" id="ed-row-rating">
            <p class="ed-group-label nojs-only"><?= $this->e($t('ed.row_rating')) ?></p>
            <p class="ed-row-error" role="alert" id="ed-err-rating" data-ed-error="rating" data-need-publish="<?= $this->e($t('ed.need_rating')) ?>" data-need-draft="<?= $this->e($t('story.err_rating_draft')) ?>"<?= $openRow === 'rating' ? '' : ' hidden' ?>><?= $this->e($t('ed.need_rating')) ?></p>
            <fieldset class="ed-radios ed-choices">
              <legend class="visually-hidden"><?= $this->e($t('story.rating')) ?></legend>
<?php foreach ($ratings as $i => $r): ?>
              <label class="ed-radio ed-choice"><input type="radio" name="rating_id" value="<?= (int) $r['a'] ?>"<?= $i === 0 ? ' id="ed-f-rating"' : '' ?> required data-label="<?= $this->e((string) $r['b']) ?>"<?= $chosenRating === (int) $r['a'] ? ' checked' : '' ?>><span class="ed-radio-dot" aria-hidden="true"></span><span class="ed-radio-text"><span class="ed-radio-label"><?= $this->e((string) $r['b']) ?></span><?php if ((int) ($r['e'] ?? 0) === 1 && trim((string) ($r['f'] ?? '')) !== ''): ?><span class="ed-radio-hint"><?= $this->e((string) $r['f']) ?></span><?php endif; ?></span></label>
<?php endforeach; ?>
            </fieldset>
          </div>
        </div>
        <div class="ed-prow" data-ed-row="language">
          <?= $rowHead('language', $t('ed.row_language'), $languageLabel, 'data-ed-value="language"') ?>
          <div class="ed-prow-body" id="ed-row-language">
            <p class="ed-group-label nojs-only"><?= $this->e($t('ed.row_language')) ?></p>
            <label class="ed-flabel" for="ed-f-language"><?= $this->e($t('common.language')) ?></label>
            <span class="ed-select"><select class="ed-input" id="ed-f-language" name="language">
              <option value=""<?= $chosenLanguage === '' ? ' selected' : '' ?>><?= $this->e($t('story.language_unset')) ?></option>
<?php foreach ($languageOptions as $code => $name): ?>
              <option value="<?= $this->e((string) $code) ?>"<?= $chosenLanguage === (string) $code ? ' selected' : '' ?>><?= $this->e((string) $name) ?></option>
<?php endforeach; ?>
            </select></span>
          </div>
        </div>
        <?php if (\App\Settings::on('categories_on')): ?>
        <div class="ed-prow" data-ed-row="categories">
          <?= $rowHead('categories', $t('ed.row_categories'), $categoryNames === [] ? $t('ed.none_chosen') : implode(', ', $categoryNames), 'data-ed-value="categories" data-ed-empty="' . $this->e($t('ed.none_chosen')) . '"') ?>
          <div class="ed-prow-body" id="ed-row-categories">
            <p class="ed-group-label nojs-only"><?= $this->e($t('ed.row_categories')) ?></p>
            <fieldset class="ed-radios ed-choices">
              <legend class="visually-hidden"><?= $this->e($t('story.categories')) ?></legend>
<?php foreach ($categories as $i => $c): ?>
              <label class="ed-radio ed-choice"><input type="checkbox" name="categories[]" value="<?= (int) $c['a'] ?>"<?= $i === 0 ? ' id="ed-f-category"' : '' ?> data-label="<?= $this->e((string) $c['b']) ?>"<?= in_array((string) $c['a'], $chosenCategories, true) ? ' checked' : '' ?>><span class="ed-radio-dot" aria-hidden="true"></span><span class="ed-radio-text"><span class="ed-radio-label"><?= $this->e((string) $c['b']) ?></span></span></label>
<?php endforeach; ?>
            </fieldset>
          </div>
        </div>
        <?php endif; ?>
<?php endif; ?>
        <div class="ed-prow" data-ed-row="when">
          <button type="button" class="ed-prow-head js-only" aria-expanded="false" aria-controls="ed-when"><span class="ed-prow-name"><?= $this->e($t('ed.row_when')) ?></span><span class="ed-prow-value" data-ed-when-value></span><span class="ed-chev" aria-hidden="true"></span></button>
          <div class="ed-prow-body" id="ed-when">
            <div class="ed-radios js-only" role="radiogroup" aria-label="<?= $this->e($t('ed.row_when')) ?>">
              <?= $check('ed-when-now', $t('ed.when_now'), $state !== 'scheduled', $t('ed.when_now_hint')) ?>
              <?= $check('ed-when-later', $t('ed.when_later'), $state === 'scheduled', $t('ed.when_later_hint')) ?>
            </div>
            <div class="ed-when-later js-only" data-ed-later hidden>
              <div class="ed-pair"><input type="date" id="ed-date" aria-label="<?= $this->e($t('ed.date')) ?>"><input type="time" id="ed-time" aria-label="<?= $this->e($t('ed.time')) ?>"></div>
              <p class="ed-warn" role="alert" data-ed-past hidden><?= $this->e($t('ed.past_warning')) ?></p>
              <p class="ed-hint" data-ed-tz></p>
            </div>
            <label class="ed-plain"><?= $this->e($t('ed.nojs_schedule')) ?> <input type="datetime-local" name="publish_at" data-writer-schedule value="<?= $this->e($failed ? $publishAt : substr($publishAt, 0, 16)) ?>"></label>
            <p class="ed-hint nojs-only" data-utc-note><?= $this->e($t('chapter.publish_hint')) ?> <?= $this->e($t('chapter.utc_note')) ?></p>
          </div>
        </div>
<?php if (!$storyMode): ?>
        <div class="ed-prow" data-ed-row="who">
          <button type="button" class="ed-prow-head js-only" aria-expanded="false" aria-controls="ed-who"><span class="ed-prow-name"><?= $this->e($t('ed.row_who')) ?></span><span class="ed-prow-value" data-ed-who-value></span><span class="ed-chev" aria-hidden="true"></span></button>
          <div class="ed-prow-body" id="ed-who">
            <div class="ed-radios js-only" role="radiogroup" aria-label="<?= $this->e($t('ed.row_who')) ?>">
              <?= $check('ed-who-all', $t('ed.who_everyone'), !$passwordOn, $t('ed.who_everyone_hint')) ?>
              <?= $check('ed-who-pw', $t('ed.who_password'), $passwordOn, $t('ed.who_password_hint')) ?>
            </div>
            <div class="ed-who-pw" data-ed-pw-wrap>
              <label class="ed-pw-label nojs-only" for="ed-password"><?= $this->e($t('chapter.access_password_label')) ?></label>
              <div class="ed-pw"><input type="password" name="access_password" id="ed-password" aria-label="<?= $this->e($t('ed.password')) ?>" autocomplete="new-password" placeholder="<?= $this->e($t($writerProtected ? 'ed.password_kept' : 'ed.password_placeholder')) ?>"><button type="button" class="js-only" data-ed-pw-show><?= $this->e($t('ed.password_show')) ?></button></div>
            </div>
<?php if ($writerProtected || ($story['access_clear'] ?? false)): ?>
            <p class="ed-hint js-only" data-ed-clear-note hidden><?= $this->e($t('ed.password_clear_note')) ?></p>
            <label class="ed-plain inline nojs-only"><input type="checkbox" name="access_clear" value="1"<?= ($story['access_clear'] ?? false) ? ' checked' : '' ?>> <?= $this->e($t('chapter.access_clear_label')) ?></label>
<?php endif; ?>
          </div>
        </div>
<?php endif; ?>
        <div class="ed-prow js-only" data-ed-row="notes">
          <button type="button" class="ed-prow-head" aria-expanded="false" aria-controls="ed-notes"><span class="ed-prow-name"><?= $this->e($t('ed.row_notes')) ?></span><span class="ed-prow-value" data-ed-notes-value></span><span class="ed-chev" aria-hidden="true"></span></button>
          <div class="ed-prow-body" id="ed-notes">
            <p class="ed-hint" data-ed-notes-none><?= $this->e($t('ed.notes_none')) ?></p>
            <div class="ed-note-btns"><button type="button" class="ed-btn-sm" data-ed-act="note-before"><?= $this->e($t($hasBefore ? 'ed.edit_note_before' : 'ed.add_note_before')) ?></button><button type="button" class="ed-btn-sm" data-ed-act="note-after"><?= $this->e($t($hasAfter ? 'ed.edit_note_after' : 'ed.add_note_after')) ?></button></div>
          </div>
        </div>
<?php if ($storyMode): ?>
        <details class="ed-prow ed-moreopts" data-ed-row="more"<?= !empty($moreOpen) ? ' open' : '' ?>>
          <summary class="ed-prow-head"><span class="ed-prow-name"><?= $this->e($t('story.more_options')) ?></span><span class="ed-prow-value" data-ed-optional="<?= $this->e($t('ed.optional')) ?>" data-ed-look="<?= $this->e($t('ed.needs_a_look')) ?>"><?= $this->e($t('ed.optional')) ?></span><span class="ed-chev" aria-hidden="true"></span></summary>
          <div class="ed-moreopts-body">
            <p class="ed-radio-hint"><?= $this->e($t('story.more_options_hint')) ?></p>
            <?= $this->render('story/_more_fields', ['skin' => 'ed', 'values' => $values, 'tagGroups' => $tagGroups, 'selectedTags' => $selectedTags,
                'tagPolicy' => $tagPolicy ?? 'off', 'draftSuggest' => $draftSuggest, 'suggestions' => $suggestions, 'draftWithdraw' => $draftWithdraw,
                'suggestRefused' => $suggestRefused ?? [], 'characterGroups' => $characterGroups ?? [], 'selectedCharacters' => $selectedCharacters ?? [],
                'passwordDiscarded' => $passwordDiscarded]) ?>
          </div>
        </details>
<?php endif; ?>
      </div>
      <div class="ed-pub-foot">
        <div class="ed-pub-buttons">
          <button type="submit" class="ed-btn ed-primary" data-ed-go><?= $this->e($t($live ? 'ed.save_changes' : 'ed.publish_now')) ?></button>
          <button type="button" class="ed-btn js-only" data-ed-cancel><?= $this->e($t($live ? 'common.cancel' : 'ed.not_yet')) ?></button>
<?php if (!$live): ?>
          <button type="submit" class="ed-btn" name="draft" value="1" data-ed-draft<?= $storyMode ? ' formnovalidate' : '' ?>><?= $this->e($t('ed.save_draft')) ?></button>
<?php endif; ?>
<?php if (!$isNew): ?>
          <button type="submit" class="ed-btn ed-danger nojs-only" form="chapter-delete-form"><?= $this->e($t('chapter.delete')) ?></button>
<?php endif; ?>
        </div>
<?php if ($storyMode): ?>
        <p class="ed-need js-only" role="status" data-ed-need hidden data-need-story="<?= $this->e($t('ed.need_story')) ?>" data-need-rating="<?= $this->e($t('ed.need_rating')) ?>" data-need-body="<?= $this->e($t('ed.need_body')) ?>"></p>
<?php endif; ?>
        <p class="ed-hint js-only"><?= $this->e($t('ed.change_later')) ?></p>
      </div>
    </div>
    <div class="ed-pub-done js-only" data-ed-pub-done hidden>
      <div class="ed-pub-head"><p class="ed-cap"><?= $this->e($t('ed.done_kicker', ['n' => $roman])) ?></p><h2 data-ed-done-title></h2></div>
      <p class="ed-pub-done-text" data-ed-done-text></p>
      <div class="ed-pub-buttons"><button type="button" class="ed-btn ed-primary" data-ed-copy><?= $this->e($t('ed.copy_link')) ?></button><button type="button" class="ed-btn" data-ed-back><?= $this->e($t('ed.back_to_writing')) ?></button></div>
    </div>
  </section>

<?php if (!$isNew): ?>
  <section class="ed-confirm" aria-labelledby="ed-del-title" aria-describedby="ed-del-body" data-ed-delete hidden>
    <h2 id="ed-del-title"><?= $this->e($t('ed.delete_title', ['n' => $roman])) ?></h2>
    <p id="ed-del-body"><?= $this->e($t('ed.delete_body', ['title' => (string) ($story['title'] ?? ''), 'words' => $t('ed.row_words', ['n' => number_format($words)])])) ?></p>
    <div class="ed-pub-buttons"><button type="button" class="ed-btn" data-ed-keep><?= $this->e($t('ed.delete_keep')) ?></button><button type="submit" class="ed-btn ed-danger" form="chapter-delete-form"><?= $this->e($t('ed.delete_confirm')) ?></button></div>
  </section>
<?php endif; ?>
  <div class="ed-toast" role="status" aria-live="polite" data-ed-toast hidden></div>
</form>
<?php if (!$isNew): ?>
<form id="chapter-delete-form" method="post" action="/chapter/delete/<?= $this->e($slug) ?>/<?= (int) $position ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
</form>
<?php endif; ?>
