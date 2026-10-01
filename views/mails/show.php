<?php
/**
 * @var App\Core\View $this
 * @var App\Domain\Mail\Mail $mail
 * @var ?App\Domain\Correspondent\Correspondent $correspondent
 * @var ?string $departmentName
 * @var list<App\Domain\Attachment\Attachment> $attachments
 * @var list<array{at: DateTimeImmutable, action: string, user: string, changes: list<array{field: string, old: string, new: string}>}> $history
 * @var bool $canUpdate
 * @var int $maxUploadMb
 * @var string $accept
 * @var string $today
 * @var list<App\Domain\Mail\MailAction> $actions
 * @var list<App\Domain\Assignment\Assignment> $assignments
 * @var list<App\Domain\Annotation\Annotation> $annotations
 * @var list<array{link_id: int, type: string, side: string, mail_id: int, reference: string, subject: string, status: string, direction: string}> $links
 * @var list<array{id: int, name: string, role: string}> $assignableUsers
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var bool $canAssign
 * @var bool $canAnnotate
 * @var bool $canReply
 * @var bool $canLinkReply
 */
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailAction;

/** Confirmation settings per action (null: no confirmation). */
$confirm = [
    'close' => ['message' => __('workflow.confirm.close'), 'input' => true],
    'reopen' => ['message' => __('workflow.confirm.reopen'), 'input' => true],
    'archive' => ['message' => __('workflow.confirm.archive'), 'danger' => true],
];
$icons = ['start' => 'play', 'await_reply' => 'hourglass', 'close' => 'check-circle', 'reopen' => 'rotate-ccw', 'archive' => 'archive'];
$activeForAction = null;
foreach ($assignments as $a) {
    if ($a->isActive() && $a->role === AssignmentRole::ForAction) {
        $activeForAction = $a;
        break;
    }
}
$userOptions = static function (array $users): string {
    $html = '<option value="">—</option>';
    foreach ($users as $u) {
        $html .= '<option value="' . e($u['id']) . '">' . e($u['name']) . ' (' . e(__('roles.' . $u['role'])) . ')</option>';
    }
    return $html;
};
$departmentOptions = static function (array $departments): string {
    $html = '<option value="">—</option>';
    foreach ($departments as $d) {
        $html .= '<option value="' . e($d['id']) . '">' . e($d['name']) . '</option>';
    }
    return $html;
};

$this->layout('layouts/main');
$badge = static fn (string $enum, string $value): string =>
    '<span class="lt-badge lt-badge--' . e($value) . '">' . e(__("enums.{$enum}.{$value}")) . '</span>';
