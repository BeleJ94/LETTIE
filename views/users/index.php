<?php
/**
 * @floorplan ListReport
 *
 * User accounts, built from the List Report template (docs/FIORI_DESIGN.md §12).
 *
 * @var App\Core\View $this
 * @var list<array{id: int, code: string, name: string}> $sites
 */
use App\Domain\Auth\Role;

$this->layout('layouts/main');
$multiSite = count($sites) > 1;
// name, label, renderer, sortable, required (cannot be hidden)
$columns = [
    ['name', 'user.fields.name', 'strong', true, true],
    ['email', 'user.fields.email', 'text', true, false],
    ['role', 'user.fields.role', 'text', true, false],
];
if ($multiSite) {
    $columns[] = ['site_name', 'user.fields.site_name', 'text', true, false];
}
$columns[] = ['department_name', 'user.fields.department_name', 'text', true, false];
$columns[] = ['status', 'user.fields.status', 'tag:user_status', true, false];
$columns[] = ['last_login_at', 'user.fields.last_login_at', 'datetime', true, false];
?>
<?php $this->start('title') ?><?= e(__('user.title')) ?><?php $this->stop() ?>
<?= $this->partial('partials/list-report/assets') ?>

<ui5-dynamic-page id="users-report" class="lt-list-report" data-lt-list-report
    data-key="users" data-url="/users/data" data-row-href="/users/{id}/edit" data-row-label="name"
    data-sort="name" data-dir="asc" data-per-page="25"
    data-title="<?= e(__('user.title')) ?>"
    data-export-source="/users/export" data-export-set="users" data-export-title="<?= e(__('user.export_title')) ?>">

    <?= $this->partial('partials/list-report/title', ['title' => __('user.title')]) ?>

    <ui5-dynamic-page-header slot="headerArea" accessible-name="<?= e(__('list.filters')) ?>">
        <div class="lt-filter-bar" role="search" aria-label="<?= e(__('list.filters')) ?>">
            <div class="lt-filter-bar__field lt-filter-bar__field--wide">
                <ui5-label for="f-search" show-colon><?= e(__('list.search')) ?></ui5-label>
                <ui5-input id="f-search" data-lt-search show-clear-icon placeholder="<?= e(__('user.search_placeholder')) ?>">
                    <ui5-icon slot="icon" name="search"></ui5-icon>
                </ui5-input>
            </div>
            <div class="lt-filter-bar__field">
                <ui5-label for="f-role" show-colon><?= e(__('user.fields.role')) ?></ui5-label>
                <ui5-select id="f-role" data-lt-filter="role">
                    <ui5-option value=""><?= e(__('common.all')) ?></ui5-option>
                    <?php foreach (Role::cases() as $role): ?>
                        <ui5-option value="<?= e($role->value) ?>"><?= e(__($role->labelKey())) ?></ui5-option>
                    <?php endforeach; ?>
                </ui5-select>
            </div>
            <?php if ($multiSite): ?>
                <div class="lt-filter-bar__field">
                    <ui5-label for="f-site" show-colon><?= e(__('user.fields.site_id')) ?></ui5-label>
                    <ui5-select id="f-site" data-lt-filter="site_id">
                        <ui5-option value=""><?= e(__('common.all')) ?></ui5-option>
                        <?php foreach ($sites as $site): ?>
                            <ui5-option value="<?= e($site['id']) ?>"><?= e($site['name']) ?></ui5-option>
                        <?php endforeach; ?>
                    </ui5-select>
                </div>
            <?php endif; ?>
            <div class="lt-filter-bar__field lt-filter-bar__field--checks">
                <ui5-checkbox data-lt-filter="inactive" text="<?= e(__('user.show_inactive')) ?>"></ui5-checkbox>
            </div>
            <div class="lt-filter-bar__actions">
                <ui5-button design="Emphasized" data-lt-go><?= e(__('list.go')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-reset><?= e(__('list.reset')) ?></ui5-button>
            </div>
        </div>
    </ui5-dynamic-page-header>

    <div class="lt-list-report__content">
        <div class="lt-table-header">
            <ui5-title level="H2" size="H5" data-lt-count><?= e(__('user.title')) ?></ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <ui5-toolbar-button icon="add" text="<?= e(__('list.create')) ?>" data-lt-href="/users/new"
                                    tooltip="<?= e(__('user.new')) ?>" accessible-name="<?= e(__('user.new')) ?>"></ui5-toolbar-button>
                <ui5-toolbar-button icon="upload" text="<?= e(__('import.button')) ?>" data-lt-href="/users/import"
                                    tooltip="<?= e(__('import.title')) ?>" accessible-name="<?= e(__('import.title')) ?>"></ui5-toolbar-button>
                <ui5-toolbar-button icon="user-settings" text="<?= e(__('role_matrix.button')) ?>" data-lt-href="/roles"
                                    tooltip="<?= e(__('role_matrix.title')) ?>" accessible-name="<?= e(__('role_matrix.title')) ?>"></ui5-toolbar-button>
                <ui5-toolbar-separator></ui5-toolbar-separator>
                <?= $this->partial('partials/list-report/toolbar-end', ['export' => true]) ?>
            </ui5-toolbar>
        </div>

        <?= $this->partial('partials/list-report/table', ['label' => __('user.title'), 'columns' => $columns]) ?>
    </div>
</ui5-dynamic-page>

<?= $this->partial('partials/list-report/dialogs', ['id' => 'users']) ?>
