<?php // app/views/site_theme.php
/* The four-way theme control (Paper, Sepia, Night, Auto): a plain form of
   submit buttons, so it works with scripting off. It posts the theme alone and
   the server keeps typography (ReaderController::settings). The selected
   segment is painted from html[data-theme] in reader.css, never from a server
   class, so a cached page's bytes serve every visitor. Takes request, csrf. */
$themePath = ($request ?? null) !== null ? (string) $request->path : '/';
$themeQuery = ($request ?? null) !== null && $request->get !== [] ? '?' . http_build_query($request->get) : '';
?>
<form class="theme-seg" method="post" action="/reader/settings" aria-label="<?= $this->e(\App\Lang::t('nav.theme_toggle')) ?>">
  <input type="hidden" name="return_to" value="<?= $this->e($themePath . $themeQuery) ?>">
<?php if (!empty($csrf)): ?>
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
<?php endif; ?>
<?php foreach (\App\Theme::VALUES as $themeValue): ?>
  <button type="submit" name="theme" value="<?= $themeValue ?>"><?= \App\Lang::t('theme.' . $themeValue) ?></button>
<?php endforeach; ?>
</form>
