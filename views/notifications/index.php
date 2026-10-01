<?php
/**
 * @var App\Core\View $this
 * @var list<App\Domain\Notification\Notification> $notifications
 */
$this->layout('layouts/main');
$message = static function (App\Domain\Notification\Notification $n): string {
    $d = $n->data;
    return __('notification.types.' . $n->type->value, [
        'reference' => (string) ($d['reference'] ?? ''),
        'subject' => (string) ($d['subject'] ?? ''),
        'due_date' => local_date(isset($d['due_date']) ? (string) $d['due_date'] : null),
        'by' => (string) ($d['by'] ?? ''),
        'rule' => (string) ($d['rule'] ?? ''),
        'months' => (string) ($d['months'] ?? ''),
    ]);
};
$hasUnread = array_filter($notifications, static fn ($n) => !$n->isRead()) !== [];
?>
<?php $this->start('title') ?><?= e(__('notification.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('notification.title')) ?></h1>
    <?php if ($hasUnread): ?>
        <form method="post" action="<?= e($basePath) ?>/notifications/read-all" class="lt-inline-form">
            <?= $csrf->field() ?>
            <button type="submit" class="lt-btn"><i data-lucide="check-check"></i><?= e(__('notification.mark_all')) ?></button>
        </form>
    <?php endif; ?>
</div>

<section class="lt-card">
    <?php if ($notifications === []): ?>
        <p class="lt-muted"><?= e(__('notification.none')) ?></p>
    <?php else: ?>
        <ul class="lt-list lt-notifications">
            <?php foreach ($notifications as $n): ?>
                <li class="<?= $n->isRead() ? 'is-read' : 'is-unread' ?> lt-notification--<?= e($n->type->value) ?>">
                    <form method="post" action="<?= e($basePath) ?>/notifications/<?= e($n->id) ?>/read">
                        <?= $csrf->field() ?>
                        <button type="submit" class="lt-notification">
                            <i data-lucide="<?= e($n->type->icon()) ?>"></i>
                            <span class="lt-notification__text">
                                <?php if (!$n->isRead()): ?><span class="lt-sr-only"><?= e(__('notification.unread')) ?> :</span><?php endif; ?>
                                <?= e($message($n)) ?>
                                <?php if (!empty($n->data['delegated_from'])): ?>
                                    <span class="lt-muted">(<?= e(__('assignment.delegated_from', ['name' => (string) $n->data['delegated_from']])) ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span class="lt-muted lt-notification__date"><?= e(local_datetime($n->createdAt)) ?></span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
