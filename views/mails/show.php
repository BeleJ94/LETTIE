<?php
/**
 * @floorplan ObjectPage
 *
 * Mail page: display by default, edit mode on /mails/{id}/edit (same page, general section editable).
 * Behaviour in pages/object-page.js, configured by data-lt-* attributes
 * (docs/FIORI_DESIGN.md, "Modèle Object Page").
 *
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
 * @var bool $editing
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var string $correspondentLabel
 */
use App\Core\Ui5;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Deadline\DueStatus;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailAction;

$this->layout('layouts/main');
$url = $basePath . '/mails/' . $mail->id;

/** Confirmation per workflow action (none: the action is immediate). */
$confirm = [
    'close' => ['message' => __('workflow.confirm.close'), 'input' => __('workflow.comment')],
    'reopen' => ['message' => __('workflow.confirm.reopen'), 'input' => __('workflow.comment')],
    'archive' => ['message' => __('workflow.confirm.archive'), 'state' => 'Negative'],
];
$actionIcons = ['start' => 'begin', 'await_reply' => 'pending', 'close' => 'accept', 'reopen' => 'undo', 'archive' => 'folder-full'];
$footerActions = array_values(array_filter($actions, static fn (MailAction $a): bool => $a !== MailAction::Assign && $a !== MailAction::Reassign));

$activeForAction = null;
foreach ($assignments as $a) {
    if ($a->isActive() && $a->role === AssignmentRole::ForAction) {
        $activeForAction = $a;
        break;
    }
}
$canAssignNow = !$editing && $canAssign && in_array(MailAction::Assign, $actions, true);
$canReassignNow = !$editing && $canAssign && $activeForAction !== null && in_array(MailAction::Reassign, $actions, true);
$userOptions = ['' => __('list.mails.none')];
foreach ($assignableUsers as $u) {
    $userOptions[$u['id']] = $u['name'] . ' — ' . __('roles.' . $u['role']);
}
$departmentOptions = ['' => __('list.mails.none')] + array_column($departments, 'name', 'id');
$roleOptions = [];
foreach (AssignmentRole::cases() as $role) {
    if (!($role === AssignmentRole::ForAction && $activeForAction !== null)) {
        $roleOptions[$role->value] = __('enums.assignment_role.' . $role->value);
    }
}
$due = $mail->dueStatus($today);
$fieldLabels = [];
foreach (['subject', 'correspondent_id', 'received_at', 'sent_at', 'document_date', 'due_date', 'channel', 'department_id', 'priority', 'confidentiality', 'external_reference', 'summary'] as $field) {
    $fieldLabels[$field] = __('mail.fields.' . $field);
}
$sections = [
    'general' => __('object.general'),
    'assignments' => __('assignment.title'),
    'annotations' => __('annotation.title'),
    'attachments' => __('attachment.title'),
    'links' => __('link.title'),
    'history' => __('mail.history'),
];
$counts = ['assignments' => count($assignments), 'annotations' => count($annotations), 'attachments' => count($attachments), 'links' => count($links)];
$readItem = static fn (string $label, string $value): string =>
    '<ui5-form-item><ui5-label slot="labelContent" show-colon>' . e($label) . '</ui5-label><ui5-text>' . e($value !== '' ? $value : '—') . '</ui5-text></ui5-form-item>';
