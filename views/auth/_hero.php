<?php
/**
 * Illustrated panel shared by the screens shown before sign-in (login, code, forgotten password).
 *
 * @var App\Core\View $this
 */
?>
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
