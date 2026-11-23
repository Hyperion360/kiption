<?php // The story answers that are not essential: notes through gift_to. Shared by the story form and the editor popover; names and order never differ. $skin is 'form' or 'ed' (class names only). ?>
<?php
$ed = ($skin ?? 'form') === 'ed';
$lab = $ed ? ' class="ed-flabel-wrap"' : '';
$txt = static fn (string $text): string => $text;
$field = $ed ? 'ed-input' : '';
$area = $ed ? 'ed-input ed-area' : '';
$cap = static fn (string $text, bool $ed): string => $ed ? '<span class="ed-flabel">' . $text . '</span>' : $text . ' ';
$check = static fn (string $name, string $value, bool $on, string $label, bool $ed): string => '<label class="inline' . ($ed ? ' ed-check' : '') . '"><input type="checkbox" name="' . $name . '" value="' . $value . '"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
$sel = static fn (array $list, $id): bool => in_array((string) $id, array_map('strval', $list), true);
$hintClass = $ed ? 'ed-radio-hint' : 'hint';
$metaClass = $ed ? 'ed-group-label' : 'chapter-meta';
?>
    <label<?= $lab ?>><?= $cap(\App\Lang::t('story.notes'), $ed) ?><textarea<?= $area === '' ? '' : ' class="' . $area . '"' ?> name="notes" rows="2"><?= "\n" . $this->e($values['notes'] ?? '') ?></textarea></label>
    <?php if ($tagGroups !== []): ?>
    <fieldset<?= $ed ? ' class="ed-fieldset"' : '' ?>>
      <legend<?= $ed ? ' class="ed-flabel"' : '' ?>><?= \App\Lang::t('story.tags') ?></legend>
      <?php foreach ($tagGroups as $typeId => $group): ?>
        <p class="<?= $metaClass ?>"><?= $this->e($group['name']) ?></p>
        <?php foreach ($group['tags'] as $tagId => $tagName): ?>
        <?= $check('tags[]', (string) (int) $tagId, $sel($selectedTags, $tagId), $this->e($tagName), $ed) ?>
        <?php endforeach; ?>
        <?php if (($tagPolicy ?? 'off') !== 'off'): ?>
        <label<?= $lab ?>><?= $cap(\App\Lang::t('story.suggest_label'), $ed) ?><input<?= $field === '' ? '' : ' class="' . $field . '"' ?> name="suggest[<?= (int) $typeId ?>]" maxlength="300" value="<?= $this->e($draftSuggest[$typeId] ?? '') ?>"></label>
        <?php endif; ?>
        <?php $pending = $suggestions[$typeId]['pending'] ?? []; $rejected = $suggestions[$typeId]['rejected'] ?? []; ?>
        <?php if ($pending !== [] || $rejected !== []): ?>
        <p class="<?= $metaClass ?>"><?= \App\Lang::t('story.suggest_pending') ?></p>
        <?php foreach ($pending as $s): ?>
        <?= $check('withdraw[]', (string) (int) $s['id'], $sel($draftWithdraw, $s['id']), $this->e($s['name']) . ' (' . \App\Lang::t('story.suggest_withdraw') . ')', $ed) ?>
        <?php endforeach; ?>
        <?php foreach ($rejected as $s): ?>
        <p class="<?= $hintClass ?>"><?= $this->e($s['name']) ?>: <?= \App\Lang::t('story.suggest_rejected') ?></p>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php $hint = ($tagPolicy ?? 'off') === 'free' ? 'story.suggest_hint_free' : 'story.suggest_hint'; ?>
      <p class="<?= $hintClass ?>"><?= \App\Lang::t($hint) ?></p>
    </fieldset>
    <?php endif; ?>
    <?php if (($suggestRefused ?? []) !== []): ?>
    <p class="error"><?= \App\Lang::t('story.suggest_refused', ['names' => $this->e(implode(', ', $suggestRefused))]) ?></p>
    <?php endif; ?>
    <?php if (($characterGroups ?? []) !== []): ?>
    <fieldset<?= $ed ? ' class="ed-fieldset"' : '' ?>>
      <legend<?= $ed ? ' class="ed-flabel"' : '' ?>><?= \App\Lang::t('story.characters') ?></legend>
      <input type="hidden" name="characters_shown" value="1">
      <?php $charCatsOn = \App\Settings::on('categories_on');
      if (!$charCatsOn) { // One flat A-Z list: the category split means nothing when Categories are off.
          $flat = array_replace(...array_column($characterGroups, 'characters'));
          uasort($flat, 'strnatcasecmp');
          $characterGroups = [['name' => '', 'characters' => $flat]];
      } ?>
      <p class="<?= $hintClass ?>"><?= \App\Lang::t($charCatsOn ? 'story.characters_help' : 'story.characters_help_flat') ?></p>
      <?php foreach ($characterGroups as $group): ?>
        <?php if ($charCatsOn): /* With Categories off, a category subhead names something readers never see. */ ?>
        <p class="<?= $metaClass ?>"><?= $group['name'] === '' ? \App\Lang::t('characters.any_category') : $this->e($group['name']) ?></p>
        <?php endif; ?>
        <?php foreach ($group['characters'] as $characterId => $characterName): ?>
        <?= $check('characters[]', (string) (int) $characterId, $sel($selectedCharacters ?? [], $characterId), $this->e($characterName), $ed) ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <?php if ($ed): ?><p class="ed-group-label"><?= \App\Lang::t('ed.group_status') ?></p><?php endif; ?>
    <?= $check('completed', '1', (int) ($values['completed'] ?? 0) === 1, \App\Lang::t('story.completed'), $ed) ?>
    <?= $check('restricted', '1', (int) ($values['restricted'] ?? 0) === 1, \App\Lang::t('story.registered_only'), $ed) ?>
    <?php if ($ed): ?><p class="ed-group-label"><?= \App\Lang::t('ed.group_who') ?></p><?php endif; ?>
    <?php if ($passwordDiscarded): ?><p class="<?= $hintClass ?>"><?= \App\Lang::t('story.password_reenter') ?></p><?php endif; ?>
    <label<?= $lab ?>><?= $cap(\App\Lang::t('story.access_password_label'), $ed) ?><input<?= $field === '' ? '' : ' class="' . $field . '"' ?> type="password" name="access_password" autocomplete="new-password"></label>
    <?= $check('access_clear', '1', !empty($values['access_clear']), \App\Lang::t('story.access_clear_label'), $ed) ?>
    <?php if ($ed): ?><p class="ed-group-label"><?= \App\Lang::t('ed.group_together') ?></p><?php endif; ?>
    <?= $check('round_robin', '1', (int) ($values['round_robin'] ?? 0) === 1, \App\Lang::t('story.round_robin'), $ed) ?>
    <?= $check('is_original', '1', (int) ($values['is_original'] ?? 0) === 1, \App\Lang::t('story.is_original_label'), $ed) ?>
    <p class="<?= $hintClass ?>"><?= \App\Lang::t('story.is_original_help') ?></p>
    <label<?= $lab ?>><?= $cap(\App\Lang::t('story.gift_to_label'), $ed) ?><input<?= $field === '' ? '' : ' class="' . $field . '"' ?> name="gift_to" maxlength="120" value="<?= $this->e($values['gift_to'] ?? '') ?>"></label>
