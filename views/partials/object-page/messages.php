<?php
/**
 * Object Page: validation messages (equivalent of the Fiori MessagePopover).
 * Goes in the footer bar: a button showing the number of errors, and the popover listing
 * them. A click on a message closes the popover and focuses the field (pages/object-page.js).
 *
 * @var App\Core\View $this
 * @var array<string, list<string>> $errors field => messages
 * @var array<string, string> $labels field => translated label (also defines the order)
 * @var string $slot slot of the button in the footer bar
 */
$slot ??= 'startContent';
$count = array_sum(array_map('count', $errors));
if ($count === 0) {
    return;
}
// Fields in the order of the form, then anything the form does not show.
$ordered = array_merge(array_intersect_key($labels, $errors), array_diff_key(array_fill_keys(array_keys($errors), null), $labels));
?>
<ui5-button slot="<?= e($slot) ?>" id="lt-messages-button" design="Negative" icon="message-error" data-lt-messages-button
            accessible-name="<?= e(__('object.messages_count', ['count' => $count])) ?>"
            tooltip="<?= e(__('object.messages_count', ['count' => $count])) ?>"><?= e($count) ?></ui5-button>
<ui5-popover slot="<?= e($slot) ?>" id="lt-messages" data-lt-messages opener="lt-messages-button" placement="Top"
             header-text="<?= e(__('object.messages_title')) ?>" accessible-name="<?= e(__('object.messages_title')) ?>">
    <ui5-list separators="Inner" accessible-name="<?= e(__('object.messages_title')) ?>">
        <?php foreach ($ordered as $field => $label): ?>
            <?php foreach ($errors[$field] as $message): ?>
                <ui5-li type="Active" icon="error" data-lt-focus="<?= e($field) ?>" highlight="Negative"
                        description="<?= e($message) ?>"><?= e($label ?? $field) ?></ui5-li>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </ui5-list>
</ui5-popover>
