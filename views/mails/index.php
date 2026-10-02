<?php
/**
 * @floorplan ListReport
 *
 * Mail list. The behaviour (loading, filters, variants, sort, columns, selection, export)
 * comes from pages/list-report.js, configured by the data-lt-* attributes below.
 *
 * @var App\Core\View $this
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var list<array{id: int, name: string, role: string}> $assignableUsers
 * @var bool $canCreate
 * @var bool $canAssign
 * @var bool $canClose
 */
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;

$this->layout('layouts/main');
$enumFilters = [
    'direction' => [Direction::cases(), 'mail.fields.direction'],
    'status' => [MailStatus::cases(), 'mail.fields.status'],
    'priority' => [Priority::cases(), 'mail.fields.priority'],
];
// name, label, renderer, sortable, required (cannot be hidden)
$columns = [
    ['reference', 'mail.fields.reference', 'strong', true, true],
    ['direction', 'mail.fields.direction', 'tag:direction', true, false],
    ['subject', 'mail.fields.subject', 'text', true, false],
    ['correspondent_name', 'mail.fields.correspondent_name', 'text', true, false],
    ['mail_date', 'mail.fields.mail_date', 'datetime', true, false],
    ['due_date', 'mail.fields.due_date', 'due', true, false],
    ['priority', 'mail.fields.priority', 'tag:priority', true, false],
    ['status', 'mail.fields.status', 'tag:status', true, false],
    ['attachments_count', 'mail.fields.attachments_count', 'number', false, false],
];
?>
<?php $this->start('title') ?><?= e(__('mail.title')) ?><?php $this->stop() ?>
<?= $this->partial('partials/list-report/assets') ?>

