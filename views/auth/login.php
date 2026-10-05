<?php
/**
 * @floorplan None — page de connexion (avant l'entrée dans l'application)
 *
 * @var App\Core\View $this
 */
?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('auth.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--bleed<?php $this->stop() ?>

<div class="lt-login-page">
    <?= $this->partial('auth/_hero') ?>

    <section class="lt-login-panel" aria-labelledby="login-title">
        <div class="lt-login-form">
            <h1 id="login-title"><?= e(__('auth.title')) ?></h1>
            <p class="lt-muted"><?= e(__('auth.intro')) ?></p>
            <?php if (($notice ?? null) !== null): ?>
                <p class="lt-alert lt-alert--success" role="status"><?= e($notice) ?></p>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <p class="lt-alert lt-alert--error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($basePath) ?>/login" class="lt-form">
                <?= $csrf->field() ?>
                <div class="lt-field">
                    <label for="email"><?= e(__('auth.email')) ?></label>
                    <div class="lt-input-icon">
                        <i data-lucide="at-sign"></i>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="lt-field">
                    <label for="password"><?= e(__('auth.password')) ?></label>
                    <div class="lt-input-icon">
                        <i data-lucide="lock"></i>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="lt-login-submit"><i data-lucide="log-in"></i><?= e(__('auth.submit')) ?></button>
            </form>
            <?php if ($canRecover ?? false): ?>
                <p class="lt-login-footnote"><a href="<?= e($basePath) ?>/password/forgot"><?= e(__('reset.link')) ?></a></p>
            <?php endif; ?>
            <p class="lt-login-footnote"><i data-lucide="shield-check"></i> <?= e(__('auth.footnote')) ?></p>
        </div>
    </section>
</div>
