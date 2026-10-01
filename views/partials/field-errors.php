<?php /** @var list<string> $messages @var string $field */ ?>
<?php foreach ($messages as $message): ?>
    <span class="lt-field__error" id="<?= e($field) ?>-error"><?= e($message) ?></span>
<?php endforeach; ?>
