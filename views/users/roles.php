<?php
/**
 * @floorplan ListReport
 *
 * Roles and their permissions, read-only: the matrix is defined in the code (App\Domain\Auth\Role)
 * and shown here so that an administrator knows what a role gives before choosing it
 * (docs/FIORI_DESIGN.md §12, "Liste de paramétrage").
 *
 * @var App\Core\View $this
 */
use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;

$this->layout('layouts/main');
?>
<?php $this->start('title') ?><?= e(__('role_matrix.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>

<ui5-dynamic-page id="roles-report" class="lt-list-report">
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('user.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/users"><?= e(__('user.title')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e(__('role_matrix.title')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H4"><?= e(__('role_matrix.title')) ?></ui5-title>
        <ui5-title slot="snappedHeading" level="H1" size="H5"><?= e(__('role_matrix.title')) ?></ui5-title>
    </ui5-dynamic-page-title>

    <div class="lt-list-report__content">
        <ui5-message-strip class="lt-list-report__result" design="Information" hide-close-button><?= e(__('role_matrix.help')) ?></ui5-message-strip>

        <div class="lt-dt-block">
            <table class="lt-table lt-dt lt-dt--static" aria-label="<?= e(__('role_matrix.title')) ?>">
                <thead>
                <tr>
                    <th><?= e(__('role_matrix.permission')) ?></th>
                    <?php foreach (Role::cases() as $role): ?>
                        <th><?= e(__($role->labelKey())) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (Permission::cases() as $permission): ?>
                    <tr data-permission="<?= e($permission->value) ?>">
                        <td data-label="<?= e(__('role_matrix.permission')) ?>"><span class="lt-cell-strong"><?= e(__('permissions.' . $permission->value)) ?></span></td>
                        <?php foreach (Role::cases() as $role): ?>
                            <td data-label="<?= e(__($role->labelKey())) ?>" data-role="<?= e($role->value) ?>">
                                <?php if ($role->can($permission)): ?>
                                    <ui5-tag design="Positive"><?= e(__('common.yes')) ?></ui5-tag>
                                <?php else: ?>
                                    <ui5-text>—</ui5-text>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</ui5-dynamic-page>
