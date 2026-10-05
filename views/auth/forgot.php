<?php
/**
 * @floorplan None — forgotten password (before entering the application)
 *
 * @var App\Core\View $this
 * @var bool $sent
 */
?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('reset.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--bleed<?php $this->stop() ?>

<div class="lt-login-page">
    <?= $this->partial('auth/_hero') ?>

    <section class="lt-login-panel" aria-labelledby="forgot-title">
        <div class="lt-login-form">
            <h1 id="forgot-title"><?= e(__('reset.title')) ?></h1>
            <?php if ($sent): ?>
                <p class="lt-alert lt-alert--success" role="status"><?= e(__('reset.sent')) ?></p>
            <?php else: ?>
                <p class="lt-muted"><?= e(__('reset.intro')) ?></p>
                <form method="post" action="<?= e($basePath) ?>/password/forgot" class="lt-form">
                    <?= $csrf->field() ?>
                    <div class="lt-field">
                        <label for="email"><?= e(__('auth.email')) ?></label>
                        <div class="lt-input-icon">
                            <i data-lucide="at-sign"></i>
                            <input type="email" id="email" name="email" maxlength="190" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <button type="submit" class="lt-login-submit"><?= e(__('reset.send')) ?></button>
                </form>
            <?php endif; ?>
            <p class="lt-login-footnote"><a href="<?= e($basePath) ?>/login"><?= e(__('auth.code.back')) ?></a></p>
        </div>
    </section>
</div>
