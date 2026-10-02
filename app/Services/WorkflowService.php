<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Transaction;
use App\Domain\AccessDeniedException;
use App\Domain\Annotation\Annotation;
use App\Domain\Assignment\Assignment;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Assignment\AssignmentRules;
use App\Domain\Assignment\AssignmentStatus;
use App\Domain\Audit\Actor;
use App\Domain\Delegation\DelegationResolver;
use App\Domain\Mail\Direction;
use App\Domain\Mail\LinkType;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailAccess;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailLinkRules;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailWorkflow;
use App\Domain\NotFoundException;
use App\Domain\Notification\NotificationType;
use App\Domain\RuleViolation;
use App\Repositories\AnnotationRepository;
use App\Repositories\AssignmentRepository;
use App\Repositories\DelegationRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MailLinkRepository;
use App\Repositories\MailRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Mail processing: assignment, reassignment, status actions, annotations,
 * reply links. Every status change goes through MailWorkflow and is logged.
 */
final class WorkflowService
{
    /** Actions triggered by perform(); the others have dedicated methods. */
    private const SIMPLE_ACTIONS = [MailAction::Start, MailAction::AwaitReply, MailAction::Close, MailAction::Reopen, MailAction::Archive];

    public function __construct(
        private readonly MailRepository $mails,
        private readonly AssignmentRepository $assignments,
        private readonly AnnotationRepository $annotations,
        private readonly DelegationRepository $delegations,
        private readonly MailLinkRepository $links,
        private readonly UserRepository $users,
        private readonly DepartmentRepository $departments,
        private readonly MailService $mailService,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
    ) {
    }

    /* ------------------------------------------------------------ assignment */

    /**
     * @throws NotFoundException|AccessDeniedException|RuleViolation
     */
    public function assign(Actor $actor, int $mailId, AssignmentRequest $request): Assignment
    {
        return $this->transaction->run(function () use ($actor, $mailId, $request): Assignment {
            $mail = $this->loadFor($actor, $mailId, MailAction::Assign);
            $to = MailWorkflow::apply($mail->status, MailAction::Assign);
            AssignmentRules::check($request, $this->today(), $this->assignments->activeForAction($mail->id) !== null, false);

            $assignment = $this->createAssignment($actor, $mail, $request);
            $this->audit->event($actor, MailService::ENTITY, $mail->id, 'assigned', $mail->siteId, null, $assignment->auditValues());
            $this->changeStatus($actor, $mail, $to);
            return $assignment;
        });
    }

    /**
     * Replaces the current "for action" assignment.
     *
     * @throws NotFoundException|AccessDeniedException|RuleViolation
     */
    public function reassign(Actor $actor, int $mailId, AssignmentRequest $request, ?string $comment = null): Assignment
    {
        return $this->transaction->run(function () use ($actor, $mailId, $request, $comment): Assignment {
            $mail = $this->loadFor($actor, $mailId, MailAction::Reassign);
            $to = MailWorkflow::apply($mail->status, MailAction::Reassign);
            $current = $this->assignments->activeForAction($mail->id);
            AssignmentRules::check($request, $this->today(), $current !== null, true);

            $this->assignments->end($current->id, AssignmentStatus::Reassigned, $this->clock->now(), $actor->user->id);
            $assignment = $this->createAssignment($actor, $mail, $request);
            $this->audit->event($actor, MailService::ENTITY, $mail->id, 'reassigned', $mail->siteId,
                $current->auditValues(), $assignment->auditValues() + ['comment' => $comment]);
            $this->changeStatus($actor, $mail, $to);
            return $assignment;
        });
    }

    /* ---------------------------------------------------------------- status */