?>
<?php $this->start('title') ?><?= e($mail->reference) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="mail-page" class="lt-object-page" data-lt-object-page <?= $editing ? 'data-editing' : '' ?>
    <?= ($editing || $footerActions !== []) ? 'show-footer' : '' ?>>

    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('mail.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/mails"><?= e(__('mail.title')) ?></ui5-breadcrumbs-item>
            <?php if ($editing): ?>
                <ui5-breadcrumbs-item href="<?= e($url) ?>"><?= e($mail->reference) ?></ui5-breadcrumbs-item>
                <ui5-breadcrumbs-item><?= e(__('object.editing')) ?></ui5-breadcrumbs-item>
            <?php else: ?>
                <ui5-breadcrumbs-item><?= e($mail->reference) ?></ui5-breadcrumbs-item>
            <?php endif; ?>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($mail->reference) ?> — <?= e($mail->subject) ?></ui5-title>
        <ui5-title slot="snappedHeading" level="H1" size="H5"><?= e($mail->reference) ?> — <?= e($mail->subject) ?></ui5-title>
        <div slot="subheading" class="lt-tags">
            <?= Ui5::tag('direction', $mail->direction->value) ?>
            <?= Ui5::tag('status', $mail->status->value) ?>
            <?= Ui5::tag('priority', $mail->priority->value) ?>
        </div>
        <div slot="snappedSubheading" class="lt-tags">
            <?= Ui5::tag('status', $mail->status->value) ?>
        </div>
        <?php if (!$editing): ?>
            <ui5-toolbar slot="actionsBar" design="Transparent" accessible-name="<?= e(__('object.title_actions')) ?>">
                <?php if ($canUpdate): ?>
                    <ui5-toolbar-button icon="edit" text="<?= e(__('common.edit')) ?>" data-lt-href="/mails/<?= e($mail->id) ?>/edit"></ui5-toolbar-button>
                <?php endif; ?>
                <?php if ($canReply): ?>
                    <ui5-toolbar-button icon="response" text="<?= e(__('link.reply')) ?>" data-lt-href="/mails/new?reply_to=<?= e($mail->id) ?>"></ui5-toolbar-button>
                <?php endif; ?>
                <ui5-toolbar-button icon="print" text="<?= e(__('slip.button')) ?>" data-lt-open="/mails/<?= e($mail->id) ?>/slip?print=1"
                                    tooltip="<?= e(__('object.open_slip')) ?>"></ui5-toolbar-button>
            </ui5-toolbar>
        <?php endif; ?>
    </ui5-dynamic-page-title>

    <ui5-dynamic-page-header slot="headerArea" accessible-name="<?= e(__('object.kpis')) ?>">
        <div class="lt-kpis">
            <div class="lt-kpi" data-kpi="due">
                <ui5-label><?= e(__('mail.fields.due_date')) ?></ui5-label>
                <?php if ($mail->dueDate === null): ?>
                    <ui5-title level="H2" size="H5"><?= e(__('object.no_due_date')) ?></ui5-title>
                <?php else: ?>
                    <ui5-title level="H2" size="H5"><?= e(local_date($mail->dueDate)) ?></ui5-title>
                    <?php if (isset(Ui5::DUE_DESIGNS[$due->value])): ?>
                        <ui5-tag design="<?= e(Ui5::DUE_DESIGNS[$due->value]) ?>"><?= e(__('deadline.status.' . $due->value, ['date' => local_date($mail->dueDate)])) ?></ui5-tag>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="lt-kpi" data-kpi="owner">
                <ui5-label><?= e(__('object.owner')) ?></ui5-label>
                <ui5-title level="H2" size="H5"><?= e($activeForAction?->userName ?? $activeForAction?->departmentName ?? __('object.unassigned')) ?></ui5-title>
                <?php if ($activeForAction === null && $mail->status === App\Domain\Mail\MailStatus::Registered && $mail->direction === Direction::Incoming): ?>
                    <ui5-tag design="Critical"><?= e(__('launchpad.action_needed')) ?></ui5-tag>
                <?php endif; ?>
            </div>
            <div class="lt-kpi" data-kpi="correspondent">
                <ui5-label><?= e(__('mail.fields.correspondent_id')) ?></ui5-label>
                <ui5-title level="H2" size="H5" wrapping-type="Normal"><?= e($correspondent?->displayName() ?? '—') ?></ui5-title>
            </div>
            <div class="lt-kpi" data-kpi="attachments">
                <ui5-label><?= e(__('attachment.title')) ?></ui5-label>
                <ui5-title level="H2" size="H5"><?= e(count($attachments)) ?></ui5-title>
            </div>
        </div>
    </ui5-dynamic-page-header>

    <div class="lt-object-page__content">
        <?php /* Anchor bar: one tab per section (the tabs have no content of their own). */ ?>
        <ui5-tabcontainer class="lt-anchor-bar" collapsed data-lt-anchor-bar accessible-name="<?= e(__('object.sections')) ?>">
            <?php foreach ($sections as $id => $label): ?>
                <ui5-tab text="<?= e($label . (!empty($counts[$id]) ? ' (' . $counts[$id] . ')' : '')) ?>" data-target="<?= e($id) ?>"
                    <?= $id === 'general' ? 'selected' : '' ?>></ui5-tab>
            <?php endforeach; ?>
        </ui5-tabcontainer>

        <section class="lt-op-section" id="general" aria-labelledby="general-title">
            <ui5-title level="H2" size="H4" id="general-title"><?= e($sections['general']) ?></ui5-title>
            <?php if ($editing): ?>
                <form id="mail-form" method="post" action="<?= e($url) ?>" novalidate>
                    <?= $csrf->field() ?>
                    <input type="hidden" name="_method" value="PUT">
                    <?= $this->partial('mails/_fields', [
                        'direction' => $mail->direction, 'values' => $values, 'errors' => $errors,
                        'correspondentLabel' => $correspondentLabel, 'departments' => $departments, 'isNew' => false,
                    ]) ?>
                </form>
            <?php else: ?>
                <ui5-form layout="S1 M2 L3 XL4" label-span="S12 M12 L12 XL12" item-spacing="Normal" accessible-name="<?= e($sections['general']) ?>">
                    <?= $readItem(__('mail.fields.correspondent_id'), $correspondent?->displayName() ?? '') ?>
                    <?= $mail->direction === Direction::Incoming
                        ? $readItem(__('mail.fields.received_at'), local_datetime($mail->receivedAt))
                        : $readItem(__('mail.fields.sent_at'), local_datetime($mail->sentAt)) ?>
                    <?= $readItem(__('mail.fields.document_date'), local_date($mail->documentDate)) ?>
                    <?= $readItem(__('mail.fields.due_date'), local_date($mail->dueDate)) ?>
                    <?= $readItem(__('mail.fields.channel'), __('enums.channel.' . $mail->channel->value)) ?>
                    <?= $readItem(__('mail.fields.department_id'), $departmentName ?? '') ?>
                    <?= $readItem(__('mail.fields.confidentiality'), __('enums.confidentiality.' . $mail->confidentiality->value)) ?>
                    <?= $readItem(__('mail.fields.external_reference'), $mail->externalReference ?? '') ?>
                    <?= $readItem(__('mail.fields.created'), local_datetime($mail->createdAt)) ?>
                    <?php if ($mail->summary !== null): ?>
                        <ui5-form-item column-span="4">
                            <ui5-label slot="labelContent" show-colon><?= e(__('mail.fields.summary')) ?></ui5-label>
                            <ui5-text class="lt-prewrap"><?= e($mail->summary) ?></ui5-text>
                        </ui5-form-item>
                    <?php endif; ?>
                </ui5-form>
            <?php endif; ?>
        </section>

        <section class="lt-op-section" id="assignments" aria-labelledby="assignments-title">
            <div class="lt-op-section__header">
                <ui5-title level="H2" size="H4" id="assignments-title"><?= e($sections['assignments']) ?></ui5-title>
                <?php if ($canAssignNow): ?>
                    <ui5-button design="Transparent" icon="employee" data-lt-open-dialog="dlg-assign"><?= e(__('assignment.assign')) ?></ui5-button>
                <?php endif; ?>
                <?php if ($canReassignNow): ?>
                    <ui5-button design="Transparent" icon="synchronize" data-lt-open-dialog="dlg-reassign"><?= e(__('assignment.reassign')) ?></ui5-button>
                <?php endif; ?>
            </div>
            <?php if ($assignments === []): ?>
                <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('assignment.none')) ?></ui5-text></div>
            <?php else: ?>
                <div class="lt-op-block">
                <ui5-list separators="Inner" accessible-name="<?= e($sections['assignments']) ?>">
                    <?php foreach ($assignments as $a): ?>
                        <ui5-li-custom>
                            <div class="lt-item">
                                <div class="lt-item__head">
                                    <ui5-title level="H3" size="H6"><?= e($a->userName ?? $a->departmentName ?? '—') ?></ui5-title>
                                    <?php if ($a->userName !== null && $a->departmentName !== null): ?><ui5-label><?= e($a->departmentName) ?></ui5-label><?php endif; ?>
                                    <ui5-tag design="<?= $a->role === AssignmentRole::ForAction ? 'Information' : 'Neutral' ?>" hide-state-icon><?= e(__('enums.assignment_role.' . $a->role->value)) ?></ui5-tag>
                                    <?php if (!$a->isActive()): ?><ui5-tag design="Neutral" hide-state-icon><?= e(__('enums.assignment_status.' . $a->status->value)) ?></ui5-tag><?php endif; ?>
                                </div>
                                <?php if ($a->delegatedFromName !== null): ?>
                                    <ui5-label><?= e(__('assignment.delegated_from', ['name' => $a->delegatedFromName])) ?></ui5-label>
                                <?php endif; ?>
                                <?php if ($a->instructions !== null): ?><ui5-text class="lt-prewrap"><?= e($a->instructions) ?></ui5-text><?php endif; ?>
                                <ui5-label wrapping-type="Normal">
                                    <?= e(__('assignment.by', ['name' => $a->assignedByName ?? '—', 'date' => local_datetime($a->createdAt)])) ?>
                                    <?php if ($a->dueDate !== null): ?> · <?= e(__('mail.fields.due_date')) ?> <?= e(local_date($a->dueDate)) ?><?php endif; ?>
                                </ui5-label>
                            </div>
                        </ui5-li-custom>
                    <?php endforeach; ?>
                </ui5-list>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-op-section" id="annotations" aria-labelledby="annotations-title">
            <div class="lt-op-section__header">
                <ui5-title level="H2" size="H4" id="annotations-title"><?= e($sections['annotations']) ?></ui5-title>
                <?php if ($canAnnotate && !$editing): ?>
                    <ui5-button design="Transparent" icon="notes" data-lt-open-dialog="dlg-annotate"><?= e(__('annotation.add')) ?></ui5-button>
                <?php endif; ?>
            </div>
            <?php if ($annotations === []): ?>
                <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('annotation.none')) ?></ui5-text></div>
            <?php else: ?>
                <div class="lt-op-block">
                <ui5-list separators="Inner" accessible-name="<?= e($sections['annotations']) ?>">
                    <?php foreach ($annotations as $note): ?>
                        <ui5-li-custom>
                            <div class="lt-item">
                                <div class="lt-item__head">
                                    <ui5-title level="H3" size="H6"><?= e($note->userName) ?></ui5-title>
                                    <ui5-label><?= e(local_datetime($note->createdAt)) ?></ui5-label>
                                    <?php if ($note->isPrivate): ?><ui5-tag design="Neutral" hide-state-icon><ui5-icon slot="icon" name="locked"></ui5-icon><?= e(__('annotation.private')) ?></ui5-tag><?php endif; ?>
                                </div>
                                <ui5-text class="lt-prewrap"><?= e($note->body) ?></ui5-text>
                            </div>
                        </ui5-li-custom>
                    <?php endforeach; ?>
                </ui5-list>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-op-section" id="attachments" aria-labelledby="attachments-title">
            <div class="lt-op-section__header">
                <ui5-title level="H2" size="H4" id="attachments-title"><?= e($sections['attachments']) ?></ui5-title>
                <?php if ($canUpdate && !$editing): ?>
                    <ui5-button design="Transparent" icon="upload" data-lt-open-dialog="dlg-upload"><?= e(__('attachment.upload')) ?></ui5-button>
                <?php endif; ?>
            </div>
            <?php if ($attachments === []): ?>
                <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('attachment.none')) ?></ui5-text></div>
            <?php else: ?>
                <div class="lt-op-block">
                <ui5-list separators="Inner" accessible-name="<?= e($sections['attachments']) ?>">
                    <?php foreach ($attachments as $file): ?>
                        <ui5-li-custom>
                            <div class="lt-item lt-item--row">
                                <ui5-icon name="<?= $file->mimeType === 'application/pdf' ? 'pdf-attachment' : 'attachment' ?>"></ui5-icon>
                                <ui5-text class="lt-item__grow"><?= e($file->originalName) ?></ui5-text>
                                <ui5-label><?= e(Ui5::fileSize($file->sizeBytes, $locale ?? 'fr')) ?></ui5-label>
                                <?php if ($file->isPurged()): ?>
                                    <ui5-label wrapping-type="Normal"><?= e(__('attachment.purged', ['date' => local_date($file->purgedAt?->format('Y-m-d'))])) ?></ui5-label>
                                <?php else: ?>
                                    <?php if (AttachmentPolicy::isInline($file->mimeType)): ?>
                                        <ui5-link href="<?= e($basePath) ?>/attachments/<?= e($file->id) ?>?inline=1" target="_blank"><?= e(__('attachment.view')) ?></ui5-link>
                                    <?php endif; ?>
                                    <ui5-link href="<?= e($basePath) ?>/attachments/<?= e($file->id) ?>"><?= e(__('attachment.download')) ?></ui5-link>
                                <?php endif; ?>
                            </div>
                        </ui5-li-custom>
                    <?php endforeach; ?>
                </ui5-list>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-op-section" id="links" aria-labelledby="links-title">
            <div class="lt-op-section__header">
                <ui5-title level="H2" size="H4" id="links-title"><?= e($sections['links']) ?></ui5-title>
                <?php if ($canLinkReply && !$editing): ?>
                    <ui5-button design="Transparent" icon="chain-link" data-lt-open-dialog="dlg-link"><?= e(__('link.link_reply')) ?></ui5-button>
                <?php endif; ?>
            </div>
            <?php if ($links === []): ?>
                <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('link.none')) ?></ui5-text></div>
            <?php else: ?>
                <div class="lt-op-block">
                <ui5-list separators="Inner" accessible-name="<?= e($sections['links']) ?>" data-lt-links>
                    <?php foreach ($links as $link): ?>
                        <ui5-li type="Navigation" data-lt-href="/mails/<?= e($link['mail_id']) ?>"
                                description="<?= e($link['subject']) ?>"
                                additional-text="<?= e(__('enums.status.' . $link['status'])) ?>"
                                additional-text-state="<?= e(Ui5::TAG_DESIGNS['status'][$link['status']] ?? 'None') ?>"><?= e(__('link.' . $link['type'] . '.' . $link['side'])) ?> <?= e($link['reference']) ?></ui5-li>
                    <?php endforeach; ?>
                </ui5-list>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-op-section" id="history" aria-labelledby="history-title">
            <ui5-title level="H2" size="H4" id="history-title"><?= e($sections['history']) ?></ui5-title>
            <?php if ($history === []): ?>
                <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('mail.history_empty')) ?></ui5-text></div>
            <?php else: ?>
                <div class="lt-op-block lt-op-block--padded">
                <ui5-timeline accessible-name="<?= e($sections['history']) ?>">
                    <?php foreach (array_reverse($history) as $entry): ?>
                        <ui5-timeline-item icon="history" title-text="<?= e($entry['action']) ?>"
                                           subtitle-text="<?= e($entry['user']) ?> · <?= e(local_datetime($entry['at'])) ?>">
                            <?php foreach ($entry['changes'] as $change): ?>
                                <?php
                                $isEmpty = static fn (string $v): bool => $v === '' || $v === '—';
                                if ($isEmpty($change['old']) && $isEmpty($change['new'])) {
                                    continue;
                                }
                                // A value set for the first time, removed, or changed: three wordings.
                                $key = $isEmpty($change['old']) ? 'object.value_set' : ($isEmpty($change['new']) ? 'object.value_removed' : 'object.changed');
                                ?>
                                <ui5-text class="lt-change"><?= e(__($key, ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']])) ?></ui5-text>
                            <?php endforeach; ?>
                        </ui5-timeline-item>
                    <?php endforeach; ?>
                </ui5-timeline>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($editing || $footerActions !== []): ?>
        <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
            <?php if ($editing): ?>
                <?= $this->partial('partials/object-page/messages', ['errors' => $errors, 'labels' => $fieldLabels]) ?>
                <ui5-button slot="endContent" design="Emphasized" data-lt-submit="mail-form"><?= e(__('common.save')) ?></ui5-button>
                <ui5-button slot="endContent" design="Transparent" data-lt-href="/mails/<?= e($mail->id) ?>"><?= e(__('common.cancel')) ?></ui5-button>
            <?php else: ?>
                <?php foreach ($footerActions as $i => $action): ?>
                    <?php $c = $confirm[$action->value] ?? null; ?>
                    <ui5-button slot="endContent" design="<?= $i === 0 ? 'Emphasized' : 'Transparent' ?>"
                        icon="<?= e($actionIcons[$action->value] ?? '') ?>" data-lt-action="wf-<?= e($action->value) ?>"
                        <?php if ($c !== null): ?>
                            data-confirm="<?= e($c['message']) ?>"
                            data-confirm-title="<?= e(__('enums.action.' . $action->value)) ?>"
                            data-confirm-state="<?= e($c['state'] ?? 'Critical') ?>"
                            <?= isset($c['input']) ? 'data-confirm-input="comment" data-confirm-input-label="' . e($c['input']) . '"' : '' ?>
                        <?php endif; ?>><?= e(__('enums.action.' . $action->value)) ?></ui5-button>
                <?php endforeach; ?>
            <?php endif; ?>
        </ui5-bar>
    <?php endif; ?>
</ui5-dynamic-page>

<?php if (!$editing): ?>
    <?php foreach ($footerActions as $action): ?>
        <form id="wf-<?= e($action->value) ?>" method="post" action="<?= e($url) ?>/actions/<?= e($action->value) ?>" hidden>
            <?= $csrf->field() ?>
            <input type="hidden" name="comment" value="">
        </form>
    <?php endforeach; ?>
    <?= $this->partial('partials/object-page/confirm') ?>

    <?php if ($canAssignNow || $canReassignNow): ?>
        <?php foreach (array_filter(['assign' => $canAssignNow, 'reassign' => $canReassignNow]) as $kind => $_): ?>
            <ui5-dialog id="dlg-<?= e($kind) ?>" data-lt-form-dialog header-text="<?= e(__('assignment.' . $kind)) ?>" <?= $kind === 'reassign' ? 'state="Critical"' : '' ?>>
                <form method="post" action="<?= e($url) ?>/<?= e($kind) ?>" class="lt-dialog-form" novalidate>
                    <?= $csrf->field() ?>
                    <?php if ($kind === 'reassign'): ?>
                        <ui5-message-strip design="Critical" hide-close-button><?= e(__('workflow.confirm.reassign', ['name' => $activeForAction->userName ?? $activeForAction->departmentName ?? ''])) ?></ui5-message-strip>
                    <?php endif; ?>
                    <ui5-label for="<?= e($kind) ?>-user" show-colon><?= e(__('assignment.fields.user_id')) ?></ui5-label>
                    <ui5-select id="<?= e($kind) ?>-user" name="user_id"><?= Ui5::options($userOptions, '') ?></ui5-select>
                    <?php if ($departments !== []): ?>
                        <ui5-label for="<?= e($kind) ?>-department" show-colon><?= e(__('assignment.fields.department_id')) ?></ui5-label>
                        <ui5-select id="<?= e($kind) ?>-department" name="department_id"><?= Ui5::options($departmentOptions, '') ?></ui5-select>
                    <?php endif; ?>
                    <?php if ($kind === 'assign'): ?>
                        <ui5-label for="assign-role" required show-colon><?= e(__('assignment.fields.role')) ?></ui5-label>
                        <ui5-select id="assign-role" name="role"><?= Ui5::options($roleOptions, '') ?></ui5-select>
                    <?php endif; ?>
                    <ui5-label for="<?= e($kind) ?>-due" show-colon><?= e(__('assignment.fields.due_date')) ?></ui5-label>
                    <ui5-date-picker id="<?= e($kind) ?>-due" name="due_date" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" min-date="<?= e($today) ?>"
                        placeholder="<?= e(__('list.date_placeholder')) ?>"
                        value="<?= e(($kind === 'reassign' ? $activeForAction->dueDate : $mail->dueDate) ?? '') ?>"></ui5-date-picker>
                    <ui5-label for="<?= e($kind) ?>-instructions" show-colon><?= e(__('assignment.fields.instructions')) ?></ui5-label>
                    <ui5-textarea id="<?= e($kind) ?>-instructions" name="instructions" maxlength="2000" rows="3"></ui5-textarea>
                    <?php if ($kind === 'reassign'): ?>
                        <ui5-label for="reassign-comment" show-colon><?= e(__('workflow.reason')) ?></ui5-label>
                        <ui5-textarea id="reassign-comment" name="comment" maxlength="1000" rows="2"></ui5-textarea>
                    <?php endif; ?>
                </form>
                <div slot="footer" class="lt-dialog-footer">
                    <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('assignment.' . $kind)) ?></ui5-button>
                    <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
                </div>
            </ui5-dialog>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($canAnnotate): ?>
        <ui5-dialog id="dlg-annotate" data-lt-form-dialog header-text="<?= e(__('annotation.add')) ?>">
            <form method="post" action="<?= e($url) ?>/annotations" class="lt-dialog-form" novalidate>
                <?= $csrf->field() ?>
                <ui5-label for="note-body" required show-colon><?= e(__('annotation.body')) ?></ui5-label>
                <ui5-textarea id="note-body" name="body" maxlength="5000" rows="5" required></ui5-textarea>
                <ui5-checkbox name="is_private" value="1" text="<?= e(__('annotation.private_help')) ?>"></ui5-checkbox>
            </form>
            <div slot="footer" class="lt-dialog-footer">
                <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('annotation.add')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
            </div>
        </ui5-dialog>
    <?php endif; ?>

    <?php if ($canUpdate): ?>
        <ui5-dialog id="dlg-upload" data-lt-form-dialog header-text="<?= e(__('attachment.upload')) ?>">
            <form method="post" enctype="multipart/form-data" action="<?= e($url) ?>/attachments" class="lt-dialog-form" novalidate>
                <?= $csrf->field() ?>
                <ui5-label for="file" required show-colon><?= e(__('attachment.file')) ?></ui5-label>
                <ui5-file-uploader id="file" name="file" accept="<?= e($accept) ?>" required placeholder="<?= e(__('attachment.file')) ?>"></ui5-file-uploader>
                <ui5-label wrapping-type="Normal"><?= e(__('attachment.help', ['max' => $maxUploadMb])) ?></ui5-label>
            </form>
            <div slot="footer" class="lt-dialog-footer">
                <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('attachment.upload')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
            </div>
        </ui5-dialog>
    <?php endif; ?>

    <?php if ($canLinkReply): ?>
        <ui5-dialog id="dlg-link" data-lt-form-dialog state="Critical" header-text="<?= e(__('link.link_reply')) ?>">
            <form method="post" action="<?= e($url) ?>/reply-link" class="lt-dialog-form" novalidate>
                <?= $csrf->field() ?>
                <ui5-message-strip design="Critical" hide-close-button><?= e(__('workflow.confirm.link_reply')) ?></ui5-message-strip>
                <ui5-label for="link-ref" required show-colon><?= e(__('link.reference')) ?></ui5-label>
                <ui5-input id="link-ref" name="reference" maxlength="20" required placeholder="ENT-<?= e(date('Y')) ?>-00001"></ui5-input>
            </form>
            <div slot="footer" class="lt-dialog-footer">
                <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('link.link_reply')) ?></ui5-button>
                <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
            </div>
        </ui5-dialog>
    <?php endif; ?>
<?php endif; ?>
