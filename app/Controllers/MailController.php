<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\BulkRequest;
use App\Core\TableRequest;
use App\Core\Translator;
use App\Core\Url;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Auth\Permission;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\RuleViolation;
use App\Services\AttachmentService;
use App\Services\AuthService;
use App\Services\CorrespondentService;
use App\Services\DocumentService;
use App\Services\MailService;
use App\Services\ReferenceDataService;
use App\Services\WorkflowService;
use DateTimeImmutable;
use DateTimeZone;

final class MailController
{
    use HandlesDomain;

    /** Audit fields holding enum values, translated in the history. */
    private const ENUM_FIELDS = ['direction', 'channel', 'priority', 'confidentiality', 'status'];
    private const DATETIME_FIELDS = ['received_at', 'sent_at'];
    private const DATE_FIELDS = ['document_date', 'due_date'];
    private const HISTORY_HIDDEN_FIELDS = ['assignment_id', 'annotation_id', 'attachment_id', 'sha256', 'size_bytes', 'mime_type', 'site_id'];

    /** @var array<int, string> user id => name, for the history */
    private array $userNames = [];

    public function __construct(
        private readonly MailService $mails,
        private readonly AttachmentService $attachments,
        private readonly CorrespondentService $correspondents,
        private readonly ReferenceDataService $reference,
        private readonly WorkflowService $workflow,
        private readonly DocumentService $documents,
        private readonly AttachmentPolicy $attachmentPolicy,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->actor($request)->user;
        $canAssign = $user->can(Permission::MailAssign);
        return Response::html($this->view->render('mails/index', [
            'departments' => $this->reference->departments(),
            'canCreate' => $user->can(Permission::MailCreate),
            'canAssign' => $canAssign,
            'canClose' => $user->can(Permission::MailUpdate),
            'assignableUsers' => $canAssign ? $this->workflow->assignableUsers($user->siteId) : [],
        ]));
    }

    /** JSON for the server-side table (see lt-tables.js). */
    public function data(Request $request): Response
    {
        $table = TableRequest::fromRequest($request, MailService::sortKeys(), 'mail_date', 'desc');
        return Response::json($this->mails->page($this->filterFrom($request), $table)->toArray());
    }

    /** JSON rows for lt-export.js: same filters, search and sort as the table, capped. */
    public function export(Request $request): Response
    {
        $table = TableRequest::fromRequest($request, MailService::sortKeys(), 'mail_date', 'desc');
        return Response::json($this->documents->listExport($this->filterFrom($request), $table));
    }

    /** Printable registration slip (standalone page, print stylesheet). */
    public function slip(Request $request): Response
    {
        $data = $this->orNotFound(fn (): array => $this->documents->slip($this->routeId($request)));
        return Response::html($this->view->render('mails/slip', $data + [
            'printedAt' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'printedBy' => $this->actor($request)->user->fullName(),
        ]));
    }

    public function create(Request $request): Response
    {
        $replyTo = $this->replyTarget($request->query('reply_to'));
        $direction = $replyTo !== null ? Direction::Outgoing : (Direction::tryFrom((string) $request->query('direction')) ?? Direction::Incoming);
        $values = self::defaults($direction);
        if ($replyTo !== null) {
            $values['correspondent_id'] = $replyTo->correspondentId;
            $values['subject'] = mb_substr('Re: ' . $replyTo->subject, 0, 255);
            $values['department_id'] = $replyTo->departmentId;
            $values['confidentiality'] = $replyTo->confidentiality->value;
        }
        return $this->form($direction, null, $values, [], $replyTo);
    }