    /**
     * start, await_reply, close, reopen, archive.
     *
     * @throws NotFoundException|AccessDeniedException|RuleViolation
     */
    public function perform(Actor $actor, int $mailId, MailAction $action, ?string $comment = null): Mail
    {
        if (!in_array($action, self::SIMPLE_ACTIONS, true)) {
            throw new \InvalidArgumentException("Use the dedicated method for {$action->value}.");
        }
        return $this->transaction->run(function () use ($actor, $mailId, $action, $comment): Mail {
            $mail = $this->loadFor($actor, $mailId, $action);
            $this->changeStatus($actor, $mail, MailWorkflow::apply($mail->status, $action), $comment);
            return $this->mails->findById($mail->id) ?? throw NotFoundException::of('Mail', $mail->id);
        });
    }

    /* ---------------------------------------------------- bulk actions (list) */

    /**
     * Assigns several mails at once (list toolbar). Each mail is processed in its own
     * transaction: a refusal on one mail never undoes the others. "For action" on a mail
     * that already has an owner is a reassignment, as on the mail page.
     *
     * @param list<int> $mailIds
     * @return array{done: list<int>, failed: array<int, NotFoundException|AccessDeniedException|RuleViolation>}
     */
    public function assignMany(Actor $actor, array $mailIds, AssignmentRequest $request, ?string $comment = null): array
    {
        return $this->each($mailIds, function (int $mailId) use ($actor, $request, $comment): void {
            if ($request->role === AssignmentRole::ForAction && $this->assignments->activeForAction($mailId) !== null) {
                $this->reassign($actor, $mailId, $request, $comment);
            } else {
                $this->assign($actor, $mailId, $request);
            }
        });
    }

    /**
     * Performs one workflow action on several mails (list toolbar: close), one transaction per mail.
     *
     * @param list<int> $mailIds
     * @return array{done: list<int>, failed: array<int, NotFoundException|AccessDeniedException|RuleViolation>}
     */
    public function performMany(Actor $actor, array $mailIds, MailAction $action, ?string $comment = null): array
    {
        return $this->each($mailIds, function (int $mailId) use ($actor, $action, $comment): void {
            $this->perform($actor, $mailId, $action, $comment);
        });
    }

    /**
     * @param list<int> $mailIds
     * @param \Closure(int): void $work
     * @return array{done: list<int>, failed: array<int, NotFoundException|AccessDeniedException|RuleViolation>}
     */
    private function each(array $mailIds, \Closure $work): array
    {
        $result = ['done' => [], 'failed' => []];
        foreach (array_values(array_unique($mailIds)) as $mailId) {
            try {
                $work($mailId);
                $result['done'][] = $mailId;
            } catch (NotFoundException | AccessDeniedException | RuleViolation $e) {
                $result['failed'][$mailId] = $e;
            }
        }
        return $result;
    }

    /* ------------------------------------------------------------ annotation */

    /** @throws NotFoundException|RuleViolation */
    public function annotate(Actor $actor, int $mailId, string $body, bool $isPrivate): int
    {
        $body = Annotation::checkBody($body);
        return $this->transaction->run(function () use ($actor, $mailId, $body, $isPrivate): int {
            $mail = $this->mails->findById($mailId) ?? throw NotFoundException::of('Mail', $mailId);
            $id = $this->annotations->create($mail->id, $mail->siteId, $actor->user->id, $body, $isPrivate, $this->clock->now());
            // The text of a private note stays out of the shared history.
            $this->audit->event($actor, MailService::ENTITY, $mail->id, 'annotation_added', $mail->siteId, null, [
                'annotation_id' => $id,
                'is_private' => $isPrivate,
            ]);
            return $id;
        });
    }

    /* ----------------------------------------------------------------- links */

