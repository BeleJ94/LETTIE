<?php
/**
 * Application shell (docs/FIORI_DESIGN.md §2 and §11): ShellBar, side navigation, profile menu.
 *
 * @var App\Core\View $this
 * @var App\Core\Csrf $csrf
 * @var ?App\Domain\Auth\User $currentUser
 * @var string $basePath
 * @var string $currentPath
 * @var string $timezone
 * @var array<string, mixed> $i18n
 */
use App\Domain\Auth\Permission;

$assets = $basePath . '/assets';
$vendor = $assets . '/vendor';
$locale ??= 'fr';
// Side navigation (docs/FIORI_DESIGN.md §11): daily work first, steering in a group, personal
// and administration settings pinned at the bottom. Every entry is filtered by permission.
$navMain = [
    ['path' => '/', 'icon' => 'home', 'label' => 'nav.dashboard', 'permission' => Permission::MailView],
    ['path' => '/mails/new', 'icon' => 'add', 'label' => 'nav.new_mail', 'permission' => Permission::MailCreate, 'action' => true],
    ['path' => '/mails', 'icon' => 'email', 'label' => 'nav.mails', 'permission' => Permission::MailView, 'children' => [
        ['query' => '', 'label' => 'nav.all_mails', 'permission' => Permission::MailView],
        ['query' => 'mine=1', 'label' => 'nav.my_mails', 'permission' => Permission::MailUpdate],
        ['query' => 'mine=1&overdue=1', 'label' => 'nav.my_overdue', 'permission' => Permission::MailUpdate, 'count' => 'mine_overdue'],
        ['query' => 'direction=incoming&status=registered', 'label' => 'nav.to_assign', 'permission' => Permission::MailAssign, 'count' => 'unassigned'],
    ]],
    ['path' => '/register', 'icon' => 'course-book', 'label' => 'nav.register', 'permission' => Permission::MailView],
    ['path' => '/correspondents', 'icon' => 'business-card', 'label' => 'nav.correspondents', 'permission' => Permission::CorrespondentsManage],
];
$navSteering = [
    ['path' => '/overview', 'icon' => 'activities', 'label' => 'nav.overview', 'permission' => Permission::ReportsView],
    ['path' => '/statistics', 'icon' => 'bar-chart', 'label' => 'nav.statistics', 'permission' => Permission::ReportsView],
];
$navFixed = [
    ['path' => '/delegations', 'icon' => 'away', 'label' => 'nav.delegations', 'permission' => Permission::MailView],
    ['path' => '/retention-rules', 'icon' => 'history', 'label' => 'nav.retention', 'permission' => Permission::SettingsManage],
];
$allowed = static fn (array $items): array => $currentUser === null ? [] : array_values(array_filter(
    $items,
    static fn (array $item): bool => $currentUser->can($item['permission']),
));
// "/mails/new" is its own entry: it does not select "Courrier".
$isCurrent = static fn (string $path): bool => match (true) {
    $path === '/' => $currentPath === '/',
    $path === '/mails' => $currentPath === '/mails' || (str_starts_with($currentPath, '/mails/') && $currentPath !== '/mails/new'),
    default => $currentPath === $path || str_starts_with($currentPath, $path . '/'),
};
$navItem = static function (array $item, string $slot = '') use ($basePath, $isCurrent): string {
    // An entry with shortcuts only opens and closes them: it has no address of its own (its first shortcut is the whole list).
    $parent = isset($item['children']);
    return '<ui5-side-navigation-item text="' . e(__($item['label'])) . '" icon="' . e($item['icon']) . '"'
        . ($parent ? ' unselectable' : ' href="' . e($basePath . $item['path']) . '"')
        . ($slot !== '' ? ' slot="' . e($slot) . '"' : '')
        . ($parent ? ' expanded' : '')
        . (!empty($item['action']) ? ' design="Action"' : (!$parent && $isCurrent($item['path']) ? ' selected' : ''))
        . '>';
};
$initials = $currentUser === null ? '' : mb_strtoupper(mb_substr($currentUser->firstName, 0, 1) . mb_substr($currentUser->lastName, 0, 1));
$otherLocale = $locale === 'fr' ? 'en' : 'fr';
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <title><?= e($this->section('title', __('app.name'))) ?> · <?= e(__('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($assets) ?>/img/logo.svg">
    <?php /* Theme and density first (before paint), read by the UI5 bundle: docs/FIORI_DESIGN.md §2-3, §6. */ ?>
    <script src="<?= e($assets) ?>/js/lt-theme.js"></script>
    <link rel="stylesheet" href="<?= e($vendor) ?>/ui5-webcomponents-2.27.2/ui5-fonts.css">
    <?php /* Page styles (a vendored library still used by one screen), as external files only. */ ?>
    <?= $this->section('styles') ?>
    <link rel="stylesheet" href="<?= e($assets) ?>/css/app.css">
</head>
<body class="<?= $currentUser === null ? 'lt-guest' : 'lt-authenticated' ?>"
      data-base-path="<?= e($basePath) ?>"
      data-locale="<?= e($locale) ?>"
      data-timezone="<?= e($timezone) ?>"
      data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<a class="lt-skip" href="#main"><?= e(__('nav.skip')) ?></a>

<ui5-navigation-layout id="lt-layout" class="lt-shell" mode="Auto">
    <ui5-shellbar slot="header" id="lt-shellbar" accessible-name="<?= e(__('app.name')) ?>"
        <?php if ($currentUser !== null): ?>
            show-notifications data-lt-notifications data-url="/notifications/count" data-href="/notifications"
        <?php endif; ?>>
        <?php if ($currentUser !== null): ?>
            <ui5-button slot="startButton" icon="menu2" design="Transparent" data-lt-toggle="nav"
                        accessible-name="<?= e(__('nav.menu')) ?>" tooltip="<?= e(__('nav.menu')) ?>"></ui5-button>
        <?php endif; ?>
        <ui5-shellbar-branding slot="branding" href="<?= e($basePath) ?>/" accessible-name="<?= e(__('app.name')) ?>">
            <?= e(__('app.name')) ?>
            <img slot="logo" src="<?= e($assets) ?>/img/logo.svg" alt="">
        </ui5-shellbar-branding>
        <?php if ($currentUser !== null && $currentUser->can(Permission::MailView)): ?>
            <ui5-shellbar-search slot="searchField" show-clear-icon data-lt-search data-url="/mails"
                                 placeholder="<?= e(__('launchpad.search')) ?>"
                                 accessible-name="<?= e(__('launchpad.search')) ?>"></ui5-shellbar-search>
        <?php endif; ?>
        <?php if ($currentUser === null): ?>
            <?php /* Guests have no profile menu: theme and language are direct ShellBar actions. */ ?>
            <ui5-shellbar-item icon="dark-mode" text="<?= e(__('nav.dark_theme')) ?>" data-lt-theme-toggle></ui5-shellbar-item>
            <ui5-shellbar-item icon="globe" text="<?= e(__('locale.' . $otherLocale)) ?>" data-lt-locale="<?= e($otherLocale) ?>"></ui5-shellbar-item>
        <?php else: ?>
            <ui5-avatar slot="profile" id="lt-profile" initials="<?= e($initials) ?>" color-scheme="Accent6"
                        accessible-name="<?= e(__('launchpad.profile', ['name' => $currentUser->fullName()])) ?>"></ui5-avatar>
        <?php endif; ?>
    </ui5-shellbar>

    <?php if ($currentUser !== null): ?>
        <?php /* Counters and the selected shortcut are set by app.js (data-lt-nav-counts, data-lt-nav-query). */ ?>
        <ui5-side-navigation slot="sideContent" id="lt-sidenav" accessible-name="<?= e(__('nav.main')) ?>"
                             data-lt-nav-counts data-url="/navigation/counts">
            <?php foreach ($allowed($navMain) as $item): ?>
                <?= $navItem($item) ?>
                    <?php foreach ($allowed($item['children'] ?? []) as $child): ?>
                        <ui5-side-navigation-sub-item text="<?= e(__($child['label'])) ?>"
                            href="<?= e($basePath . $item['path'] . ($child['query'] !== '' ? '?' . $child['query'] : '')) ?>" data-lt-nav-query="<?= e($child['query']) ?>"<?= $child['query'] === '' && $isCurrent($item['path']) ? ' selected' : '' ?>
                            data-label="<?= e(__($child['label'])) ?>"<?= isset($child['count']) ? ' data-count="' . e($child['count']) . '"' : '' ?>></ui5-side-navigation-sub-item>
                    <?php endforeach; ?>
                </ui5-side-navigation-item>
            <?php endforeach; ?>
            <?php if ($allowed($navSteering) !== []): ?>
                <ui5-side-navigation-group text="<?= e(__('launchpad.nav_groups.steering')) ?>" expanded>
                    <?php foreach ($allowed($navSteering) as $item): ?>
                        <?= $navItem($item) ?></ui5-side-navigation-item>
                    <?php endforeach; ?>
                </ui5-side-navigation-group>
            <?php endif; ?>
            <?php foreach ($allowed($navFixed) as $item): ?>
                <?= $navItem($item, 'fixedItems') ?></ui5-side-navigation-item>
            <?php endforeach; ?>
        </ui5-side-navigation>
    <?php endif; ?>

    <main id="main" class="lt-main <?= e(trim($this->section('main_class'))) ?>" tabindex="-1">
        <ui5-message-strip class="lt-offline-banner" design="Critical" hide-close-button role="status"><?= e(__('js.offline')) ?></ui5-message-strip>
        <?php foreach (['success' => 'Positive', 'error' => 'Negative'] as $type => $design): ?>
            <?php if (!empty($flash[$type])): ?>
                <ui5-message-strip class="lt-flash lt-flash--<?= e($type) ?>" design="<?= e($design) ?>"
                                   role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($flash[$type]) ?></ui5-message-strip>
            <?php endif; ?>
        <?php endforeach; ?>
        <?= $this->section('content') ?>
    </main>
</ui5-navigation-layout>

<?php if ($currentUser !== null): ?>
    <ui5-user-menu id="lt-user-menu" opener="lt-profile">
        <ui5-user-menu-account slot="accounts" selected
            title-text="<?= e($currentUser->fullName()) ?>"
            subtitle-text="<?= e(__($currentUser->role->labelKey())) ?>"
            description="<?= e($currentUser->email) ?>"
            avatar-initials="<?= e($initials) ?>" avatar-color-scheme="Accent6"></ui5-user-menu-account>
        <ui5-user-menu-item icon="dark-mode" text="<?= e(__('nav.dark_theme')) ?>" data-lt-theme-toggle></ui5-user-menu-item>
        <ui5-user-menu-item icon="globe" text="<?= e(__('locale.label')) ?>">
            <?php foreach (['fr', 'en'] as $code): ?>
                <ui5-user-menu-item text="<?= e(__('locale.' . $code)) ?>" data-lt-locale="<?= e($code) ?>"
                    <?= $code === $locale ? ' icon="accept"' : '' ?>></ui5-user-menu-item>
            <?php endforeach; ?>
        </ui5-user-menu-item>
    </ui5-user-menu>
    <form method="post" action="<?= e($basePath) ?>/logout" id="lt-logout-form" hidden>
        <?= $csrf->field() ?>
    </form>
<?php endif; ?>
<form method="post" action="<?= e($basePath) ?>/locale" id="lt-locale-form" hidden>
    <?= $csrf->field() ?>
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
</form>

<script src="<?= e($vendor) ?>/jquery-3.7.1/jquery.min.js"></script>
<script src="<?= e($vendor) ?>/lucide-0.460.0/lucide.min.js"></script>
<script src="<?= e($assets) ?>/js/lt-core.js"></script>
<script src="<?= e($assets) ?>/js/lt-export.js"></script>
<?php /* Libraries a page needs before app.js wires the page (DataTables on the screens not migrated yet). */ ?>
<?= $this->section('libraries') ?>
<script src="<?= e($assets) ?>/js/app.js"></script>
<script type="module" src="<?= e($vendor) ?>/ui5-webcomponents-2.27.2/ui5.js"></script>
<?php /* Page scripts (Chart.js, ExcelJS, pdfmake…) go in the "scripts" section, as external files only. */ ?>
<?= $this->section('scripts') ?>
</body>
</html>
