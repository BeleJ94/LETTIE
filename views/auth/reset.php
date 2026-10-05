<?php
/**
 * @floorplan None — choice of a new password from an e-mail link (before entering the application)
 *
 * @var App\Core\View $this
 * @var string $token
 * @var bool $valid the link can still be used
 * @var array<string, list<string>> $errors
 */
use App\Domain\Auth\PasswordPolicy;
?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('reset.new_title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--bleed<?php $this->stop() ?>

<div class="lt-login-page">
    <?= $this->partial('auth/_hero') ?>

    <section class="lt-login-panel" aria-labelledby="reset-title">
        <div class="lt-login-form">
            <h1 id="reset-title"><?= e(__('reset.new_title')) ?></h1>
            <?php if (!$valid): ?>
                <p class="lt-alert lt-alert--error" role="alert"><?= e(__('rules.reset.invalid')) ?></p>
                <p class="lt-login-footnote"><a href="<?= e($basePath) ?>/password/forgot"><?= e(__('reset.again')) ?></a></p>
            <?php else: ?>
                <p class="lt-muted"><?= e(__('profile.password_help', ['min' => PasswordPolicy::MIN_LENGTH])) ?></p>
                <?php foreach ($errors as $messages): ?>
                    <p class="lt-alert lt-alert--error" role="alert"><?= e(implode(' ', $messages)) ?></p>
                <?php endforeach; ?>
                <form method="post" action="<?= e($basePath) ?>/password/reset/<?= e($token) ?>" class="lt-form" autocomplete="off">
                    <?= $csrf->field() ?>
                    <?php foreach (['new_password', 'new_password_confirmation'] as $name): ?>
                        <div class="lt-field">
                            <label for="<?= e($name) ?>"><?= e(__('profile.fields.' . $name)) ?></label>
                            <div class="lt-input-icon">
                                <i data-lucide="lock"></i>
                                <input type="password" id="<?= e($name) ?>" name="<?= e($name) ?>" maxlength="<?= e(PasswordPolicy::MAX_BYTES) ?>" autocomplete="new-password" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="lt-login-submit"><?= e(__('profile.change_password')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>
