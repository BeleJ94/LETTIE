<?php
/**
 * @floorplan ListReport
 *
 * Retention rules: a short settings list rendered by the server (no filter, no paging),
 * with the look of the List Report table (docs/FIORI_DESIGN.md §12, "Liste de paramétrage").
 * A rule is created in a dialog opened from the table toolbar.
 *
 * @var App\Core\View $this
 * @var list<App\Domain\Retention\RetentionRule> $rules
 * @var list<array{started_at: DateTimeImmutable, finished_at: ?DateTimeImmutable, status: string, dry_run: bool, summary: array<string, mixed>}> $runs
 * @var bool $canGlobal
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
use App\Core\Ui5;
use App\Domain\Mail\Direction;
use App\Domain\Retention\RetentionAction;

$this->layout('layouts/main');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$runStates = ['success' => 'Positive', 'failed' => 'Negative', 'running' => 'Information'];
$columns = ['name', 'action', 'retention_months', 'direction', 'scope', 'is_active'];
?>
<?php $this->start('title') ?><?= e(__('retention.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="retention-report" class="lt-list-report">
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-title slot="heading" level="H1" size="H4"><?= e(__('retention.title')) ?></ui5-title>
        <ui5-title slot="snappedHeading" level="H1" size="H5"><?= e(__('retention.title')) ?></ui5-title>
    </ui5-dynamic-page-title>

    <div class="lt-list-report__content">
        <ui5-message-strip class="lt-list-report__result" design="Information" hide-close-button><?= e(__('retention.help')) ?></ui5-message-strip>

        <div class="lt-table-header">
            <ui5-title level="H2" size="H5"><?= e(__('retention.rules')) ?> (<?= e(count($rules)) ?>)</ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <ui5-toolbar-button icon="add" text="<?= e(__('list.create')) ?>" data-lt-open-dialog="retention-new"
                                    tooltip="<?= e(__('retention.new')) ?>" accessible-name="<?= e(__('retention.new')) ?>"></ui5-toolbar-button>
            </ui5-toolbar>
        </div>

        <div class="lt-dt-block">
            <?php if ($rules === []): ?>
                <ui5-illustrated-message name="NoData" design="Dot" title-text="<?= e(__('retention.rules')) ?>" subtitle-text="<?= e(__('retention.none')) ?>"></ui5-illustrated-message>
            <?php else: ?>
                <table class="lt-table lt-dt lt-dt--static" aria-label="<?= e(__('retention.rules')) ?>">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th<?= $column === 'retention_months' ? ' class="lt-num"' : '' ?>><?= e(__('retention.fields.' . $column)) ?></th>
                        <?php endforeach; ?>
                        <th><span class="lt-sr-only"><?= e(__('list.toolbar')) ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td data-label="<?= e(__('retention.fields.name')) ?>"><span class="lt-cell-strong"><?= e($rule->name) ?></span></td>
                            <td data-label="<?= e(__('retention.fields.action')) ?>"><?= e(__('enums.retention_action.' . $rule->action->value)) ?></td>
                            <td data-label="<?= e(__('retention.fields.retention_months')) ?>" class="lt-num"><?= e($rule->retentionMonths) ?></td>
                            <td data-label="<?= e(__('retention.fields.direction')) ?>"><?= $rule->direction !== null ? Ui5::tag('direction', $rule->direction->value) : e(__('common.all')) ?></td>
                            <td data-label="<?= e(__('retention.fields.scope')) ?>"><?= e(__($rule->siteId === null ? 'retention.all_sites' : 'retention.this_site')) ?></td>
                            <td data-label="<?= e(__('retention.fields.is_active')) ?>">
                                <ui5-tag design="<?= $rule->isActive ? 'Positive' : 'Neutral' ?>"><?= e(__($rule->isActive ? 'common.yes' : 'common.no')) ?></ui5-tag>
                            </td>
                            <td class="lt-dt__actions">
                                <form id="rule-toggle-<?= e($rule->id) ?>" method="post" action="<?= e($basePath) ?>/retention-rules/<?= e($rule->id) ?>/toggle" class="lt-inline-form"
                                      data-lt-confirm="<?= e(__($rule->isActive ? 'retention.confirm_disable' : 'retention.confirm_enable', ['name' => $rule->name])) ?>"
                                      <?= $rule->isActive ? '' : 'data-lt-confirm-danger' ?>>
                                    <?= $csrf->field() ?>
                                    <input type="hidden" name="active" value="<?= $rule->isActive ? '0' : '1' ?>">
                                    <ui5-button design="Transparent" data-lt-submit="rule-toggle-<?= e($rule->id) ?>"><?= e(__($rule->isActive ? 'retention.disable' : 'retention.enable')) ?></ui5-button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="lt-table-header lt-table-header--next">
            <ui5-title level="H2" size="H5"><?= e(__('retention.runs')) ?></ui5-title>
        </div>
        <?php if ($runs === []): ?>
            <ui5-message-strip design="Critical" hide-close-button><?= e(__('retention.never_run')) ?></ui5-message-strip>
        <?php else: ?>
            <div class="lt-dt-block">
                <ui5-list separators="Inner" accessible-name="<?= e(__('retention.runs')) ?>">
                    <?php foreach ($runs as $run): ?>
                        <?php
                        $s = $run['summary'];
                        $details = [];
                        if (isset($s['reminders'], $s['retention'])) {
                            $details[] = __('retention.run_summary', [
                                'notifications' => $s['reminders']['created'] ?? 0,
                                'archived' => $s['retention']['archived'] ?? 0,
                                'purged' => $s['retention']['purged_files'] ?? 0,
                            ]);
                        }
                        if (!empty($s['error'])) {
                            $details[] = (string) $s['error'];
                        }
                        ?>
                        <ui5-li type="Inactive" wrapping-type="Normal" description="<?= e(implode(' — ', $details)) ?>"
                                additional-text="<?= e(__('retention.run_status.' . $run['status'])) ?>"
                                additional-text-state="<?= e($runStates[$run['status']] ?? 'None') ?>"><?= e(local_datetime($run['started_at'])) ?><?= $run['dry_run'] ? ' (' . e(__('retention.dry_run')) . ')' : '' ?></ui5-li>
                    <?php endforeach; ?>
                </ui5-list>
            </div>
        <?php endif; ?>
    </div>
</ui5-dynamic-page>

<ui5-dialog id="retention-new" data-lt-form-dialog header-text="<?= e(__('retention.new')) ?>"<?= $errors !== [] ? ' open' : '' ?>>
    <form id="retention-form" method="post" action="<?= e($basePath) ?>/retention-rules" novalidate data-lt-validate class="lt-dialog-form"
          data-lt-confirm="<?= e(__('retention.confirm_create')) ?>" data-lt-confirm-danger>
        <?= $csrf->field() ?>
        <?php if ($errors !== []): ?>
            <ui5-message-strip design="Negative" hide-close-button><?= e(implode(' ', array_merge(...array_values($errors)))) ?></ui5-message-strip>
        <?php endif; ?>
        <ui5-form layout="S1 M1 L1 XL1" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('retention.new')) ?>">
            <ui5-form-item>
                <ui5-label slot="labelContent" for="name" required show-colon><?= e(__('retention.fields.name')) ?></ui5-label>
                <ui5-input id="name" name="name" maxlength="150" required value="<?= e($v('name')) ?>"<?= Ui5::state($errors, 'name') ?>><?= Ui5::stateMessage($errors, 'name') ?></ui5-input>
            </ui5-form-item>
            <ui5-form-item>
                <ui5-label slot="labelContent" for="action" required show-colon><?= e(__('retention.fields.action')) ?></ui5-label>
                <div class="lt-field-stack">
                    <ui5-select id="action" name="action"<?= Ui5::state($errors, 'action') ?>><?= Ui5::enumOptions(RetentionAction::cases(), 'retention_action', $v('action')) ?><?= Ui5::stateMessage($errors, 'action') ?></ui5-select>
                    <ui5-label wrapping-type="Normal"><?= e(__('retention.action_help')) ?></ui5-label>
                </div>
            </ui5-form-item>
            <ui5-form-item>
                <ui5-label slot="labelContent" for="retention_months" required show-colon><?= e(__('retention.fields.retention_months')) ?></ui5-label>
                <ui5-step-input id="retention_months" name="retention_months" min="1" max="1200" required value="<?= e($v('retention_months') !== '' ? $v('retention_months') : '12') ?>"<?= Ui5::state($errors, 'retention_months') ?>><?= Ui5::stateMessage($errors, 'retention_months') ?></ui5-step-input>
            </ui5-form-item>
            <ui5-form-item>
                <ui5-label slot="labelContent" for="direction" show-colon><?= e(__('retention.fields.direction')) ?></ui5-label>
                <ui5-select id="direction" name="direction"><ui5-option value=""><?= e(__('common.all')) ?></ui5-option><?= Ui5::enumOptions(Direction::cases(), 'direction', $v('direction')) ?></ui5-select>
            </ui5-form-item>
            <?php if ($canGlobal): ?>
                <ui5-form-item>
                    <ui5-checkbox id="all_sites" name="all_sites" value="1" text="<?= e(__('retention.all_sites')) ?>" <?= $v('all_sites') === '1' ? 'checked' : '' ?>></ui5-checkbox>
                </ui5-form-item>
            <?php endif; ?>
        </ui5-form>
    </form>
    <div slot="footer" class="lt-dialog-footer">
        <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('retention.save')) ?></ui5-button>
        <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('list.cancel')) ?></ui5-button>
    </div>
</ui5-dialog>
