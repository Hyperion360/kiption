<?php // app/Features/Story/views/_bookmark_control.php ?>
<?php /* One member bookmark control for one chapter: the header's ghost
       control ($variant 'rt') or the bottom bar's item ('bar'). The read
       page renders it live; each chapter unit renders it again inside its
       inert unit-state template, which the infinite module swaps in when
       that chapter becomes the one being read, so labels and URLs stay
       server-built. Members only: the caller decides. */
     $bmAction = '/reader/' . ($bookmarked ? 'bookmarkremove' : 'bookmarkadd') . '/' . $this->e($story['slug']) . '/' . (int) $position;
     $bmLabel = \App\Lang::t($bookmarked ? 'reader.bookmark_saved' : 'reader.bookmark'); ?>
<?php if ($variant === 'rt'): ?>
        <form method="post" action="<?= $bmAction ?>" class="inline"><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="rt-ctl<?= $bookmarked ? ' is-saved' : '' ?>"><span class="ctl-ribbon" aria-hidden="true"></span><?= $bmLabel ?></button></form>
<?php else: ?>
      <form method="post" action="<?= $bmAction ?>" class="inline bar-bookmark">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"<?= $bookmarked ? ' class="is-saved"' : '' ?> aria-label="<?= $this->e($bmLabel) ?>">
          <span class="bar-top"><svg class="bar-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M7 4h10v16l-5-3.5L7 20Z"/></svg></span>
          <span class="bar-caption"><?= $bmLabel ?></span>
        </button>
      </form>
<?php endif; ?>
