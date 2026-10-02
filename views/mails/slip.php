<?php
/**
 * @floorplan None — bordereau : document imprimable
 *
 * Registration slip ("bordereau d'enregistrement"): printed and attached to the paper mail.
 *
 * @var App\Core\View $this
 * @var App\Domain\Mail\Mail $mail
 * @var ?App\Domain\Correspondent\Correspondent $correspondent
 * @var ?string $department
 * @var ?string $site
 * @var ?string $registeredBy
 * @var int $attachments
 * @var ?string $subject null when masked (secret mail)
 * @var DateTimeImmutable $printedAt
 * @var string $printedBy
 */
use App\Core\Code39;
use App\Domain\Mail\Direction;

$this->layout('layouts/print');
$incoming = $mail->direction === Direction::Incoming;
$address = $correspondent === null ? [] : array_filter([
    $correspondent->organization !== $correspondent->name ? $correspondent->organization : null,
    $correspondent->addressLine1,
    $correspondent->addressLine2,
    trim(($correspondent->postalCode ?? '') . ' ' . ($correspondent->city ?? '')),
]);
?>
<?php $this->start('title') ?><?= e(__('slip.title')) ?> <?= e($mail->reference) ?><?php $this->stop() ?>

<div class="lt-print-toolbar">
    <a class="lt-btn lt-btn--ghost" href="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>"><i data-lucide="arrow-left"></i><?= e(__('common.back')) ?></a>
    <button type="button" class="lt-btn lt-btn--primary" data-lt-print data-lt-print-auto><i data-lucide="printer"></i><?= e(__('slip.print')) ?></button>
</div>

<article class="lt-slip">
    <header class="lt-slip__header">
        <div>
            <p class="lt-slip__org"><?= e($site ?? __('app.name')) ?></p>
            <h1 class="lt-slip__title"><?= e(__($incoming ? 'slip.title_incoming' : 'slip.title_outgoing')) ?></h1>
        </div>
        <div class="lt-slip__ref">
            <span class="lt-slip__ref-label"><?= e(__('mail.fields.reference')) ?></span>
            <strong><?= e($mail->reference) ?></strong>
            <?= Code39::svg($mail->reference, 48) ?>
        </div>
    </header>

    <table class="lt-slip__fields">
        <tbody>
        <tr>
            <th scope="row"><?= e(__($incoming ? 'mail.fields.received_at' : 'mail.fields.sent_at')) ?></th>
            <td><?= e(local_datetime($incoming ? $mail->receivedAt : $mail->sentAt) ?: '—') ?></td>
            <th scope="row"><?= e(__('mail.fields.channel')) ?></th>
            <td><?= e(__('enums.channel.' . $mail->channel->value)) ?></td>
        </tr>
        <tr>
            <th scope="row"><?= e(__('mail.fields.document_date')) ?></th>
            <td><?= e(local_date($mail->documentDate) ?: '—') ?></td>
            <th scope="row"><?= e(__('mail.fields.external_reference')) ?></th>
            <td><?= e($mail->externalReference ?? '—') ?></td>
        </tr>
        <tr>
            <th scope="row"><?= e(__($incoming ? 'slip.sender' : 'slip.recipient')) ?></th>
            <td colspan="3">
                <strong><?= e($correspondent?->name ?? '—') ?></strong>
                <?php foreach ($address as $line): ?><br><?= e($line) ?><?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= e(__('mail.fields.subject')) ?></th>
            <td colspan="3"><?= $subject !== null ? e($subject) : '<em>' . e(__('slip.masked')) . '</em>' ?></td>
        </tr>
        <tr>
            <th scope="row"><?= e(__('mail.fields.department_id')) ?></th>
            <td><?= e($department ?? '—') ?></td>
            <th scope="row"><?= e(__('mail.fields.due_date')) ?></th>
            <td><?= e(local_date($mail->dueDate) ?: '—') ?></td>
        </tr>
        <tr>
            <th scope="row"><?= e(__('mail.fields.priority')) ?></th>
            <td><?= e(__('enums.priority.' . $mail->priority->value)) ?></td>
            <th scope="row"><?= e(__('mail.fields.confidentiality')) ?></th>
            <td><?= e(__('enums.confidentiality.' . $mail->confidentiality->value)) ?></td>
        </tr>
        <tr>
            <th scope="row"><?= e(__('slip.registered')) ?></th>
            <td><?= e(local_datetime($mail->createdAt)) ?><?= $registeredBy !== null ? ' — ' . e($registeredBy) : '' ?></td>
            <th scope="row"><?= e(__('attachment.title')) ?></th>
            <td><?= e($attachments) ?></td>
        </tr>
        </tbody>
    </table>

    <section class="lt-slip__signatures" aria-label="<?= e(__('slip.signatures')) ?>">
        <?php foreach (['received_by', 'transmitted_to', 'processed_by'] as $box): ?>
            <div class="lt-slip__box">
                <span><?= e(__('slip.boxes.' . $box)) ?></span>
                <span class="lt-slip__box-line"><?= e(__('slip.date_signature')) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <footer class="lt-slip__footer">
        <?= e(__('slip.printed', ['date' => local_datetime($printedAt), 'name' => $printedBy])) ?>
    </footer>
</article>
