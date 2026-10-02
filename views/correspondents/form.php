<?php
/**
 * @floorplan ObjectPage
 *
 * Correspondent: Object Page in creation or edit mode (docs/FIORI_DESIGN.md, "Modèle Object Page").
 *
 * @var App\Core\View $this
 * @var ?App\Domain\Correspondent\Correspondent $correspondent
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
use App\Core\Ui5;
use App\Domain\Correspondent\CorrespondentType;

$this->layout('layouts/main');
$title = $correspondent !== null ? $correspondent->name : __('correspondent.new');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
// Sections: id => [title, fields (name, input type, max length, required, columns spanned)]
$sections = [
    'identity' => [__('object.identity'), [['name', 'Text', 190, true, 2], ['organization', 'Text', 190, false, 1]]],
    'contact' => [__('object.contact'), [['email', 'Email', 190, false, 1], ['phone', 'Tel', 50, false, 1]]],
    'address' => [__('object.address'), [
        ['address_line1', 'Text', 190, false, 2], ['address_line2', 'Text', 190, false, 2],
        ['postal_code', 'Text', 20, false, 1], ['city', 'Text', 100, false, 1], ['country', 'Text', 2, true, 1],
    ]],
    'other' => [__('object.other'), []],
];
$fieldLabels = [];
foreach (['type', 'name', 'organization', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'notes', 'is_active'] as $field) {
    $fieldLabels[$field] = __('correspondent.fields.' . $field);
}
?>
<?php $this->start('title') ?><?= e($title) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="correspondent-page" class="lt-object-page" data-lt-object-page data-editing show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('correspondent.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/correspondents"><?= e(__('correspondent.title')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e($correspondent !== null ? __('object.editing') : __('object.creating')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($title) ?></ui5-title>
        <?php if ($correspondent !== null): ?>
            <div slot="subheading" class="lt-tags">
                <ui5-tag design="Set2" color-scheme="6" hide-state-icon><?= e(__('enums.correspondent_type.' . $correspondent->type->value)) ?></ui5-tag>
                <ui5-tag design="<?= $correspondent->isActive ? 'Positive' : 'Neutral' ?>"><?= e(__($correspondent->isActive ? 'common.yes' : 'common.no')) ?> · <?= e(__('correspondent.fields.is_active')) ?></ui5-tag>
            </div>
        <?php endif; ?>
    </ui5-dynamic-page-title>

    <div class="lt-object-page__content">
        <ui5-tabcontainer class="lt-anchor-bar" collapsed data-lt-anchor-bar accessible-name="<?= e(__('object.sections')) ?>">
            <?php foreach ($sections as $id => [$label]): ?>
                <ui5-tab text="<?= e($label) ?>" data-target="<?= e($id) ?>" <?= $id === 'identity' ? 'selected' : '' ?>></ui5-tab>
            <?php endforeach; ?>
        </ui5-tabcontainer>

        <form id="correspondent-form" method="post" novalidate
              action="<?= e($basePath) ?><?= $correspondent !== null ? '/correspondents/' . e($correspondent->id) : '/correspondents' ?>">
            <?= $csrf->field() ?>
            <?php if ($correspondent !== null): ?>
                <input type="hidden" name="_method" value="PUT">
            <?php endif; ?>

            <?php foreach ($sections as $id => [$label, $fields]): ?>
                <section class="lt-op-section" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title">
                    <ui5-title level="H2" size="H4" id="<?= e($id) ?>-title"><?= e($label) ?></ui5-title>
                    <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e($label) ?>">
                        <?php if ($id === 'identity'): ?>
                            <ui5-form-item>
                                <ui5-label slot="labelContent" for="type" required show-colon><?= e(__('correspondent.fields.type')) ?></ui5-label>
                                <ui5-select id="type" name="type"<?= Ui5::state($errors, 'type') ?>><?= Ui5::enumOptions(CorrespondentType::cases(), 'correspondent_type', $v('type')) ?><?= Ui5::stateMessage($errors, 'type') ?></ui5-select>
                            </ui5-form-item>
                        <?php endif; ?>
                        <?php foreach ($fields as [$name, $type, $max, $required, $span]): ?>
                            <ui5-form-item column-span="<?= e($span) ?>">
                                <ui5-label slot="labelContent" for="<?= e($name) ?>" <?= $required ? 'required' : '' ?> show-colon><?= e(__('correspondent.fields.' . $name)) ?></ui5-label>
                                <ui5-input id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" maxlength="<?= e($max) ?>" <?= $required ? 'required' : '' ?>
                                           value="<?= e($v($name)) ?>"<?= Ui5::state($errors, $name) ?>><?= Ui5::stateMessage($errors, $name) ?></ui5-input>
                            </ui5-form-item>
                        <?php endforeach; ?>
                        <?php if ($id === 'other'): ?>
                            <ui5-form-item column-span="3">
                                <ui5-label slot="labelContent" for="notes" show-colon><?= e(__('correspondent.fields.notes')) ?></ui5-label>
                                <ui5-textarea id="notes" name="notes" maxlength="5000" rows="3" growing growing-max-rows="10"
                                              value="<?= e($v('notes')) ?>"<?= Ui5::state($errors, 'notes') ?>><?= Ui5::stateMessage($errors, 'notes') ?></ui5-textarea>
                            </ui5-form-item>
                            <ui5-form-item>
                                <ui5-checkbox id="is_active" name="is_active" value="1" text="<?= e(__('correspondent.fields.is_active')) ?>" <?= $v('is_active') === '1' ? 'checked' : '' ?>></ui5-checkbox>
                            </ui5-form-item>
                        <?php endif; ?>
                    </ui5-form>
                </section>
            <?php endforeach; ?>
        </form>
    </div>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?= $this->partial('partials/object-page/messages', ['errors' => $errors, 'labels' => $fieldLabels]) ?>
        <ui5-button slot="endContent" design="Emphasized" data-lt-submit="correspondent-form"><?= e(__('common.save')) ?></ui5-button>
        <ui5-button slot="endContent" design="Transparent" data-lt-href="/correspondents"><?= e(__('common.cancel')) ?></ui5-button>
    </ui5-bar>
</ui5-dynamic-page>
