<?php
/**
 * @floorplan ListReport
 *
 * Sites and departments: two short settings lists rendered by the server
 * (docs/FIORI_DESIGN.md §12, "Liste de paramétrage"). Creation and modification happen in dialogs.
 *
 * @var App\Core\View $this
 * @var list<array{id: int, code: string, name: string, is_active: bool, users_count: int}> $sites
 * @var list<array{id: int, site_id: int, site_name: string, code: string, name: string, is_active: bool, users_count: int}> $departments
 * @var bool $canManageSites
 * @var int $ownSiteId
 * @var ?string $openDialog id of the dialog to reopen with its errors
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
use App\Core\Ui5;
use App\Domain\Organization\OrganizationRules;

$this->layout('layouts/main');
$multiSite = count($sites) > 1;
$siteOptions = array_column(array_filter($sites, static fn (array $s): bool => $s['is_active']), 'name', 'id');

/**
 * One dialog: a site or a department, new or existing.
 *
 * @param array{id: string, title: string, action: string, method: string, code: string, name: string, site?: bool} $d
 */
$dialog = function (array $d) use ($openDialog, $values, $errors, $csrf, $basePath, $siteOptions, $ownSiteId): void {
    $open = $openDialog === $d['id'];
    $e = $open ? $errors : [];
    $value = static fn (string $key, string $default): string => $open && is_scalar($values[$key] ?? null) ? (string) $values[$key] : $default;
    ?>
    <ui5-dialog id="<?= e($d['id']) ?>" data-lt-form-dialog header-text="<?= e($d['title']) ?>"<?= $open ? ' open' : '' ?>>
        <form method="post" action="<?= e($basePath . $d['action']) ?>" class="lt-dialog-form" novalidate>
            <?= $csrf->field() ?>
            <?php if ($d['method'] !== 'POST'): ?>
                <input type="hidden" name="_method" value="<?= e($d['method']) ?>">
            <?php endif; ?>
            <?php if (!empty($d['site'])): ?>
                <?php if (count($siteOptions) > 1): ?>
                    <ui5-label for="<?= e($d['id']) ?>-site" required show-colon><?= e(__('organization.fields.site_id')) ?></ui5-label>
                    <ui5-select id="<?= e($d['id']) ?>-site" name="site_id"<?= Ui5::state($e, 'site_id') ?>><?= Ui5::options($siteOptions, $value('site_id', (string) $ownSiteId)) ?><?= Ui5::stateMessage($e, 'site_id') ?></ui5-select>
                <?php else: ?>
                    <input type="hidden" name="site_id" value="<?= e($ownSiteId) ?>">
                <?php endif; ?>
            <?php endif; ?>
            <ui5-label for="<?= e($d['id']) ?>-name" required show-colon><?= e(__('organization.fields.name')) ?></ui5-label>
            <ui5-input id="<?= e($d['id']) ?>-name" name="name" maxlength="<?= e(OrganizationRules::NAME_MAX) ?>" required
                       value="<?= e($value('name', $d['name'])) ?>"<?= Ui5::state($e, 'name') ?>><?= Ui5::stateMessage($e, 'name') ?></ui5-input>
            <ui5-label for="<?= e($d['id']) ?>-code" required show-colon><?= e(__('organization.fields.code')) ?></ui5-label>
            <ui5-input id="<?= e($d['id']) ?>-code" name="code" maxlength="<?= e(OrganizationRules::CODE_MAX) ?>" required
                       value="<?= e($value('code', $d['code'])) ?>"<?= Ui5::state($e, 'code') ?>><?= Ui5::stateMessage($e, 'code') ?></ui5-input>
            <ui5-label wrapping-type="Normal"><?= e(__('organization.code_help')) ?></ui5-label>
        </form>
        <div slot="footer" class="lt-dialog-footer">
            <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('common.save')) ?></ui5-button>
            <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
        </div>
    </ui5-dialog>
    <?php
};

