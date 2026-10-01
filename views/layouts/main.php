<?php
/**
 * @var App\Core\View $this
 * @var App\Core\Csrf $csrf
 * @var ?App\Domain\Auth\User $currentUser
 * @var string $basePath
 * @var string $currentPath
 * @var string $timezone
 * @var array<string, mixed> $i18n
 */
$assets = $basePath . '/assets';
$vendor = $assets . '/vendor';
$navItems = [
    ['path' => '/', 'icon' => 'layout-dashboard', 'label' => 'nav.dashboard', 'permission' => App\Domain\Auth\Permission::MailView],
    ['path' => '/mails', 'icon' => 'mail', 'label' => 'nav.mails', 'permission' => App\Domain\Auth\Permission::MailView],
    ['path' => '/register', 'icon' => 'book-open', 'label' => 'nav.register', 'permission' => App\Domain\Auth\Permission::MailView],
    ['path' => '/statistics', 'icon' => 'chart-column', 'label' => 'nav.statistics', 'permission' => App\Domain\Auth\Permission::ReportsView],
    ['path' => '/delegations', 'icon' => 'calendar-off', 'label' => 'nav.delegations', 'permission' => App\Domain\Auth\Permission::MailView],
    ['path' => '/notifications', 'icon' => 'bell', 'label' => 'nav.notifications', 'permission' => App\Domain\Auth\Permission::MailView],
    ['path' => '/retention-rules', 'icon' => 'archive', 'label' => 'nav.retention', 'permission' => App\Domain\Auth\Permission::SettingsManage],
    ['path' => '/correspondents','icon' => 'contact', 'label' => 'nav.correspondents', 'permission' => App\Domain\Auth\Permission::CorrespondentsManage],
];
$isCurrent = static fn (string $path): bool => $path === '/'
    ? $currentPath === '/'
    : ($currentPath === $path || str_starts_with($currentPath, $path . '/'));
?>
<!doctype html>
<html lang="<?= e($locale ?? 'fr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <meta name="theme-color" content="#1f5fd1">
    <title><?= e($this->section('title', __('app.name'))) ?> · <?= e(__('app.name')) ?></title>
    <link rel="preload" href="<?= e($vendor) ?>/ibm-plex-sans-5.1.0/IBMPlexSans-Regular-Latin1.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e($vendor) ?>/datatables-2.1.8/dataTables.dataTables.min.css">
    <link rel="stylesheet" href="<?= e($vendor) ?>/sweetalert2-11.14.5/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= e($assets) ?>/css/app.css">
</head>
<body class="<?= $currentUser === null ? 'lt-guest' : 'lt-authenticated' ?>"
      data-base-path="<?= e($basePath) ?>"
      data-locale="<?= e($locale ?? 'fr') ?>"
      data-timezone="<?= e($timezone) ?>"
      data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<a class="lt-skip" href="#main"><?= e(__('nav.skip')) ?></a>