    /**
     * Links an outgoing mail as the reply to an incoming one, and closes the incoming mail.
     *
     * @throws NotFoundException|AccessDeniedException|RuleViolation
     */
    public function linkReply(Actor $actor, int $outgoingId, int $incomingId): void
    {
        $this->transaction->run(function () use ($actor, $outgoingId, $incomingId): void {
            $outgoing = $this->mails->findById($outgoingId) ?? throw NotFoundException::of('Mail', $outgoingId);
            $incoming = $this->mails->findById($incomingId) ?? throw NotFoundException::of('Mail', $incomingId);
            // Linking closes the incoming mail: the actor must be allowed to close it.
            if (!MailAccess::canPerform($actor->user, MailAction::Close, $this->isAssignee($actor, $incoming))) {
                throw new AccessDeniedException('Not allowed to close this mail.');
            }
            MailLinkRules::checkReply($outgoing, $incoming, $this->links->exists($outgoing->id, $incoming->id, LinkType::ReplyTo));

            $this->links->create($outgoing->id, $incoming->id, LinkType::ReplyTo, $incoming->siteId, $actor->user->id, $this->clock->now());
            $this->audit->event($actor, MailService::ENTITY, $outgoing->id, 'reply_linked', $outgoing->siteId, null, ['answers' => $incoming->reference]);
            $this->audit->event($actor, MailService::ENTITY, $incoming->id, 'reply_linked', $incoming->siteId, null, ['answered_by' => $outgoing->reference]);

            if (MailWorkflow::can($incoming->status, MailAction::Close)) {
                $this->changeStatus($actor, $incoming, MailWorkflow::apply($incoming->status, MailAction::Close));
            }
        });
    }

    /** Same as linkReply(), the incoming mail given by its reference (same site as the reply). */
    public function linkReplyByReference(Actor $actor, int $outgoingId, string $incomingReference): void
    {
        $outgoing = $this->mails->findById($outgoingId) ?? throw NotFoundException::of('Mail', $outgoingId);
        $incoming = $this->mails->findByReference($outgoing->siteId, strtoupper(trim($incomingReference)))
            ?? throw RuleViolation::single('reference', 'rules.link.not_found', ['reference' => $incomingReference]);
        $this->linkReply($actor, $outgoing->id, $incoming->id);
    }

    /**
     * Registers an outgoing reply to an incoming mail, links it and closes the incoming mail, atomically.
     *
     * @throws NotFoundException|AccessDeniedException|RuleViolation
     */
    public function createReply(Actor $actor, int $incomingId, MailInput $input): Mail
    {
        return $this->transaction->run(function () use ($actor, $incomingId, $input): Mail {
            $incoming = $this->mails->findById($incomingId) ?? throw NotFoundException::of('Mail', $incomingId);
            if ($incoming->direction !== Direction::Incoming) {
                throw RuleViolation::single('reference', 'rules.link.target_incoming');
            }
            $reply = $this->mailService->create($actor, Direction::Outgoing, $input, $incoming->siteId);
            $this->linkReply($actor, $reply->id, $incoming->id);
            return $reply;
        });
    }

    /* ----------------------------------------------------------------- reads */

    /** @return list<Assignment> */
    public function assignmentsFor(int $mailId): array
    {
        return $this->assignments->listForMail($mailId);
    }

    /** @return list<Annotation> */
    public function annotationsFor(Actor $actor, int $mailId): array
    {
        return $this->annotations->listVisible($mailId, $actor->user->id);
    }

    /** @return list<array{link_id: int, type: string, side: string, mail_id: int, reference: string, subject: string, status: string, direction: string}> */
    public function linksFor(int $mailId): array
    {
        return $this->links->linksFor($mailId);
    }

    /**
     * Actions to offer on the mail page: allowed by the workflow and to this actor.
     * "answer" is not offered: a mail is answered by linking a reply.
     *
     * @return list<MailAction>
     */
    public function availableActions(Actor $actor, Mail $mail): array
    {
        $isAssignee = $this->isAssignee($actor, $mail);
        return array_values(array_filter(
            MailWorkflow::availableActions($mail->status),
            static fn (MailAction $a): bool => $a !== MailAction::Answer && MailAccess::canPerform($actor->user, $a, $isAssignee),
        ));
    }

    /** @return list<array{id: int, site_id: int, department_id: ?int, name: string, role: string}> */
    public function assignableUsers(int $siteId): array
    {
        return array_values(array_filter($this->users->listActive(), static fn (array $u): bool => $u['site_id'] === $siteId));
    }