<ui5-dynamic-page id="mails-report" class="lt-list-report" data-lt-list-report
    data-key="mails" data-url="/mails/data" data-row-href="/mails/{id}" data-row-label="reference"
    data-sort="mail_date" data-dir="desc" data-per-page="25"
    data-title="<?= e(__('list.mails.table_title')) ?>"
    data-export-source="/mails/export" data-export-set="mails" data-export-title="<?= e(__('export.mails_title')) ?>">

    <?= $this->partial('partials/list-report/title', ['title' => __('mail.title')]) ?>

    <ui5-dynamic-page-header slot="headerArea" accessible-name="<?= e(__('list.filters')) ?>">
        <div class="lt-filter-bar" role="search" aria-label="<?= e(__('list.filters')) ?>">
            <div class="lt-filter-bar__field lt-filter-bar__field--wide">
                <ui5-label for="f-search" show-colon><?= e(__('list.search')) ?></ui5-label>
                <ui5-input id="f-search" data-lt-search show-clear-icon placeholder="<?= e(__('js.table.search_placeholder')) ?>">
                    <ui5-icon slot="icon" name="search"></ui5-icon>
                </ui5-input>
            </div>
            <?php foreach ($enumFilters as $name => [$cases, $label]): ?>
                <div class="lt-filter-bar__field">
                    <ui5-label for="f-<?= e($name) ?>" show-colon><?= e(__($label)) ?></ui5-label>
                    <ui5-select id="f-<?= e($name) ?>" data-lt-filter="<?= e($name) ?>">
                        <ui5-option value=""><?= e(__('common.all')) ?></ui5-option>
                        <?php foreach ($cases as $case): ?>
                            <ui5-option value="<?= e($case->value) ?>"><?= e(__('enums.' . $name . '.' . $case->value)) ?></ui5-option>
                        <?php endforeach; ?>
                    </ui5-select>
                </div>
            <?php endforeach; ?>
            <?php if ($departments !== []): ?>
                <div class="lt-filter-bar__field">
                    <ui5-label for="f-department" show-colon><?= e(__('mail.fields.department_id')) ?></ui5-label>
                    <ui5-select id="f-department" data-lt-filter="department_id">
                        <ui5-option value=""><?= e(__('common.all')) ?></ui5-option>
                        <?php foreach ($departments as $department): ?>
                            <ui5-option value="<?= e($department['id']) ?>"><?= e($department['name']) ?></ui5-option>
                        <?php endforeach; ?>
                    </ui5-select>
                </div>
            <?php endif; ?>
            <div class="lt-filter-bar__field">
                <ui5-label for="f-from" show-colon><?= e(__('mail.filters.date_from')) ?></ui5-label>
                <ui5-date-picker id="f-from" data-lt-filter="date_from" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"></ui5-date-picker>
            </div>
            <div class="lt-filter-bar__field">
                <ui5-label for="f-to" show-colon><?= e(__('mail.filters.date_to')) ?></ui5-label>
                <ui5-date-picker id="f-to" data-lt-filter="date_to" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"></ui5-date-picker>
            </div>
            <div class="lt-filter-bar__field lt-filter-bar__field--checks">
                <ui5-checkbox data-lt-filter="mine" text="<?= e(__('mail.filters.mine')) ?>"></ui5-checkbox>
                <ui5-checkbox data-lt-filter="overdue" text="<?= e(__('mail.filters.overdue')) ?>"></ui5-checkbox>
            </div>
            <div class="lt-filter-bar__actions">
                <ui5-button design="Emphasized" data-lt-go><?= e(__('list.go')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-reset><?= e(__('list.reset')) ?></ui5-button>
            </div>
        </div>
    </ui5-dynamic-page-header>

    <div class="lt-list-report__content">
        <ui5-message-strip class="lt-list-report__result" data-lt-result hidden></ui5-message-strip>

        <div class="lt-table-header">
            <ui5-title level="H2" size="H5" data-lt-count><?= e(__('list.mails.table_title')) ?></ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <?php if ($canCreate): ?>
                    <ui5-toolbar-button icon="add" text="<?= e(__('list.mails.incoming')) ?>" data-lt-href="/mails/new?direction=incoming"
                                        tooltip="<?= e(__('mail.new_incoming')) ?>" accessible-name="<?= e(__('mail.new_incoming')) ?>"></ui5-toolbar-button>
                    <ui5-toolbar-button icon="add" text="<?= e(__('list.mails.outgoing')) ?>" data-lt-href="/mails/new?direction=outgoing"
                                        tooltip="<?= e(__('mail.new_outgoing')) ?>" accessible-name="<?= e(__('mail.new_outgoing')) ?>"></ui5-toolbar-button>
                    <ui5-toolbar-separator></ui5-toolbar-separator>
                <?php endif; ?>
                <?php if ($canAssign): ?>
                    <ui5-toolbar-button icon="employee" text="<?= e(__('list.mails.assign')) ?>" data-lt-bulk="assign" data-dialog="mails-assign" disabled></ui5-toolbar-button>
                <?php endif; ?>
                <?php if ($canClose): ?>
                    <ui5-toolbar-button icon="accept" text="<?= e(__('list.mails.close')) ?>" data-lt-bulk="close" data-dialog="mails-close" disabled></ui5-toolbar-button>
                <?php endif; ?>
                <ui5-toolbar-separator></ui5-toolbar-separator>
                <?= $this->partial('partials/list-report/toolbar-end', ['export' => true]) ?>
            </ui5-toolbar>
        </div>

        <?= $this->partial('partials/list-report/table', ['label' => __('list.mails.table_title'), 'columns' => $columns, 'selectable' => true]) ?>
    </div>
</ui5-dynamic-page>

<?= $this->partial('partials/list-report/dialogs', ['id' => 'mails']) ?>

<?php if ($canAssign): ?>
    <ui5-dialog id="mails-assign" data-lt-bulk-dialog data-url="/mails/bulk/assign" header-text="<?= e(__('list.mails.assign_title')) ?>">
        <div class="lt-dialog-form">
            <ui5-message-strip design="Information" hide-close-button><?= e(__('list.mails.assign_help')) ?></ui5-message-strip>
            <ui5-label for="assign-user" show-colon><?= e(__('assignment.fields.user_id')) ?></ui5-label>
            <ui5-select id="assign-user" data-lt-field="user_id">
                <ui5-option value=""><?= e(__('list.mails.none')) ?></ui5-option>
                <?php foreach ($assignableUsers as $user): ?>
                    <ui5-option value="<?= e($user['id']) ?>"><?= e($user['name']) ?> — <?= e(__('roles.' . $user['role'])) ?></ui5-option>
                <?php endforeach; ?>
            </ui5-select>
            <ui5-label for="assign-department" show-colon><?= e(__('assignment.fields.department_id')) ?></ui5-label>
            <ui5-select id="assign-department" data-lt-field="department_id">
                <ui5-option value=""><?= e(__('list.mails.none')) ?></ui5-option>
                <?php foreach ($departments as $department): ?>
                    <ui5-option value="<?= e($department['id']) ?>"><?= e($department['name']) ?></ui5-option>
                <?php endforeach; ?>
            </ui5-select>
            <ui5-label for="assign-role" required show-colon><?= e(__('assignment.fields.role')) ?></ui5-label>
            <ui5-select id="assign-role" data-lt-field="role">
                <?php foreach (AssignmentRole::cases() as $role): ?>
                    <ui5-option value="<?= e($role->value) ?>"><?= e(__('enums.assignment_role.' . $role->value)) ?></ui5-option>
                <?php endforeach; ?>
            </ui5-select>
            <ui5-label for="assign-instructions" show-colon><?= e(__('assignment.fields.instructions')) ?></ui5-label>
            <ui5-textarea id="assign-instructions" data-lt-field="instructions" maxlength="2000" rows="3"></ui5-textarea>
            <ui5-message-strip design="Negative" hide-close-button data-lt-dialog-error hidden></ui5-message-strip>
        </div>
        <div slot="footer" class="lt-dialog-footer">
            <ui5-button design="Emphasized" data-lt-dialog-confirm data-label="js.list.assign_confirm"><?= e(__('list.mails.assign')) ?></ui5-button>
            <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('list.cancel')) ?></ui5-button>
        </div>
    </ui5-dialog>
<?php endif; ?>

<?php if ($canClose): ?>
    <ui5-dialog id="mails-close" data-lt-bulk-dialog data-url="/mails/bulk/close" state="Critical" initial-focus="mails-close-cancel" header-text="<?= e(__('list.mails.close_title')) ?>">
        <div class="lt-dialog-form">
            <ui5-text data-lt-dialog-question data-label="js.list.close_confirm"></ui5-text>
            <ui5-text><?= e(__('list.mails.close_help')) ?></ui5-text>
            <ui5-label for="close-comment" show-colon><?= e(__('workflow.comment')) ?></ui5-label>
            <ui5-textarea id="close-comment" data-lt-field="comment" maxlength="1000" rows="3"></ui5-textarea>
            <ui5-message-strip design="Negative" hide-close-button data-lt-dialog-error hidden></ui5-message-strip>
        </div>
        <div slot="footer" class="lt-dialog-footer">
            <?php /* Initial focus on Cancel: closing is not undone from the list (docs/FIORI_DESIGN.md §5). */ ?>
            <ui5-button design="Emphasized" data-lt-dialog-confirm><?= e(__('list.mails.close')) ?></ui5-button>
            <ui5-button id="mails-close-cancel" design="Transparent" data-lt-dialog-cancel><?= e(__('list.cancel')) ?></ui5-button>
        </div>
    </ui5-dialog>
<?php endif; ?>
