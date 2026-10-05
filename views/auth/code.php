<?php
/**
 * @floorplan None — second step of the sign-in (before entering the application)
 *
 * @var App\Core\View $this
 * @var ?string $error
 */
?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('auth.code.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--bleed<?php $this->stop() ?>

<div class="lt-login-page">
    <?= $this->partial('auth/_hero') ?>

    <section class="lt-login-panel" aria-labelledby="code-title">
        <div class="lt-login-form">
            <h1 id="code-title"><?= e(__('auth.code.title')) ?></h1>
            <p class="lt-muted"><?= e(__('auth.code.intro')) ?></p>
            <?php if ($error !== null): ?>
                <p class="lt-alert lt-alert--error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($basePath) ?>/login/code" class="lt-form">
                <?= $csrf->field() ?>
                <div class="lt-field">
                    <label for="code"><?= e(__('auth.code.label')) ?></label>
                    <div class="lt-input-icon">
                        <i data-lucide="shield-check"></i>
                        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
                    </div>
                </div>
                <button type="submit" class="lt-login-submit"><i data-lucide="log-in"></i><?= e(__('auth.code.submit')) ?></button>
            </form>
            <p class="lt-login-footnote"><a href="<?= e($basePath) ?>/login"><?= e(__('auth.code.back')) ?></a></p>
        </div>
    </section>
</div>