    public function store(Request $request): Response
    {
        $replyTo = $this->replyTarget($request->post('reply_to'));
        $direction = $replyTo !== null ? Direction::Outgoing : (Direction::tryFrom((string) $request->post('direction')) ?? Direction::Incoming);
        try {
            $input = $this->parseInput($request, $direction);
            $actor = $this->actor($request);
            $mail = $replyTo !== null
                // Registers the reply, links it and closes the incoming mail in one transaction.
                ? $this->orNotFound(fn (): Mail => $this->workflow->createReply($actor, $replyTo->id, $input))
                : $this->mails->create($actor, $direction, $input, filter_var($request->post('site_id'), FILTER_VALIDATE_INT) ?: null);
        } catch (ValidationException | RuleViolation $e) {
            return $this->form($direction, null, $request->all(), $this->errorsOf($e), $replyTo);
        }
        $message = $replyTo !== null
            ? $this->translator->get('link.reply_created', ['reference' => $mail->reference, 'incoming' => $replyTo->reference])
            : $this->translator->get('mail.created', ['reference' => $mail->reference]);
        $this->session->flash('flash.success', $message);

        // Optional scan chosen in the wizard. The mail is registered whatever happens to the file:
        // a refused file is reported, and can be added again from the mail page.
        $file = $request->file('file');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->attachments->upload($actor, $mail->id, $file);
            } catch (RuleViolation $e) {
                $this->session->flash('flash.error', $this->translator->get('wizard.mail.attachment_failed', [
                    'reason' => implode(' ', array_merge(...array_values($this->violationMessages($e)))),
                ]));
            }
        }
        return Response::redirect($this->url->route('mails.show', ['id' => $mail->id]));
    }

    public function show(Request $request): Response
    {
        $mail = $this->orNotFound(fn (): Mail => $this->mails->find($this->routeId($request)));
        return $this->page($request, $mail, false);
    }

    /** The mail page in edit mode (Object Page: same screen, general section editable). */
    public function edit(Request $request): Response
    {
        $mail = $this->orNotFound(fn (): Mail => $this->mails->find($this->routeId($request)));
        if (!$mail->isEditable()) {
            return Response::redirect($this->url->route('mails.show', ['id' => $mail->id]));
        }
        return $this->page($request, $mail, true, self::valuesOf($mail));
    }

    public function update(Request $request): Response
    {
        $mail = $this->orNotFound(fn (): Mail => $this->mails->find($this->routeId($request)));
        try {
            $input = $this->parseInput($request, $mail->direction);
            $mail = $this->orNotFound(fn (): Mail => $this->mails->update($this->actor($request), $mail->id, $input));
        } catch (ValidationException | RuleViolation $e) {
            return $this->page($request, $mail, true, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('mail.updated', ['reference' => $mail->reference]));
        return Response::redirect($this->url->route('mails.show', ['id' => $mail->id]));
    }

    /**
     * Object Page of a mail, in display or edit mode.
     *
     * @param array<string, mixed> $values edit mode: values of the general section
     * @param array<string, list<string>> $errors edit mode: validation errors (HTTP 422)
     */
    private function page(Request $request, Mail $mail, bool $editing, array $values = [], array $errors = []): Response
    {
        $actor = $this->actor($request);
        $user = $actor->user;
        $correspondent = $this->correspondents->findOrNull($mail->correspondentId);
        // In edit mode the picker shows the correspondent being chosen, not the saved one.
        $chosenId = (int) ($values['correspondent_id'] ?? 0);
        $chosen = $editing && $chosenId > 0 ? $this->correspondents->findOrNull($chosenId) : null;

        return Response::html($this->view->render('mails/show', [
            'mail' => $mail,
            'correspondent' => $correspondent,
            'departmentName' => $mail->departmentId !== null ? $this->reference->departmentName($mail->departmentId) : null,
            'attachments' => $this->attachments->forMail($mail->id),
            'history' => $this->history($mail),
            'canUpdate' => $user->can(Permission::MailUpdate) && $mail->isEditable(),
            'maxUploadMb' => (int) ceil($this->attachmentPolicy->maxBytes() / 1048576),
            'accept' => implode(',', array_keys(AttachmentPolicy::ALLOWED)),
            'today' => (new DateTimeImmutable('now'))->format('Y-m-d'),
            // Workflow
            'actions' => $this->workflow->availableActions($actor, $mail),
            'assignments' => $this->workflow->assignmentsFor($mail->id),
            'annotations' => $this->workflow->annotationsFor($actor, $mail->id),
            'links' => $this->workflow->linksFor($mail->id),
            'assignableUsers' => $this->workflow->assignableUsers($mail->siteId),
            'departments' => array_values(array_filter($this->reference->departments(), static fn (array $d): bool => $d['site_id'] === $mail->siteId)),
            'canAssign' => $user->can(Permission::MailAssign) && $mail->isEditable(),
            'canAnnotate' => $user->can(Permission::MailAnnotate),
            'canReply' => $user->can(Permission::MailCreate) && $mail->direction === Direction::Incoming && $mail->isEditable(),
            'canLinkReply' => $user->can(Permission::MailUpdate) && $mail->direction === Direction::Outgoing,
            // Edit mode
            'editing' => $editing,
            'values' => $values,
            'errors' => $errors,
            'correspondentLabel' => $chosen?->displayName() ?? '',
        ]), $errors === [] ? 200 : 422);
    }

    /**
     * Creation form (Object Page in creation mode).
     *
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function form(Direction $direction, ?Mail $mail, array $values, array $errors = [], ?Mail $replyTo = null): Response
    {
        $correspondentId = (int) ($values['correspondent_id'] ?? 0);
        $correspondent = $correspondentId > 0 ? $this->correspondents->findOrNull($correspondentId) : null;

        // Incoming mail is registered step by step (Wizard); a reply or an outgoing mail is a simple form.
        $view = $direction === Direction::Incoming && $replyTo === null ? 'mails/wizard' : 'mails/form';

        return Response::html($this->view->render($view, [
            'maxUploadMb' => (int) ceil($this->attachmentPolicy->maxBytes() / 1048576),
            'accept' => implode(',', array_keys(AttachmentPolicy::ALLOWED)),
            'direction' => $direction,
            'mail' => $mail,
            'replyTo' => $replyTo,
            'values' => $values,
            'errors' => $errors,
            'correspondentLabel' => $correspondent?->displayName() ?? '',
            'departments' => $this->reference->departments(),
        ]), $errors === [] ? 200 : 422);
    }

    private function parseInput(Request $request, Direction $direction): MailInput
    {
        $in = static fn (array $cases): string => 'in:' . implode(',', array_column($cases, 'value'));
        $rules = [
            'subject' => 'required|string|max:255',
            'summary' => 'nullable|string|max:5000',
            'correspondent_id' => 'required|int|min:1',
            'department_id' => 'nullable|int|min:1',
            'channel' => 'required|' . $in(Channel::cases()),
            'priority' => 'required|' . $in(Priority::cases()),
            'confidentiality' => 'required|' . $in(Confidentiality::cases()),
            'document_date' => 'nullable|date',
            'received_at' => ($direction === Direction::Incoming ? 'required' : 'nullable') . '|datetime',
            'sent_at' => 'nullable|datetime',
            'due_date' => 'nullable|date',
            'external_reference' => 'nullable|string|max:100',
        ];
        $labels = array_combine(array_keys($rules), array_map(static fn (string $f): string => 'mail.fields.' . $f, array_keys($rules)));
        $data = $this->validator->validate($request->all(), $rules, $labels);

        return new MailInput(
            subject: $data['subject'],
            summary: self::nullIfEmpty($data['summary']),
            correspondentId: $data['correspondent_id'],
            departmentId: $data['department_id'],
            channel: Channel::from($data['channel']),
            priority: Priority::from($data['priority']),
            confidentiality: Confidentiality::from($data['confidentiality']),
            documentDate: $data['document_date'],
            receivedAt: $direction === Direction::Incoming ? self::localToUtc($data['received_at']) : null,
            sentAt: $direction === Direction::Outgoing ? self::localToUtc($data['sent_at']) : null,
            dueDate: $data['due_date'],
            externalReference: self::nullIfEmpty($data['external_reference']),
        );
    }

    private function filterFrom(Request $request): MailFilter
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $day = static function (mixed $value) use ($zone): ?DateTimeImmutable {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone) : false;
            return $date === false ? null : $date;
        };
        $from = $day($request->query('date_from'));
        $to = $day($request->query('date_to'));
        $id = static fn (mixed $v): ?int => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        return new MailFilter(
            direction: Direction::tryFrom((string) $request->query('direction')),
            status: MailStatus::tryFrom((string) $request->query('status')),
            priority: Priority::tryFrom((string) $request->query('priority')),
            departmentId: $id($request->query('department_id')),
            correspondentId: $id($request->query('correspondent_id')),
            from: $from?->setTimezone(new DateTimeZone('UTC')),
            // Inclusive end day: up to the next local midnight.
            toExclusive: $to?->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
            overdueBefore: $request->query('overdue') ? (new DateTimeImmutable('now', $zone))->format('Y-m-d') : null,
            // "My mail": assigned to me or to an absent colleague I replace.
            assignedToUserIds: $request->query('mine') ? $this->workflow->coveredUserIds($this->actor($request)) : null,
            // Rows selected in the list ("1,2,3"): export of the selection.
            ids: is_string($request->query('ids')) && $request->query('ids') !== '' ? BulkRequest::ids($request->query('ids')) : null,
        );
    }

    /** Incoming mail a new outgoing mail answers (?reply_to=id), if valid. */
    private function replyTarget(mixed $id): ?Mail
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        $mail = $this->orNotFound(fn (): Mail => $this->mails->find($id));
        return $mail->direction === Direction::Incoming ? $mail : null;
    }

    /** @return array<string, string> */
    private static function defaults(Direction $direction): array
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');
        return [
            'channel' => Channel::Postal->value,
            'priority' => Priority::Normal->value,
            'confidentiality' => Confidentiality::Internal->value,
            'received_at' => $direction === Direction::Incoming ? $now : '',
            'sent_at' => $direction === Direction::Outgoing ? $now : '',
        ];
    }

    /** @return array<string, mixed> */
    private static function valuesOf(Mail $mail): array
    {
        return [
            'subject' => $mail->subject,
            'summary' => $mail->summary,
            'correspondent_id' => $mail->correspondentId,
            'department_id' => $mail->departmentId,
            'channel' => $mail->channel->value,
            'priority' => $mail->priority->value,
            'confidentiality' => $mail->confidentiality->value,
            'document_date' => $mail->documentDate,
            'received_at' => self::utcToLocalInput($mail->receivedAt),
            'sent_at' => self::utcToLocalInput($mail->sentAt),
            'due_date' => $mail->dueDate,
            'external_reference' => $mail->externalReference,
        ];
    }

    /**
     * Activity log entries ready for display (labels and values translated).
     *
     * @return list<array{at: DateTimeImmutable, action: string, user: string, changes: list<array{field: string, old: string, new: string}>}>
     */
    private function history(Mail $mail): array
    {
        $this->userNames = array_column($this->workflow->assignableUsers($mail->siteId), 'name', 'id');
        $entries = [];
        foreach ($this->mails->history($mail->id) as $row) {
            $changes = [];
            foreach (array_unique([...array_keys($row['old_values']), ...array_keys($row['new_values'])]) as $field) {
                // Technical values (ids, checksum, raw size) stay in the audit log; the page shows what a reader needs.
                if (in_array($field, self::HISTORY_HIDDEN_FIELDS, true)) {
                    continue;
                }
                $changes[] = [
                    'field' => $this->fieldLabel((string) $field),
                    'old' => $this->displayValue((string) $field, $row['old_values'][$field] ?? null),
                    'new' => $this->displayValue((string) $field, $row['new_values'][$field] ?? null),
                ];
            }
            $entries[] = [
                'at' => $row['created_at'],
                'action' => $this->translator->get('mail.actions.' . $row['action']),
                'user' => $row['user_name'] ?? '—',
                'changes' => $changes,
            ];
        }
        return $entries;
    }

    private function fieldLabel(string $field): string
    {
        foreach (['mail.fields.', 'history.fields.', 'attachment.fields.'] as $prefix) {
            if ($this->translator->has($prefix . $field)) {
                return $this->translator->get($prefix . $field);
            }
        }
        return $field;
    }

    private function displayValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($field, self::ENUM_FIELDS, true)) {
            return $this->translator->get("enums.{$field}.{$value}");
        }
        if ($field === 'role') {
            return $this->translator->get("enums.assignment_role.{$value}");
        }
        if (is_bool($value)) {
            return $this->translator->get($value ? 'common.yes' : 'common.no');
        }
        if ($field === 'user_id' || $field === 'delegated_from_user_id') {
            return $this->userNames[(int) $value] ?? '#' . $value;
        }
        if (in_array($field, self::DATETIME_FIELDS, true)) {
            return local_datetime(new DateTimeImmutable((string) $value, new DateTimeZone('UTC')));
        }
        if (in_array($field, self::DATE_FIELDS, true)) {
            return local_date((string) $value);
        }
        if ($field === 'correspondent_id') {
            return $this->correspondents->findOrNull((int) $value)?->displayName() ?? '#' . $value;
        }
        if ($field === 'department_id') {
            return $this->reference->departmentName((int) $value) ?? '#' . $value;
        }
        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