    /**
     * Ids of the users whose assignments $actor currently covers: themself and
     * the absent colleagues who delegated to them today.
     *
     * @return list<int>
     */
    public function coveredUserIds(Actor $actor): array
    {
        $ids = [$actor->user->id];
        foreach ($this->delegations->activeMap($actor->user->siteId, $this->today()) as $delegator => $delegate) {
            if ($delegate === $actor->user->id) {
                $ids[] = $delegator;
            }
        }
        return $ids;
    }

    /* --------------------------------------------------------------- helpers */

    private function loadFor(Actor $actor, int $mailId, MailAction $action): Mail
    {
        $mail = $this->mails->findById($mailId) ?? throw NotFoundException::of('Mail', $mailId);
        if (!MailAccess::canPerform($actor->user, $action, $this->isAssignee($actor, $mail))) {
            throw new AccessDeniedException("Not allowed to {$action->value} this mail.");
        }
        return $mail;
    }

    private function isAssignee(Actor $actor, Mail $mail): bool
    {
        return $this->assignments->isAssignee($mail->id, $this->coveredUserIds($actor), $actor->user->departmentId);
    }

    private function createAssignment(Actor $actor, Mail $mail, AssignmentRequest $request): Assignment
    {
        $errors = [];
        $userId = $request->userId;
        $delegatedFrom = null;
        if ($userId !== null) {
            $user = $this->users->findById($userId);
            if ($user === null || !$user->isActive || $user->siteId !== $mail->siteId) {
                $errors['user_id'][] = ['rules.assignment.user_invalid', []];
            } else {
                // Absent user: the assignment goes to their delegate.
                $resolved = DelegationResolver::resolve($userId, $this->delegations->activeMap($mail->siteId, $this->today()));
                $userId = $resolved['user_id'];
                $delegatedFrom = $resolved['delegated_from'];
            }
        }
        if ($request->departmentId !== null && $this->departments->findSiteId($request->departmentId) !== $mail->siteId) {
            $errors['department_id'][] = ['rules.assignment.department_invalid', []];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }

        $id = $this->assignments->create(
            $mail->id, $mail->siteId, $userId, $request->departmentId, $request->role,
            $request->instructions, $request->dueDate, $delegatedFrom, $actor->user->id, $this->clock->now(),
        );
        $assignment = $this->assignments->findById($id) ?? throw NotFoundException::of('Assignment', $id);

        if ($assignment->userId !== null && $assignment->userId !== $actor->user->id) {
            $this->notifications->notify($assignment->userId, NotificationType::Assigned, $mail->id, [
                'reference' => $mail->reference,
                'subject' => $mail->subject,
                'role' => $assignment->role->value,
                'due_date' => $assignment->dueDate ?? $mail->dueDate,
                'by' => $actor->user->fullName(),
                'delegated_from' => $assignment->delegatedFromName,
            ], 'assigned:' . $assignment->id);
        }
        return $assignment;
    }

    /** Applies a status change (no-op when unchanged), ends assignments when the mail is done, logs it. */
    private function changeStatus(Actor $actor, Mail $mail, MailStatus $to, ?string $comment = null): void
    {
        if ($to === $mail->status) {
            return;
        }
        $now = $this->clock->now();
        $closedAt = MailWorkflow::closedAt($mail->status, $to, $mail->closedAt, $now);
        $this->mails->updateStatus($mail->id, $to, $closedAt, $actor->user->id);
        if ($to === MailStatus::Closed || $to === MailStatus::Answered) {
            $this->assignments->endAllActive($mail->id, AssignmentStatus::Completed, $now, $actor->user->id);
        }

        $new = ['status' => $to->value];
        if ($comment !== null && $comment !== '') {
            $new['comment'] = $comment;
        }
        $this->audit->event($actor, MailService::ENTITY, $mail->id, 'status_change', $mail->siteId, ['status' => $mail->status->value], $new);
    }

    private function today(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }
}
