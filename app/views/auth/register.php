<?php // app/views/auth/register.php ?>
<?php $this->layout('layout'); ?>
<section class="auth-card">
  <h1>Create an account</h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/auth/store">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Penname <input name="penname" required maxlength="30" value="<?= $this->e($penname ?? '') ?>"></label>
    <label>Email <input type="email" name="email" required value="<?= $this->e($email ?? '') ?>"></label>
    <label>Password <input type="password" name="password" required minlength="8"></label>
    <label>Confirm password <input type="password" name="confirm" required minlength="8"></label>
    <?php if (($mode ?? '') === 'invite'): ?>
      <label>Invite code <input name="invite" required value="<?= $this->e($invite ?? '') ?>"></label>
    <?php endif; ?>
    <button type="submit">Register</button>
  </form>
  <p class="chapter-meta">Already have an account? <a href="/auth/login">Log in</a>.</p>
</section>
