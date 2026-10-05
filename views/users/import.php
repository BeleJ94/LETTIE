<?php
/**
 * @floorplan ObjectPage
 *
 * Import of user accounts from a CSV file (docs/FIORI_DESIGN.md, "Modèle Object Page", creation mode):
 * the file is checked first, the valid lines are created on confirmation, and the temporary
 * passwords are shown once.
 *
 * @var App\Core\View $this
 * @var array<string, list<string>> $errors
 * @var ?list<array<string, mixed>> $checked lines of the file with their errors (step 2)
 * @var ?list<array{line: int, name: string, email: string, password: ?string, error: ?string}> $results created accounts (step 3)
 */
use App\Core\Ui5;
use App\Domain\Auth\UserImport;

$this->layout('layouts/main');
$valid = $checked !== null ? count(array_filter($checked, static fn (array $row): bool => $row['errors'] === [])) : 0;
$step = $results !== null ? 'results' : ($checked !== null ? 'check' : 'file');
?>
<?php $this->start('title') ?><?= e(__('import.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="import-page" class="lt-object-page" data-lt-object-page data-editing data-step="<?= e($step) ?>" show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('user.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/users"><?= e(__('user.title')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e(__('import.title')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e(__('import.title')) ?></ui5-title>
    </ui5-dynamic-page-title>

    <div class="lt-object-page__content">
        <?php if ($step === 'file'): ?>
            <form id="import-form" method="post" enctype="multipart/form-data" novalidate data-lt-validate action="<?= e($basePath) ?>/users/import">
                <?= $csrf->field() ?>
                <section class="lt-op-section" id="file-section" aria-labelledby="file-title">
                    <ui5-title level="H2" size="H4" id="file-title"><?= e(__('import.file')) ?></ui5-title>
                    <ui5-form layout="S1 M1 L2 XL2" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('import.file')) ?>">
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="file" required show-colon><?= e(__('import.file')) ?></ui5-label>
                            <div class="lt-field-stack">
                                <ui5-file-uploader id="file" name="file" accept=".csv,text/csv" required placeholder="<?= e(__('import.file')) ?>"<?= Ui5::state($errors, 'file') ?>><?= Ui5::stateMessage($errors, 'file') ?></ui5-file-uploader>
                                <ui5-label wrapping-type="Normal"><?= e(__('import.file_help', ['max' => UserImport::MAX_ROWS])) ?></ui5-label>
                            </div>
                        </ui5-form-item>
                    </ui5-form>
                </section>
                <section class="lt-op-section" id="format-section" aria-labelledby="format-title">
                    <ui5-title level="H2" size="H4" id="format-title"><?= e(__('import.format')) ?></ui5-title>
                    <div class="lt-op-block lt-op-block--padded">
                        <ui5-list separators="None" accessible-name="<?= e(__('import.format')) ?>">
                            <?php foreach (['columns', 'role', 'site', 'department', 'password'] as $rule): ?>
                                <ui5-li type="Inactive" wrapping-type="Normal"><?= e(__('import.rules.' . $rule)) ?></ui5-li>
                            <?php endforeach; ?>
                        </ui5-list>
                        <ui5-text class="lt-change"><?= e(__('import.example')) ?></ui5-text>
                    </div>
                </section>
            </form>
        <?php elseif ($step === 'check'): ?>
            <ui5-message-strip class="lt-list-report__result" design="<?= $valid === count($checked) ? 'Positive' : ($valid === 0 ? 'Negative' : 'Critical') ?>" hide-close-button><?= e(__('import.checked', ['valid' => $valid, 'total' => count($checked)])) ?></ui5-message-strip>
            <form id="import-confirm" method="post" action="<?= e($basePath) ?>/users/import/confirm" hidden><?= $csrf->field() ?></form>
            <div class="lt-dt-block">
                <table class="lt-table lt-dt lt-dt--static" data-table="import-check" aria-label="<?= e(__('import.title')) ?>">
                    <thead>
                    <tr>
                        <th class="lt-num"><?= e(__('import.line')) ?></th>
                        <th><?= e(__('user.fields.name')) ?></th>
                        <th><?= e(__('user.fields.email')) ?></th>
                        <th><?= e(__('user.fields.role')) ?></th>
                        <th><?= e(__('user.fields.site_id')) ?></th>
                        <th><?= e(__('user.fields.department_id')) ?></th>
                        <th><?= e(__('import.result')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($checked as $row): ?>
                        <tr>
                            <td class="lt-num" data-label="<?= e(__('import.line')) ?>"><?= e($row['line']) ?></td>
                            <td data-label="<?= e(__('user.fields.name')) ?>"><span class="lt-cell-strong"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></span></td>
                            <td data-label="<?= e(__('user.fields.email')) ?>"><?= e($row['email']) ?></td>
                            <td data-label="<?= e(__('user.fields.role')) ?>"><?= e($row['role'] !== null ? __('roles.' . $row['role']) : '') ?></td>
                            <td data-label="<?= e(__('user.fields.site_id')) ?>"><?= e($row['site_name']) ?></td>
                            <td data-label="<?= e(__('user.fields.department_id')) ?>"><?= e($row['department_name']) ?></td>
                            <td data-label="<?= e(__('import.result')) ?>" class="lt-dt__wrap">
                                <?php if ($row['errors'] === []): ?>
                                    <ui5-tag design="Positive"><?= e(__('import.ok')) ?></ui5-tag>
                                <?php else: ?>
                                    <ui5-tag design="Negative"><?= e(__('import.refused')) ?></ui5-tag>
                                    <?php foreach ($row['errors'] as [$key, $params]): ?>
                                        <ui5-text class="lt-change"><?= e(__($key, $params)) ?></ui5-text>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <ui5-message-strip class="lt-list-report__result" design="Critical" hide-close-button><?= e(__('import.passwords_once')) ?></ui5-message-strip>
            <div class="lt-dt-block">
                <table class="lt-table lt-dt lt-dt--static" data-table="import-results" aria-label="<?= e(__('import.title')) ?>">
                    <thead>
                    <tr>
                        <th><?= e(__('user.fields.name')) ?></th>
                        <th><?= e(__('user.fields.email')) ?></th>
                        <th><?= e(__('import.temporary_password')) ?></th>
                        <th><?= e(__('import.result')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $result): ?>
                        <tr>
                            <td data-label="<?= e(__('user.fields.name')) ?>"><span class="lt-cell-strong"><?= e($result['name']) ?></span></td>
                            <td data-label="<?= e(__('user.fields.email')) ?>"><?= e($result['email']) ?></td>
                            <td data-label="<?= e(__('import.temporary_password')) ?>"><?= e($result['password'] ?? '') ?></td>
                            <td data-label="<?= e(__('import.result')) ?>" class="lt-dt__wrap">
                                <?php if ($result['error'] === null): ?>
                                    <ui5-tag design="Positive"><?= e(__('import.created')) ?></ui5-tag>
                                <?php else: ?>
                                    <ui5-tag design="Negative"><?= e(__('import.refused')) ?></ui5-tag>
                                    <ui5-text class="lt-change"><?= e(__($result['error'])) ?></ui5-text>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?php if ($step === 'file'): ?>
            <ui5-button slot="endContent" design="Emphasized" data-lt-submit="import-form"><?= e(__('import.check')) ?></ui5-button>
            <ui5-button slot="endContent" design="Transparent" data-lt-href="/users"><?= e(__('common.cancel')) ?></ui5-button>
        <?php elseif ($step === 'check'): ?>
            <?php if ($valid > 0): ?>
                <ui5-button slot="endContent" design="Emphasized" data-lt-submit="import-confirm"><?= e(__('import.create', ['count' => $valid])) ?></ui5-button>
            <?php endif; ?>
            <ui5-button slot="endContent" design="Transparent" data-lt-href="/users/import"><?= e(__('import.other_file')) ?></ui5-button>
            <ui5-button slot="endContent" design="Transparent" data-lt-href="/users"><?= e(__('common.cancel')) ?></ui5-button>
        <?php else: ?>
            <ui5-button slot="endContent" design="Emphasized" data-lt-href="/users"><?= e(__('import.done')) ?></ui5-button>
        <?php endif; ?>
    </ui5-bar>
</ui5-dynamic-page>
