<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\BulkRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Domain\AccessDeniedException;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Mail\MailAction;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\WorkflowService;

/** Actions posted from the mail page; each redirects back to it with a message. */
final class WorkflowController
{
    use HandlesDomain;

    private const ROUTE_ACTIONS = ['start', 'await_reply', 'close', 'reopen', 'archive'];

    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly Url $url,
    ) {
    }

    public function assign(Request $request): Response
    {
        return $this->run($request, 'assignments', function () use ($request): string {
            $this->workflow->assign($this->actor($request), $this->routeId($request), $this->assignmentRequest($request, false));
            return $this->translator->get('workflow.assigned');
        });
    }

    public function reassign(Request $request): Response
    {
        return $this->run($request, 'assignments', function () use ($request): string {
            $comment = self::nullIfEmpty($this->validator->validate($request->all(), ['comment' => 'nullable|string|max:1000'])['comment']);
            $this->workflow->reassign($this->actor($request), $this->routeId($request), $this->assignmentRequest($request, true), $comment);
            return $this->translator->get('workflow.reassigned');
        });
    }

    /** POST /mails/{id}/actions/{action}: start, await_reply, close, reopen, archive. */
    public function action(Request $request): Response
    {
        $action = in_array($request->param('action'), self::ROUTE_ACTIONS, true) ? MailAction::from((string) $request->param('action')) : null;
        if ($action === null) {
            throw HttpException::notFound();
        }
        return $this->run($request, 'workflow', function () use ($request, $action): string {
            $comment = self::nullIfEmpty($this->validator->validate($request->all(), ['comment' => 'nullable|string|max:1000'])['comment']);
            $this->workflow->perform($this->actor($request), $this->routeId($request), $action, $comment);
            return $this->translator->get('workflow.done.' . $action->value);
        });
    }

    public function annotate(Request $request): Response
    {
        return $this->run($request, 'annotations', function () use ($request): string {
            $data = $this->validator->validate($request->all(), [
                'body' => 'required|string|max:5000',
                'is_private' => 'nullable|bool',
            ], ['body' => 'annotation.body']);
            $this->workflow->annotate($this->actor($request), $this->routeId($request), $data['body'], $data['is_private'] ?? false);
            return $this->translator->get('annotation.added');
        });
    }

    /** Links this (outgoing) mail as the reply to the incoming mail given by reference. */
    public function linkReply(Request $request): Response
    {
        return $this->run($request, 'links', function () use ($request): string {
            $data = $this->validator->validate($request->all(), ['reference' => 'required|string|max:20'], ['reference' => 'link.reference']);
            $this->workflow->linkReplyByReference($this->actor($request), $this->routeId($request), $data['reference']);
            return $this->translator->get('link.linked', ['reference' => strtoupper($data['reference'])]);
        });
    }

    /** POST /mails/bulk/assign (JSON): assigns the mails selected in the list. */
    public function bulkAssign(Request $request): Response
    {
        $ids = $this->bulkIds($request);
        $comment = self::nullIfEmpty($this->validator->validate($request->all(), ['comment' => 'nullable|string|max:1000'])['comment']);
        $assignment = $this->assignmentRequest($request, false);
        if ($assignment->userId === null && $assignment->departmentId === null) {
            // Refused once, in the dialog, rather than once per selected mail.
            throw new ValidationException(['user_id' => [$this->translator->get('rules.assignment.target_required')]]);
        }
        try {
            $result = $this->workflow->assignMany($this->actor($request), $ids, $assignment, $comment);
        } catch (RuleViolation $e) {
            throw new ValidationException($this->violationMessages($e));
        }
        return $this->bulkResponse($result);
    }

    /** POST /mails/bulk/close (JSON): closes the mails selected in the list. */
    public function bulkClose(Request $request): Response
    {
        $ids = $this->bulkIds($request);
        $comment = self::nullIfEmpty($this->validator->validate($request->all(), ['comment' => 'nullable|string|max:1000'])['comment']);
        return $this->bulkResponse($this->workflow->performMany($this->actor($request), $ids, MailAction::Close, $comment));
    }

    /** @return list<int> */
    private function bulkIds(Request $request): array
    {
        $ids = BulkRequest::ids($request->input('ids'));
        if ($ids === []) {
            throw new ValidationException(['ids' => [$this->translator->get('bulk.none_selected')]]);
        }
        return $ids;
    }

    /**
     * @param array{done: list<int>, failed: array<int, \Throwable>} $result
     */
    private function bulkResponse(array $result): Response
    {
        $failed = [];
        foreach ($result['failed'] as $mailId => $error) {
            $failed[] = ['id' => $mailId, 'message' => match (true) {
                $error instanceof RuleViolation => implode(' ', array_merge(...array_values($this->violationMessages($error)))),
                $error instanceof AccessDeniedException => $this->translator->get('bulk.forbidden'),
                default => $this->translator->get('bulk.not_found'),
            }];
        }
        return Response::json(['done' => $result['done'], 'failed' => $failed]);
    }

    /** @param \Closure(): string $work returns the success message */
    private function run(Request $request, string $anchor, \Closure $work): Response
    {
        try {
            $this->session->flash('flash.success', $this->orNotFound($work));
        } catch (ValidationException | RuleViolation $e) {
            $this->flashErrors($e);
        }
        return Response::redirect($this->url->route('mails.show', ['id' => $this->routeId($request)]) . '#' . $anchor);
    }

    private function assignmentRequest(Request $request, bool $reassign): AssignmentRequest
    {
        $data = $this->validator->validate($request->all(), [
            'user_id' => 'nullable|int|min:1',
            'department_id' => 'nullable|int|min:1',
            'role' => ($reassign ? 'nullable' : 'required') . '|in:' . implode(',', array_column(AssignmentRole::cases(), 'value')),
            'instructions' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
        ], [
            'user_id' => 'assignment.fields.user_id',
            'department_id' => 'assignment.fields.department_id',
            'role' => 'assignment.fields.role',
            'instructions' => 'assignment.fields.instructions',
            'due_date' => 'assignment.fields.due_date',
        ]);
        return new AssignmentRequest(
            userId: $data['user_id'],
            departmentId: $data['department_id'],
            role: $reassign ? AssignmentRole::ForAction : AssignmentRole::from($data['role']),
            instructions: self::nullIfEmpty($data['instructions']),
            dueDate: $data['due_date'],
        );
    }
}