<header class="lt-topbar">
    <?php if ($currentUser !== null): ?>
        <button type="button" class="lt-btn lt-btn--ghost lt-btn--icon lt-nav-toggle" data-lt-toggle="nav"
                aria-controls="lt-sidebar" aria-expanded="false" title="<?= e(__('nav.menu')) ?>">
            <i data-lucide="menu"></i><span class="lt-sr-only"><?= e(__('nav.menu')) ?></span>
        </button>
    <?php endif; ?>
    <a class="lt-brand" href="<?= e($basePath) ?>/"><i data-lucide="mails"></i><span><?= e(__('app.name')) ?></span></a>
    <div class="lt-topbar__spacer"></div>
    <div class="lt-topbar__tools">
        <button type="button" class="lt-btn lt-btn--ghost lt-btn--icon" data-lt-field-mode aria-pressed="false"
                title="<?= e(__('js.field_mode.on')) ?>">
            <i data-lucide="smartphone"></i><span class="lt-sr-only"><?= e(__('nav.field_mode')) ?></span>
        </button>
        <form method="post" action="<?= e($basePath) ?>/locale" class="lt-inline-form">
            <?= $csrf->field() ?>
            <label for="lt-locale"><?= e(__('locale.label')) ?></label>
            <select name="locale" id="lt-locale">
                <?php foreach (['fr', 'en'] as $code): ?>
                    <option value="<?= e($code) ?>"<?= $code === $locale ? ' selected' : '' ?>><?= e(__('locale.' . $code)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="lt-btn lt-btn--icon" title="<?= e(__('locale.switch')) ?>">
                <i data-lucide="languages"></i><span class="lt-sr-only"><?= e(__('locale.switch')) ?></span>
            </button>
        </form>
        <?php if ($currentUser !== null): ?>
            <a class="lt-btn lt-btn--ghost lt-btn--icon lt-bell" href="<?= e($basePath) ?>/notifications"
               title="<?= e(__('notification.title')) ?>" data-lt-notifications data-url="/notifications/count">
                <i data-lucide="bell"></i><span class="lt-sr-only"><?= e(__('notification.title')) ?></span>
                <span class="lt-bell__count" data-lt-notifications-count hidden></span>
            </a>
            <div class="lt-user">
                <span class="lt-user__name"><?= e($currentUser->fullName()) ?></span>
                <span class="lt-user__role"><?= e(__($currentUser->role->labelKey())) ?></span>
            </div>
            <form method="post" action="<?= e($basePath) ?>/logout" class="lt-inline-form">
                <?= $csrf->field() ?>
                <button type="submit" class="lt-btn lt-btn--ghost lt-btn--icon" title="<?= e(__('auth.logout')) ?>">
                    <i data-lucide="log-out"></i><span class="lt-sr-only"><?= e(__('auth.logout')) ?></span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</header>
<div class="lt-offline-banner" role="status"><?= e(__('js.offline')) ?></div>

<div class="lt-shell">
    <?php if ($currentUser !== null): ?>
        <nav id="lt-sidebar" class="lt-sidebar" aria-label="<?= e(__('nav.main')) ?>">
            <ul class="lt-nav">
                <?php foreach ($navItems as $item): ?>
                    <?php if ($currentUser->can($item['permission'])): ?>
                        <li>
                            <a href="<?= e($basePath . $item['path']) ?>"<?= $isCurrent($item['path']) ? ' aria-current="page"' : '' ?>>
                                <i data-lucide="<?= e($item['icon']) ?>"></i><span><?= e(__($item['label'])) ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="lt-backdrop"></div>
    <?php endif; ?>

    <main id="main" class="lt-main <?= e(trim($this->section('main_class'))) ?>" tabindex="-1">
        <?php foreach (['success', 'error'] as $type): ?>
            <?php if (!empty($flash[$type])): ?>
                <div class="lt-alert lt-alert--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($flash[$type]) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?= $this->section('content') ?>
    </main>
</div>

<script src="<?= e($vendor) ?>/jquery-3.7.1/jquery.min.js"></script>
<script src="<?= e($vendor) ?>/datatables-2.1.8/dataTables.min.js"></script>
<script src="<?= e($vendor) ?>/sweetalert2-11.14.5/sweetalert2.min.js"></script>
<script src="<?= e($vendor) ?>/lucide-0.460.0/lucide.min.js"></script>
<script src="<?= e($assets) ?>/js/lt-core.js"></script>
<script src="<?= e($assets) ?>/js/lt-tables.js"></script>
<script src="<?= e($assets) ?>/js/lt-export.js"></script>
<script src="<?= e($assets) ?>/js/app.js"></script>
<?php /* Page scripts (Chart.js, ExcelJS, pdfmake…) go in the "scripts" section, as external files only. */ ?>
<?= $this->section('scripts') ?>
</body>
</html>