/** Activate / deactivate form of a row. */
$toggle = function (string $kind, array $row) use ($csrf, $basePath): void {
    $id = $kind . '-toggle-' . $row['id'];
    ?>
    <form id="<?= e($id) ?>" method="post" action="<?= e($basePath) ?>/<?= e($kind) ?>s/<?= e($row['id']) ?>/toggle" class="lt-inline-form"
          data-lt-confirm="<?= e(__($row['is_active'] ? 'organization.confirm_disable' : 'organization.confirm_enable', ['name' => $row['name']])) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="active" value="<?= $row['is_active'] ? '0' : '1' ?>">
        <ui5-button design="Transparent" data-lt-submit="<?= e($id) ?>"><?= e(__($row['is_active'] ? 'organization.disable' : 'organization.enable')) ?></ui5-button>
    </form>
    <?php
};
?>
<?php $this->start('title') ?><?= e(__('organization.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="organization-report" class="lt-list-report">
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-title slot="heading" level="H1" size="H4"><?= e(__('organization.title')) ?></ui5-title>
        <ui5-title slot="snappedHeading" level="H1" size="H5"><?= e(__('organization.title')) ?></ui5-title>
    </ui5-dynamic-page-title>

    <div class="lt-list-report__content">
        <ui5-message-strip class="lt-list-report__result" design="Information" hide-close-button><?= e(__('organization.help')) ?></ui5-message-strip>

        <div class="lt-table-header">
            <ui5-title level="H2" size="H5"><?= e(__('organization.sites')) ?> (<?= e(count($sites)) ?>)</ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <?php if ($canManageSites): ?>
                    <ui5-toolbar-button icon="add" text="<?= e(__('list.create')) ?>" data-lt-open-dialog="site-new"
                                        tooltip="<?= e(__('organization.new_site')) ?>" accessible-name="<?= e(__('organization.new_site')) ?>"></ui5-toolbar-button>
                <?php endif; ?>
            </ui5-toolbar>
        </div>
        <div class="lt-dt-block">
            <table class="lt-table lt-dt lt-dt--static" data-table="sites" aria-label="<?= e(__('organization.sites')) ?>">
                <thead>
                <tr>
                    <th><?= e(__('organization.fields.name')) ?></th>
                    <th><?= e(__('organization.fields.code')) ?></th>
                    <th class="lt-num"><?= e(__('organization.fields.users_count')) ?></th>
                    <th><?= e(__('organization.fields.is_active')) ?></th>
                    <th><span class="lt-sr-only"><?= e(__('list.toolbar')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $site): ?>
                    <tr>
                        <td data-label="<?= e(__('organization.fields.name')) ?>"><span class="lt-cell-strong"><?= e($site['name']) ?></span></td>
                        <td data-label="<?= e(__('organization.fields.code')) ?>"><?= e($site['code']) ?></td>
                        <td data-label="<?= e(__('organization.fields.users_count')) ?>" class="lt-num"><?= e($site['users_count']) ?></td>
                        <td data-label="<?= e(__('organization.fields.is_active')) ?>"><?= Ui5::tag('user_status', $site['is_active'] ? 'active' : 'inactive') ?></td>
                        <td class="lt-dt__actions">
                            <div class="lt-inline-form">
                                <ui5-button design="Transparent" data-lt-open-dialog="site-<?= e($site['id']) ?>"><?= e(__('common.edit')) ?></ui5-button>
                                <?php if ($canManageSites): ?>
                                    <?php $toggle('site', $site) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lt-table-header lt-table-header--next">
            <ui5-title level="H2" size="H5"><?= e(__('organization.departments')) ?> (<?= e(count($departments)) ?>)</ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <ui5-toolbar-button icon="add" text="<?= e(__('list.create')) ?>" data-lt-open-dialog="department-new"
                                    tooltip="<?= e(__('organization.new_department')) ?>" accessible-name="<?= e(__('organization.new_department')) ?>"></ui5-toolbar-button>
            </ui5-toolbar>
        </div>
        <div class="lt-dt-block">
            <?php if ($departments === []): ?>
                <ui5-illustrated-message name="NoData" design="Dot" title-text="<?= e(__('organization.departments')) ?>" subtitle-text="<?= e(__('organization.no_department')) ?>"></ui5-illustrated-message>
            <?php else: ?>
                <table class="lt-table lt-dt lt-dt--static" data-table="departments" aria-label="<?= e(__('organization.departments')) ?>">
                    <thead>
                    <tr>
                        <th><?= e(__('organization.fields.name')) ?></th>
                        <th><?= e(__('organization.fields.code')) ?></th>
                        <?php if ($multiSite): ?>
                            <th><?= e(__('organization.fields.site_id')) ?></th>
                        <?php endif; ?>
                        <th class="lt-num"><?= e(__('organization.fields.users_count')) ?></th>
                        <th><?= e(__('organization.fields.is_active')) ?></th>
                        <th><span class="lt-sr-only"><?= e(__('list.toolbar')) ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departments as $department): ?>
                        <tr>
                            <td data-label="<?= e(__('organization.fields.name')) ?>"><span class="lt-cell-strong"><?= e($department['name']) ?></span></td>
                            <td data-label="<?= e(__('organization.fields.code')) ?>"><?= e($department['code']) ?></td>
                            <?php if ($multiSite): ?>
                                <td data-label="<?= e(__('organization.fields.site_id')) ?>"><?= e($department['site_name']) ?></td>
                            <?php endif; ?>
                            <td data-label="<?= e(__('organization.fields.users_count')) ?>" class="lt-num"><?= e($department['users_count']) ?></td>
                            <td data-label="<?= e(__('organization.fields.is_active')) ?>"><?= Ui5::tag('user_status', $department['is_active'] ? 'active' : 'inactive') ?></td>
                            <td class="lt-dt__actions">
                                <div class="lt-inline-form">
                                    <ui5-button design="Transparent" data-lt-open-dialog="department-<?= e($department['id']) ?>"><?= e(__('common.edit')) ?></ui5-button>
                                    <?php $toggle('department', $department) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</ui5-dynamic-page>

<?php
if ($canManageSites) {
    $dialog(['id' => 'site-new', 'title' => __('organization.new_site'), 'action' => '/sites', 'method' => 'POST', 'code' => '', 'name' => '']);
}
foreach ($sites as $site) {
    $dialog(['id' => 'site-' . $site['id'], 'title' => __('organization.edit_site'), 'action' => '/sites/' . $site['id'], 'method' => 'PUT', 'code' => $site['code'], 'name' => $site['name']]);
}
$dialog(['id' => 'department-new', 'title' => __('organization.new_department'), 'action' => '/departments', 'method' => 'POST', 'code' => '', 'name' => '', 'site' => true]);
foreach ($departments as $department) {
    $dialog(['id' => 'department-' . $department['id'], 'title' => __('organization.edit_department'), 'action' => '/departments/' . $department['id'], 'method' => 'PUT', 'code' => $department['code'], 'name' => $department['name']]);
}
?>