$size = static function (int $bytes) use ($locale): string {
    $units = $locale === 'en' ? ['B', 'KB', 'MB', 'GB'] : ['o', 'Ko', 'Mo', 'Go'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
};
?>
<?php $this->start('title') ?><?= e($mail->reference) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <div>
        <h1><?= e($mail->reference) ?> — <?= e($mail->subject) ?></h1>
        <p class="lt-muted">
            <?= $badge('direction', $mail->direction->value) ?>
            <?= $badge('status', $mail->status->value) ?>
            <?= $badge('priority', $mail->priority->value) ?>
            <?php $due = $mail->dueStatus($today); ?>
            <?php if ($due !== App\Domain\Deadline\DueStatus::None && $due !== App\Domain\Deadline\DueStatus::Ok): ?>
                <span class="lt-due lt-due--<?= e($due->value) ?>"><?= e(__('deadline.status.' . $due->value, ['date' => local_date($mail->dueDate)])) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="lt-actions">
        <a class="lt-btn lt-btn--ghost" href="<?= e($basePath) ?>/mails"><i data-lucide="arrow-left"></i><?= e(__('common.back')) ?></a>
        <?php if ($canReply): ?>
            <a class="lt-btn" href="<?= e($basePath) ?>/mails/new?reply_to=<?= e($mail->id) ?>"><i data-lucide="reply"></i><?= e(__('link.reply')) ?></a>
        <?php endif; ?>
        <a class="lt-btn" href="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/slip?print=1" target="_blank" rel="noopener"><i data-lucide="printer"></i><?= e(__('slip.button')) ?></a>
        <?php if ($canUpdate): ?>
            <a class="lt-btn lt-btn--primary" href="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/edit"><i data-lucide="pencil"></i><?= e(__('common.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($actions !== []): ?>
    <section class="lt-card lt-workflow" id="workflow" aria-label="<?= e(__('workflow.title')) ?>">
        <?php foreach ($actions as $action): ?>
            <?php if ($action === MailAction::Assign || $action === MailAction::Reassign) continue; ?>
            <?php $c = $confirm[$action->value] ?? null; ?>
            <form method="post" class="lt-inline-form" action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/actions/<?= e($action->value) ?>"
                <?php if ($c !== null): ?>
                    data-lt-confirm="<?= e($c['message']) ?>"
                    data-lt-confirm-title="<?= e(__('enums.action.' . $action->value)) ?>"
                    data-lt-confirm-button="<?= e(__('enums.action.' . $action->value)) ?>"
                    <?= !empty($c['danger']) ? 'data-lt-confirm-danger' : '' ?>
                    <?php if (!empty($c['input'])): ?>
                        data-lt-confirm-input="comment" data-lt-confirm-input-label="<?= e(__('workflow.comment')) ?>"
                    <?php endif; ?>
                <?php endif; ?>>
                <?= $csrf->field() ?>
                <button type="submit" class="lt-btn<?= $action === MailAction::Archive ? ' lt-btn--danger' : '' ?>"><i data-lucide="<?= e($icons[$action->value] ?? 'circle') ?>"></i><?= e(__('enums.action.' . $action->value)) ?></button>
            </form>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<div class="lt-columns">
    <section class="lt-card" aria-labelledby="details-title">
        <h2 id="details-title"><?= e(__('mail.details')) ?></h2>
        <dl class="lt-dl">
            <dt><?= e(__('mail.fields.correspondent_id')) ?></dt>
            <dd><?= e($correspondent?->displayName() ?? '—') ?></dd>
            <?php if ($mail->direction === Direction::Incoming): ?>
                <dt><?= e(__('mail.fields.received_at')) ?></dt>
                <dd><?= e(local_datetime($mail->receivedAt)) ?></dd>
            <?php else: ?>
                <dt><?= e(__('mail.fields.sent_at')) ?></dt>
                <dd><?= e(local_datetime($mail->sentAt) ?: '—') ?></dd>
            <?php endif; ?>
            <dt><?= e(__('mail.fields.document_date')) ?></dt>
            <dd><?= e(local_date($mail->documentDate) ?: '—') ?></dd>
            <dt><?= e(__('mail.fields.due_date')) ?></dt>
            <dd><?= e(local_date($mail->dueDate) ?: '—') ?></dd>
            <dt><?= e(__('mail.fields.channel')) ?></dt>
            <dd><?= e(__('enums.channel.' . $mail->channel->value)) ?></dd>
            <dt><?= e(__('mail.fields.department_id')) ?></dt>
            <dd><?= e($departmentName ?? '—') ?></dd>
            <dt><?= e(__('mail.fields.confidentiality')) ?></dt>
            <dd><?= e(__('enums.confidentiality.' . $mail->confidentiality->value)) ?></dd>
            <dt><?= e(__('mail.fields.external_reference')) ?></dt>
            <dd><?= e($mail->externalReference ?? '—') ?></dd>
            <dt><?= e(__('mail.fields.created')) ?></dt>
            <dd><?= e(local_datetime($mail->createdAt)) ?></dd>
        </dl>
        <?php if ($mail->summary !== null): ?>
            <h3><?= e(__('mail.fields.summary')) ?></h3>
            <p class="lt-prewrap"><?= e($mail->summary) ?></p>
        <?php endif; ?>
    </section>

    <section class="lt-card" id="attachments" aria-labelledby="attachments-title">
        <h2 id="attachments-title"><?= e(__('attachment.title')) ?></h2>
        <?php if ($attachments === []): ?>
            <p class="lt-muted"><?= e(__('attachment.none')) ?></p>
        <?php else: ?>
            <ul class="lt-files">
                <?php foreach ($attachments as $file): ?>
                    <li>
                        <i data-lucide="<?= $file->mimeType === 'application/pdf' ? 'file-text' : 'image' ?>"></i>
                        <span class="lt-files__name"><?= e($file->originalName) ?></span>
                        <span class="lt-muted"><?= e($size($file->sizeBytes)) ?></span>
                        <?php if ($file->isPurged()): ?>
                            <span class="lt-muted" title="SHA-256 <?= e($file->sha256) ?>"><?= e(__('attachment.purged', ['date' => local_date($file->purgedAt?->format('Y-m-d'))])) ?></span>
                        <?php else: ?>
                            <?php if (AttachmentPolicy::isInline($file->mimeType)): ?>
                                <a href="<?= e($basePath) ?>/attachments/<?= e($file->id) ?>?inline=1" target="_blank" rel="noopener"><?= e(__('attachment.view')) ?></a>
                            <?php endif; ?>
                            <a href="<?= e($basePath) ?>/attachments/<?= e($file->id) ?>"><?= e(__('attachment.download')) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($canUpdate): ?>
            <form method="post" enctype="multipart/form-data" class="lt-form lt-upload"
                  action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/attachments">
                <?= $csrf->field() ?>
                <div class="lt-field">
                    <label for="file"><?= e(__('attachment.file')) ?></label>
                    <input type="file" id="file" name="file" accept="<?= e($accept) ?>" required aria-describedby="file-help">
                    <span class="lt-field__help" id="file-help"><?= e(__('attachment.help', ['max' => $maxUploadMb])) ?></span>
                </div>
                <button type="submit"><i data-lucide="upload"></i><?= e(__('attachment.upload')) ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>

<div class="lt-columns">
    <section class="lt-card" id="assignments" aria-labelledby="assignments-title">
        <h2 id="assignments-title"><?= e(__('assignment.title')) ?></h2>
        <?php if ($assignments === []): ?>
            <p class="lt-muted"><?= e(__('assignment.none')) ?></p>
        <?php else: ?>
            <ul class="lt-list">
                <?php foreach ($assignments as $a): ?>
                    <li class="<?= $a->isActive() ? '' : 'lt-list__ended' ?>">
                        <div>
                            <strong><?= e($a->userName ?? $a->departmentName ?? '—') ?></strong>
                            <?php if ($a->userName !== null && $a->departmentName !== null): ?><span class="lt-muted">· <?= e($a->departmentName) ?></span><?php endif; ?>
                            <span class="lt-badge lt-badge--<?= e($a->role->value) ?>"><?= e(__('enums.assignment_role.' . $a->role->value)) ?></span>
                            <?php if (!$a->isActive()): ?><span class="lt-badge"><?= e(__('enums.assignment_status.' . $a->status->value)) ?></span><?php endif; ?>
                        </div>
                        <?php if ($a->delegatedFromName !== null): ?>
                            <div class="lt-muted"><i data-lucide="user-round-check"></i> <?= e(__('assignment.delegated_from', ['name' => $a->delegatedFromName])) ?></div>
                        <?php endif; ?>
                        <?php if ($a->instructions !== null): ?><p class="lt-prewrap"><?= e($a->instructions) ?></p><?php endif; ?>
                        <div class="lt-muted">
                            <?= e(__('assignment.by', ['name' => $a->assignedByName ?? '—', 'date' => local_datetime($a->createdAt)])) ?>
                            <?php if ($a->dueDate !== null): ?> · <?= e(__('mail.fields.due_date')) ?> <?= e(local_date($a->dueDate)) ?><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($canAssign && in_array(MailAction::Assign, $actions, true)): ?>
            <details class="lt-details"<?= $assignments === [] ? ' open' : '' ?>>
                <summary><?= e(__('assignment.assign')) ?></summary>
                <form method="post" class="lt-form" action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/assign">
                    <?= $csrf->field() ?>
                    <div class="lt-field">
                        <label for="as-user"><?= e(__('assignment.fields.user_id')) ?></label>
                        <select id="as-user" name="user_id"><?= $userOptions($assignableUsers) ?></select>
                    </div>
                    <?php if ($departments !== []): ?>
                        <div class="lt-field">
                            <label for="as-dept"><?= e(__('assignment.fields.department_id')) ?></label>
                            <select id="as-dept" name="department_id"><?= $departmentOptions($departments) ?></select>
                        </div>
                    <?php endif; ?>
                    <div class="lt-field">
                        <label for="as-role"><?= e(__('assignment.fields.role')) ?></label>
                        <select id="as-role" name="role">
                            <?php foreach (AssignmentRole::cases() as $role): ?>
                                <?php if ($role === AssignmentRole::ForAction && $activeForAction !== null) continue; ?>
                                <option value="<?= e($role->value) ?>"><?= e(__('enums.assignment_role.' . $role->value)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lt-field">
                        <label for="as-due"><?= e(__('assignment.fields.due_date')) ?></label>
                        <input type="date" id="as-due" name="due_date" min="<?= e($today) ?>" value="<?= e($mail->dueDate ?? '') ?>">
                    </div>
                    <div class="lt-field">
                        <label for="as-instr"><?= e(__('assignment.fields.instructions')) ?></label>
                        <textarea id="as-instr" name="instructions" rows="2" maxlength="2000"></textarea>
                    </div>
                    <button type="submit"><i data-lucide="user-plus"></i><?= e(__('assignment.assign')) ?></button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ($canAssign && $activeForAction !== null && in_array(MailAction::Reassign, $actions, true)): ?>
            <details class="lt-details">
                <summary><?= e(__('assignment.reassign')) ?></summary>
                <form method="post" class="lt-form" action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/reassign"
                      data-lt-confirm="<?= e(__('workflow.confirm.reassign', ['name' => $activeForAction->userName ?? $activeForAction->departmentName ?? ''])) ?>"
                      data-lt-confirm-title="<?= e(__('assignment.reassign')) ?>"
                      data-lt-confirm-button="<?= e(__('assignment.reassign')) ?>"
                      data-lt-confirm-input="comment" data-lt-confirm-input-label="<?= e(__('workflow.reason')) ?>">
                    <?= $csrf->field() ?>
                    <div class="lt-field">
                        <label for="re-user"><?= e(__('assignment.fields.user_id')) ?></label>
                        <select id="re-user" name="user_id"><?= $userOptions($assignableUsers) ?></select>
                    </div>
                    <?php if ($departments !== []): ?>
                        <div class="lt-field">
                            <label for="re-dept"><?= e(__('assignment.fields.department_id')) ?></label>
                            <select id="re-dept" name="department_id"><?= $departmentOptions($departments) ?></select>
                        </div>
                    <?php endif; ?>
                    <div class="lt-field">
                        <label for="re-due"><?= e(__('assignment.fields.due_date')) ?></label>
                        <input type="date" id="re-due" name="due_date" min="<?= e($today) ?>" value="<?= e($activeForAction->dueDate ?? '') ?>">
                    </div>
                    <div class="lt-field">
                        <label for="re-instr"><?= e(__('assignment.fields.instructions')) ?></label>
                        <textarea id="re-instr" name="instructions" rows="2" maxlength="2000"></textarea>
                    </div>
                    <button type="submit"><i data-lucide="repeat"></i><?= e(__('assignment.reassign')) ?></button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <section class="lt-card" id="links" aria-labelledby="links-title">
        <h2 id="links-title"><?= e(__('link.title')) ?></h2>
        <?php if ($links === []): ?>
            <p class="lt-muted"><?= e(__('link.none')) ?></p>
        <?php else: ?>
            <ul class="lt-list">
                <?php foreach ($links as $link): ?>
                    <li>
                        <span class="lt-muted"><?= e(__('link.' . $link['type'] . '.' . $link['side'])) ?></span>
                        <a href="<?= e($basePath) ?>/mails/<?= e($link['mail_id']) ?>"><?= e($link['reference']) ?></a>
                        — <?= e($link['subject']) ?>
                        <span class="lt-badge lt-badge--<?= e($link['status']) ?>"><?= e(__('enums.status.' . $link['status'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($canLinkReply): ?>
            <form method="post" class="lt-form lt-form--inline" action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/reply-link"
                  data-lt-confirm="<?= e(__('workflow.confirm.link_reply')) ?>"
                  data-lt-confirm-title="<?= e(__('link.link_reply')) ?>"
                  data-lt-confirm-button="<?= e(__('link.link_reply')) ?>">
                <?= $csrf->field() ?>
                <div class="lt-field">
                    <label for="link-ref"><?= e(__('link.reference')) ?></label>
                    <input type="text" id="link-ref" name="reference" maxlength="20" placeholder="ENT-<?= e(date('Y')) ?>-00001" required pattern="[Ee][Nn][Tt]-\d{4}-\d{5,}">
                </div>
                <button type="submit"><i data-lucide="link"></i><?= e(__('link.link_reply')) ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="lt-card" id="annotations" aria-labelledby="annotations-title">
    <h2 id="annotations-title"><?= e(__('annotation.title')) ?></h2>
    <?php if ($annotations === []): ?>
        <p class="lt-muted"><?= e(__('annotation.none')) ?></p>
    <?php else: ?>
        <ol class="lt-notes">
            <?php foreach ($annotations as $note): ?>
                <li class="<?= $note->isPrivate ? 'lt-notes__private' : '' ?>">
                    <div class="lt-timeline__head">
                        <strong><?= e($note->userName) ?></strong>
                        <span class="lt-muted"><?= e(local_datetime($note->createdAt)) ?></span>
                        <?php if ($note->isPrivate): ?><span class="lt-badge"><i data-lucide="lock"></i> <?= e(__('annotation.private')) ?></span><?php endif; ?>
                    </div>
                    <p class="lt-prewrap"><?= e($note->body) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($canAnnotate): ?>
        <form method="post" class="lt-form" action="<?= e($basePath) ?>/mails/<?= e($mail->id) ?>/annotations">
            <?= $csrf->field() ?>
            <div class="lt-field">
                <label for="note-body"><?= e(__('annotation.body')) ?></label>
                <textarea id="note-body" name="body" rows="3" maxlength="5000" required></textarea>
            </div>
            <label class="lt-check"><input type="checkbox" name="is_private" value="1"> <?= e(__('annotation.private_help')) ?></label>
            <button type="submit"><i data-lucide="message-square-plus"></i><?= e(__('annotation.add')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="lt-card" aria-labelledby="history-title">
    <h2 id="history-title"><?= e(__('mail.history')) ?></h2>
    <?php if ($history === []): ?>
        <p class="lt-muted"><?= e(__('mail.history_empty')) ?></p>
    <?php else: ?>
        <ol class="lt-timeline">
            <?php foreach (array_reverse($history) as $entry): ?>
                <li>
                    <div class="lt-timeline__head">
                        <strong><?= e($entry['action']) ?></strong>
                        <span class="lt-muted"><?= e($entry['user']) ?> · <?= e(local_datetime($entry['at'])) ?></span>
                    </div>
                    <?php if ($entry['changes'] !== []): ?>
                        <table class="lt-table lt-table--compact">
                            <tbody>
                            <?php foreach ($entry['changes'] as $change): ?>
                                <tr>
                                    <th scope="row"><?= e($change['field']) ?></th>
                                    <td><del><?= e($change['old']) ?></del></td>
                                    <td><ins><?= e($change['new']) ?></ins></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
