<?php
/**
 * @floorplan ListReport
 *
 * Mail register: the registered mail of one direction over a period, in registration order,
 * exported to Excel or PDF. Built from the List Report template (docs/FIORI_DESIGN.md §12):
 * the table reads the mail list, the exports use the register document (/register/data).
 *
 * @var App\Core\View $this
 * @var string $from
 * @var string $to
 */
use App\Domain\Mail\Direction;

$this->layout('layouts/main');
// name, label, renderer, sortable, required (cannot be hidden)
$columns = [
    ['reference', 'mail.fields.reference', 'strong', true, true],
    ['mail_date', 'mail.fields.mail_date', 'datetime', true, false],
    ['subject', 'mail.fields.subject', 'text', true, false],
    ['correspondent_name', 'mail.fields.correspondent_name', 'text', true, false],
    ['department_name', 'mail.fields.department_id', 'text', false, false],
    ['priority', 'mail.fields.priority', 'tag:priority', true, false],
    ['status', 'mail.fields.status', 'tag:status', true, false],
];
$defaults = ['direction' => Direction::Incoming->value, 'date_from' => $from, 'date_to' => $to];
?>
<?php $this->start('title') ?><?= e(__('register.title')) ?><?php $this->stop() ?>
<?= $this->partial('partials/list-report/assets') ?>

<ui5-dynamic-page id="register-report" class="lt-list-report" data-lt-list-report
    data-key="register" data-url="/mails/data" data-row-href="/mails/{id}" data-row-label="reference"
    data-sort="reference" data-dir="asc" data-per-page="50"
    data-title="<?= e(__('register.title')) ?>"
    data-default-filters="<?= e(json_encode($defaults, JSON_THROW_ON_ERROR)) ?>"
    data-export-source="/register/data" data-export-set="register_{direction}" data-export-map="direction:direction,date_from:from,date_to:to"
    data-export-title="<?= e(__('register.document_title')) ?>" data-export-subtitle="<?= e(__('register.document_subtitle')) ?>">

    <?= $this->partial('partials/list-report/title', ['title' => __('register.title')]) ?>

    <ui5-dynamic-page-header slot="headerArea" accessible-name="<?= e(__('list.filters')) ?>">
        <div class="lt-filter-bar" role="search" aria-label="<?= e(__('list.filters')) ?>">
            <div class="lt-filter-bar__field">
                <ui5-label for="f-direction" required show-colon><?= e(__('mail.fields.direction')) ?></ui5-label>
                <ui5-select id="f-direction" data-lt-filter="direction">
                    <?php foreach (Direction::cases() as $direction): ?>
                        <ui5-option value="<?= e($direction->value) ?>"><?= e(__('enums.direction.' . $direction->value)) ?></ui5-option>
                    <?php endforeach; ?>
                </ui5-select>
            </div>
            <div class="lt-filter-bar__field">
                <ui5-label for="f-from" required show-colon><?= e(__('stats.from')) ?></ui5-label>
                <ui5-date-picker id="f-from" data-lt-filter="date_from" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"></ui5-date-picker>
            </div>
            <div class="lt-filter-bar__field">
                <ui5-label for="f-to" required show-colon><?= e(__('stats.to')) ?></ui5-label>
                <ui5-date-picker id="f-to" data-lt-filter="date_to" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"></ui5-date-picker>
            </div>
            <div class="lt-filter-bar__actions">
                <ui5-button design="Emphasized" data-lt-go><?= e(__('list.go')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-reset><?= e(__('list.reset')) ?></ui5-button>
            </div>
        </div>
    </ui5-dynamic-page-header>

    <div class="lt-list-report__content">
        <ui5-message-strip class="lt-list-report__result" design="Information" hide-close-button><?= e(__('register.help')) ?></ui5-message-strip>

        <div class="lt-table-header">
            <ui5-title level="H2" size="H5" data-lt-count><?= e(__('register.title')) ?></ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <?= $this->partial('partials/list-report/toolbar-end', ['export' => true]) ?>
            </ui5-toolbar>
        </div>

        <?= $this->partial('partials/list-report/table', ['label' => __('register.title'), 'columns' => $columns]) ?>
    </div>
</ui5-dynamic-page>

<?= $this->partial('partials/list-report/dialogs', ['id' => 'register']) ?>
