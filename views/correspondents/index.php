<?php
/**
 * @floorplan ListReport
 *
 * Correspondents directory, built from the List Report template (docs/FIORI_DESIGN.md §12).
 *
 * @var App\Core\View $this
 */
$this->layout('layouts/main');
// name, label, renderer, sortable, required (cannot be hidden)
$columns = [
    ['name', 'correspondent.fields.name', 'strong', true, true],
    ['type', 'correspondent.fields.type', 'tag:correspondent_type', true, false],
    ['organization', 'correspondent.fields.organization', 'text', true, false],
    ['email', 'correspondent.fields.email', 'text', true, false],
    ['city', 'correspondent.fields.city', 'text', true, false],
    ['mails_count', 'correspondent.fields.mails_count', 'number', true, false],
];
?>
<?php $this->start('title') ?><?= e(__('correspondent.title')) ?><?php $this->stop() ?>
<?= $this->partial('partials/list-report/assets') ?>

<ui5-dynamic-page id="correspondents-report" class="lt-list-report" data-lt-list-report
    data-key="correspondents" data-url="/correspondents/data" data-row-href="/correspondents/{id}/edit" data-row-label="name"
    data-sort="name" data-dir="asc" data-per-page="25"
    data-title="<?= e(__('correspondent.title')) ?>">

    <?= $this->partial('partials/list-report/title', ['title' => __('correspondent.title')]) ?>

    <ui5-dynamic-page-header slot="headerArea" accessible-name="<?= e(__('list.filters')) ?>">
        <div class="lt-filter-bar" role="search" aria-label="<?= e(__('list.filters')) ?>">
            <div class="lt-filter-bar__field lt-filter-bar__field--wide">
                <ui5-label for="f-search" show-colon><?= e(__('list.search')) ?></ui5-label>
                <ui5-input id="f-search" data-lt-search show-clear-icon placeholder="<?= e(__('correspondent.search_placeholder')) ?>">
                    <ui5-icon slot="icon" name="search"></ui5-icon>
                </ui5-input>
            </div>
            <div class="lt-filter-bar__field lt-filter-bar__field--checks">
                <ui5-checkbox data-lt-filter="inactive" text="<?= e(__('correspondent.show_inactive')) ?>"></ui5-checkbox>
            </div>
            <div class="lt-filter-bar__actions">
                <ui5-button design="Emphasized" data-lt-go><?= e(__('list.go')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-reset><?= e(__('list.reset')) ?></ui5-button>
            </div>
        </div>
    </ui5-dynamic-page-header>

    <div class="lt-list-report__content">
        <div class="lt-table-header">
            <ui5-title level="H2" size="H5" data-lt-count><?= e(__('correspondent.title')) ?></ui5-title>
            <ui5-toolbar class="lt-table-header__toolbar" align-content="End" design="Transparent" accessible-name="<?= e(__('list.toolbar')) ?>">
                <ui5-toolbar-button icon="add" text="<?= e(__('list.create')) ?>" data-lt-href="/correspondents/new"
                                    tooltip="<?= e(__('correspondent.new')) ?>" accessible-name="<?= e(__('correspondent.new')) ?>"></ui5-toolbar-button>
                <ui5-toolbar-separator></ui5-toolbar-separator>
                <?= $this->partial('partials/list-report/toolbar-end') ?>
            </ui5-toolbar>
        </div>

        <?= $this->partial('partials/list-report/table', ['label' => __('correspondent.title'), 'columns' => $columns]) ?>
    </div>
</ui5-dynamic-page>

<?= $this->partial('partials/list-report/dialogs', ['id' => 'correspondents']) ?>
