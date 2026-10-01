<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Core\TableRequest;
use App\Domain\AccessDeniedException;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Assignment\AssignmentStatus;
use App\Domain\Audit\Actor;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\RuleViolation;
use App\Domain\SiteScope;
use App\Services\MailService;
use App\Services\WorkflowService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\ServiceFactory;
use Tests\Support\TestDatabase;

final class WorkflowServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private ServiceFactory $factory;
    private int $site;
    private int $correspondent;
    private Actor $secretary;
    private Actor $agentA;
    private Actor $agentB;
    private Actor $director;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-09-30 10:00:00');
        $this->factory = new ServiceFactory($this->pdo, $this->clock);
        $this->site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($this->site, 'Mairie');
        $this->secretary = $this->factory->actor(TestDatabase::insertUser($this->site, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agentA = $this->factory->actor(TestDatabase::insertUser($this->site, 'a@example.org', 'password-123456', 'agent'));
        $this->agentB = $this->factory->actor(TestDatabase::insertUser($this->site, 'b@example.org', 'password-123456', 'agent'));
        $this->director = $this->factory->actor(TestDatabase::insertUser($this->site, 'dir@example.org', 'password-123456', 'management'));
    }

    private function workflow(?Actor $actor = null): WorkflowService
    {
        return $this->factory->workflow(SiteScope::forUser(($actor ?? $this->secretary)->user));
    }

    private function input(Direction $direction): MailInput
    {
        $at = new DateTimeImmutable('2026-09-30 08:00:00', new DateTimeZone('UTC'));
        return new MailInput('Objet', null, $this->correspondent, null, Channel::Postal, Priority::Normal, Confidentiality::Internal,
            null, $direction === Direction::Incoming ? $at : null, $direction === Direction::Outgoing ? $at : null, null, null);
    }

    private function newMail(Direction $direction = Direction::Incoming): Mail
    {
        return $this->factory->mail(SiteScope::forUser($this->secretary->user))->create($this->secretary, $direction, $this->input($direction));
    }

    private function mailStatus(int $mailId): string
    {
        return (string) $this->pdo->query("SELECT status FROM mails WHERE id = {$mailId}")->fetchColumn();
    }

    private function forAction(Actor $to): AssignmentRequest
    {
        return new AssignmentRequest($to->user->id, null, AssignmentRole::ForAction, 'Traiter', '2026-10-10');
    }

    /** @return list<string> */
    private function logActions(int $mailId): array
    {
        return array_column($this->pdo->query("SELECT action FROM activity_log WHERE entity_type = 'mail' AND entity_id = {$mailId} ORDER BY id")->fetchAll(), 'action');
    }

    public function testAssignMovesRegisteredToAssignedAndIsLogged(): void
    {
        $mail = $this->newMail();
        $assignment = $this->workflow()->assign($this->secretary, $mail->id, $this->forAction($this->agentA));

        self::assertSame($this->agentA->user->id, $assignment->userId);
        self::assertSame('Traiter', $assignment->instructions);
        self::assertSame('assigned', $this->mailStatus($mail->id));
        self::assertSame(['create', 'assigned', 'status_change'], $this->logActions($mail->id));

        // A second owner is refused; a copy for information is fine and keeps the status.
        try {
            $this->workflow()->assign($this->secretary, $mail->id, $this->forAction($this->agentB));
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertArrayHasKey('role', $e->violations());
        }
        $this->workflow()->assign($this->secretary, $mail->id, new AssignmentRequest(null, TestDatabase::insertDepartment($this->site, 'RH'), AssignmentRole::ForInformation));
        self::assertCount(2, $this->workflow()->assignmentsFor($mail->id));
        self::assertSame('assigned', $this->mailStatus($mail->id));
    }

    public function testAssignmentTargetsMustBelongToTheMailSite(): void
    {
        $mail = $this->newMail();
        $otherSite = TestDatabase::insertSite('B');
        $foreignUser = TestDatabase::insertUser($otherSite, 'x@example.org', 'password-123456', 'agent');
        $foreignDept = TestDatabase::insertDepartment($otherSite, 'IT');
        try {
            $this->workflow()->assign($this->secretary, $mail->id, new AssignmentRequest($foreignUser, $foreignDept, AssignmentRole::ForAction));
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertSame(['user_id', 'department_id'], array_keys($e->violations()));
        }
        self::assertSame('registered', $this->mailStatus($mail->id), 'rolled back');
    }

    public function testReassignEndsPreviousOwner(): void
    {
        $mail = $this->newMail();
        $wf = $this->workflow();
        $first = $wf->assign($this->secretary, $mail->id, $this->forAction($this->agentA));
        $this->workflow($this->agentA)->perform($this->agentA, $mail->id, MailAction::Start);
        self::assertSame('in_progress', $this->mailStatus($mail->id));

        $second = $wf->reassign($this->secretary, $mail->id, $this->forAction($this->agentB), 'Congés');

        self::assertSame('assigned', $this->mailStatus($mail->id), 'back to assigned for the new owner');
        $all = $wf->assignmentsFor($mail->id);
        $byId = array_combine(array_map(static fn ($a) => $a->id, $all), $all);
        self::assertSame(AssignmentStatus::Reassigned, $byId[$first->id]->status);
        self::assertTrue($byId[$second->id]->isActive());

        $log = $this->pdo->query("SELECT old_values, new_values FROM activity_log WHERE action = 'reassigned'")->fetch();
        self::assertSame($this->agentA->user->id, json_decode($log['old_values'], true)['user_id']);
        self::assertSame($this->agentB->user->id, json_decode($log['new_values'], true)['user_id']);
        self::assertSame('Congés', json_decode($log['new_values'], true)['comment']);

        // Agent A no longer holds the mail.
        $this->expectException(AccessDeniedException::class);
        $this->workflow($this->agentA)->perform($this->agentA, $mail->id, MailAction::Start);
    }

    public function testAgentsActOnlyOnTheirMail(): void
    {
        $mail = $this->newMail();
        $this->workflow()->assign($this->secretary, $mail->id, $this->forAction($this->agentA));

        try {
            $this->workflow($this->agentB)->perform($this->agentB, $mail->id, MailAction::Start);
            self::fail('Agent B is not assigned');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->workflow($this->director)->perform($this->director, $mail->id, MailAction::Close);
            self::fail('Management does not process mail');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([MailAction::Start, MailAction::Close], $this->workflow($this->agentA)->availableActions($this->agentA, $this->reload($mail)));
        self::assertSame([], $this->workflow($this->agentB)->availableActions($this->agentB, $this->reload($mail)));

        $this->workflow($this->agentA)->perform($this->agentA, $mail->id, MailAction::Start);
        $this->workflow($this->agentA)->perform($this->agentA, $mail->id, MailAction::AwaitReply);
        self::assertSame('awaiting_reply', $this->mailStatus($mail->id));
    }

    private function reload(Mail $mail): Mail
    {
        return $this->factory->mail(SiteScope::system())->find($mail->id);
    }

    public function testCloseEndsAssignmentsReopenAndArchive(): void
    {
        $mail = $this->newMail();
        $wf = $this->workflow();
        $wf->assign($this->secretary, $mail->id, $this->forAction($this->agentA));

        $closed = $this->workflow($this->agentA)->perform($this->agentA, $mail->id, MailAction::Close, 'Réglé par téléphone');
        self::assertSame(MailStatus::Closed, $closed->status);
        self::assertEquals($this->clock->now(), $closed->closedAt);
        self::assertSame(AssignmentStatus::Completed, $wf->assignmentsFor($mail->id)[0]->status);
        $log = $this->pdo->query("SELECT old_values, new_values FROM activity_log WHERE action = 'status_change' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['status' => 'assigned'], json_decode($log['old_values'], true));
        self::assertSame(['status' => 'closed', 'comment' => 'Réglé par téléphone'], json_decode($log['new_values'], true));

        $reopened = $wf->perform($this->secretary, $mail->id, MailAction::Reopen);
        self::assertSame(MailStatus::InProgress, $reopened->status);
        self::assertNull($reopened->closedAt);

        try {
            $wf->perform($this->secretary, $mail->id, MailAction::Archive);
            self::fail('Archive needs a closed mail');
        } catch (RuleViolation $e) {
            self::assertSame('rules.workflow.not_allowed', $e->violations()['status'][0][0]);
        }
        $wf->perform($this->secretary, $mail->id, MailAction::Close);
        $archived = $wf->perform($this->secretary, $mail->id, MailAction::Archive);
        self::assertSame(MailStatus::Archived, $archived->status);
        self::assertNotNull($archived->closedAt);
        self::assertSame([], $wf->availableActions($this->secretary, $archived));
    }

    public function testDelegationRedirectsNewAssignmentsAndCoversExistingOnes(): void
    {
        $existing = $this->newMail();
        $this->workflow()->assign($this->secretary, $existing->id, $this->forAction($this->agentA));

        // Agent A is absent today and tomorrow; agent B replaces them.
        $this->factory->delegation(SiteScope::forUser($this->agentA->user))
            ->create($this->agentA, null, $this->agentB->user->id, '2026-09-30', '2026-10-01', 'Congés');

        $new = $this->newMail();
        $assignment = $this->workflow()->assign($this->secretary, $new->id, $this->forAction($this->agentA));
        self::assertSame($this->agentB->user->id, $assignment->userId, 'the delegate receives it');
        self::assertSame($this->agentA->user->id, $assignment->delegatedFromUserId);

        // B can also handle A's mail assigned before the absence.
        $this->workflow($this->agentB)->perform($this->agentB, $existing->id, MailAction::Start);
        self::assertSame('in_progress', $this->mailStatus($existing->id));

        // "My mail" for B includes A's mail during the absence.
        $table = TableRequest::fromRequest(new Request('GET', '/'), MailService::sortKeys(), 'mail_date');
        $mine = $this->factory->mail(SiteScope::forUser($this->agentB->user))
            ->page(new MailFilter(assignedToUserIds: $this->workflow($this->agentB)->coveredUserIds($this->agentB)), $table);
        self::assertSame(2, $mine->filtered);

        // After the absence, new assignments go to A again.
        $this->clock->advance('+2 days');
        $later = $this->newMail();
        self::assertSame($this->agentA->user->id, $this->workflow()->assign($this->secretary, $later->id, $this->forAction($this->agentA))->userId);
    }

    public function testDelegationPermissionsAndOverlap(): void
    {
        $delegations = $this->factory->delegation(SiteScope::forUser($this->agentA->user));
        try {
            $delegations->create($this->agentA, $this->agentB->user->id, $this->secretary->user->id, '2026-10-01', '2026-10-02', null);
            self::fail('An agent cannot declare an absence for a colleague');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $bySecretary = $this->factory->delegation(SiteScope::forUser($this->secretary->user))
            ->create($this->secretary, $this->agentB->user->id, $this->agentA->user->id, '2026-10-01', '2026-10-05', 'Formation');
        self::assertSame($this->agentB->user->id, $bySecretary->delegatorId);

        try {
            $delegations->create($this->agentB, null, $this->secretary->user->id, '2026-10-03', '2026-10-04', null);
            self::fail('Overlap');
        } catch (RuleViolation $e) {
            self::assertArrayHasKey('starts_on', $e->violations());
        }

        // The absent person may cancel it; the log keeps create + cancel.
        $this->factory->delegation(SiteScope::forUser($this->agentB->user))->cancel($this->agentB, $bySecretary->id);
        self::assertSame(['create', 'cancel'], array_column($this->pdo->query("SELECT action FROM activity_log WHERE entity_type = 'delegation' ORDER BY id")->fetchAll(), 'action'));
    }

    public function testReplyLinkClosesTheIncomingMail(): void
    {
        $incoming = $this->newMail();
        $this->workflow()->assign($this->secretary, $incoming->id, $this->forAction($this->agentA));
        $outgoing = $this->newMail(Direction::Outgoing);

        $this->workflow()->linkReplyByReference($this->secretary, $outgoing->id, strtolower($incoming->reference));

        self::assertSame('closed', $this->mailStatus($incoming->id));
        self::assertSame(AssignmentStatus::Completed, $this->workflow()->assignmentsFor($incoming->id)[0]->status);
        $links = $this->workflow()->linksFor($incoming->id);
        self::assertSame([['inbound', $outgoing->reference]], array_map(static fn ($l) => [$l['side'], $l['reference']], $links));
        self::assertSame('outbound', $this->workflow()->linksFor($outgoing->id)[0]['side']);
        self::assertContains('reply_linked', $this->logActions($incoming->id));
        self::assertContains('reply_linked', $this->logActions($outgoing->id));

        // Same link twice, wrong direction, unknown reference.
        foreach ([
            [$outgoing->id, $incoming->reference, 'rules.link.duplicate'],
            [$incoming->id, $outgoing->reference, 'rules.link.source_outgoing'],
            [$outgoing->id, 'ENT-2026-09999', 'rules.link.not_found'],
        ] as [$source, $reference, $expected]) {
            try {
                $this->workflow()->linkReplyByReference($this->secretary, $source, $reference);
                self::fail("Expected {$expected}");
            } catch (RuleViolation $e) {
                self::assertContains($expected, array_column($e->violations()['reference'], 0));
            }
        }
    }

    public function testCreateReplyIsAtomic(): void
    {
        $incoming = $this->newMail();
        $reply = $this->workflow()->createReply($this->secretary, $incoming->id, $this->input(Direction::Outgoing));
        self::assertSame('SOR-2026-00001', $reply->reference);
        self::assertSame('closed', $this->mailStatus($incoming->id));

        // Archived incoming mail: the reply is refused and nothing is left behind (no mail, no number).
        $this->workflow()->perform($this->secretary, $incoming->id, MailAction::Archive);
        try {
            $this->workflow()->createReply($this->secretary, $incoming->id, $this->input(Direction::Outgoing));
            self::fail('Expected RuleViolation');
        } catch (RuleViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM mails WHERE direction = 'outgoing'")->fetchColumn());
        self::assertSame('SOR-2026-00002', $this->newMail(Direction::Outgoing)->reference);
    }

    public function testPrivateAnnotationsAreVisibleToTheirAuthorOnly(): void
    {
        $mail = $this->newMail();
        $this->workflow($this->agentA)->annotate($this->agentA, $mail->id, 'Note partagée', false);
        $this->workflow($this->agentA)->annotate($this->agentA, $mail->id, 'Ma note perso', true);

        self::assertSame(['Note partagée', 'Ma note perso'], array_map(static fn ($n) => $n->body, $this->workflow($this->agentA)->annotationsFor($this->agentA, $mail->id)));
        self::assertSame(['Note partagée'], array_map(static fn ($n) => $n->body, $this->workflow($this->agentB)->annotationsFor($this->agentB, $mail->id)));

        $log = $this->pdo->query("SELECT new_values FROM activity_log WHERE action = 'annotation_added' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringNotContainsString('Ma note perso', (string) $log, 'private text stays out of the shared history');
    }
}
