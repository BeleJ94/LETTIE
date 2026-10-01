<?php /** @var App\Core\View $this */ ?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('auth.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--bleed<?php $this->stop() ?>

<div class="lt-login-page">
    <aside class="lt-login-hero" aria-labelledby="hero-title">
        <div class="lt-login-hero__text">
            <h2 id="hero-title" class="lt-login-hero__title"><?= e(__('auth.hero.title')) ?></h2>
            <p class="lt-login-hero__subtitle"><?= e(__('auth.hero.subtitle')) ?></p>
        </div>

        <img class="lt-login-hero__art" src="<?= e($basePath) ?>/assets/img/login-hero.svg" alt="" width="640" height="520">

        <ul class="lt-login-hero__features">
            <?php foreach (['register' => 'scan-line', 'assign' => 'route', 'deadline' => 'calendar-clock', 'trace' => 'shield-check'] as $key => $icon): ?>
                <li><i data-lucide="<?= e($icon) ?>"></i><span><?= e(__('auth.hero.features.' . $key)) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <section class="lt-login-panel" aria-labelledby="login-title">
        <div class="lt-login-form">
            <h1 id="login-title"><?= e(__('auth.title')) ?></h1>
            <p class="lt-muted"><?= e(__('auth.intro')) ?></p>
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
            <p class="lt-login-footnote"><i data-lucide="shield-check"></i> <?= e(__('auth.footnote')) ?></p>
        </div>
    </section>
</div>
