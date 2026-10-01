<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Attachment\Attachment;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Correspondent\Correspondent;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;
use App\Domain\Delegation\Delegation;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\NotFoundException;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationType;
use App\Domain\OutOfScopeException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Small behaviours of Domain objects not covered by the rule tests. */
final class DomainModelTest extends TestCase
{
    private static function mail(MailStatus $status, ?string $due = '2026-10-15'): Mail
    {
        $at = new DateTimeImmutable('2026-09-01 08:00:00');
        return new Mail(1, 1, Direction::Incoming, 'ENT-2026-00001', 2026, 1, 'Objet', null, 3, null, Channel::Email, Priority::High,
            Confidentiality::Secret, $status, '2026-08-30', $at, null, $due, 'EXT-1', null, 1, null, $at, $at);
    }

    public function testMailEditabilityAndAuditSnapshot(): void
    {
        self::assertTrue(self::mail(MailStatus::Closed)->isEditable());
        self::assertFalse(self::mail(MailStatus::Archived)->isEditable());
        self::assertSame([
            'subject' => 'Objet', 'summary' => null, 'correspondent_id' => 3, 'department_id' => null, 'channel' => 'email',
            'priority' => 'high', 'confidentiality' => 'secret', 'status' => 'in_progress', 'document_date' => '2026-08-30',
            'received_at' => '2026-09-01 08:00:00', 'sent_at' => null, 'due_date' => '2026-10-15', 'external_reference' => 'EXT-1',
        ], self::mail(MailStatus::InProgress)->auditValues());
    }

    public function testMailInputWithDueDateKeepsEverythingElse(): void
    {
        $input = new MailInput('S', 'R', 1, 2, Channel::Fax, Priority::Low, Confidentiality::Public, '2026-09-01', null, null, null, 'X');
        $copy = $input->withDueDate('2026-10-01');
        self::assertSame('2026-10-01', $copy->dueDate);
        self::assertNull($input->dueDate, 'immutable');
        self::assertSame([$input->subject, $input->summary, $input->departmentId, $input->channel, $input->externalReference],
            [$copy->subject, $copy->summary, $copy->departmentId, $copy->channel, $copy->externalReference]);
    }

    public function testEnumsHelpers(): void
    {
        self::assertSame(['ENT', 'SOR'], [Direction::Incoming->prefix(), Direction::Outgoing->prefix()]);
        self::assertTrue(Confidentiality::Secret->masksSubjectInDocuments());
        self::assertFalse(Confidentiality::Confidential->masksSubjectInDocuments());
        self::assertTrue(MailStatus::Answered->isFinished());
        self::assertFalse(MailStatus::AwaitingReply->isFinished());
        foreach (NotificationType::cases() as $type) {
            self::assertMatchesRegularExpression('/^[a-z-]+$/', $type->icon(), 'Lucide icon name');
        }
        foreach (Role::cases() as $role) {
            self::assertNotEmpty($role->permissions());
        }
    }

    public function testPeopleAndCorrespondents(): void
    {
        self::assertSame('Ana Martin', (new User(1, 1, null, Role::Agent, 'a@b.fr', 'x', 'Ana', 'Martin'))->fullName());
        self::assertSame('Ana', (new User(1, 1, null, Role::Agent, 'a@b.fr', 'x', 'Ana', ''))->fullName());

        $person = new Correspondent(1, 1, CorrespondentType::Person, 'Jean Dupont', 'Mairie', null, null, null, null, null, null, 'FR', null, true);
        $org = new Correspondent(2, 1, CorrespondentType::Organization, 'Mairie', 'Mairie', null, null, null, null, null, null, 'FR', null, true);
        self::assertSame('Jean Dupont (Mairie)', $person->displayName());
        self::assertSame('Mairie', $org->displayName());

        $input = new CorrespondentInput(CorrespondentType::Person, 'Jean Dupont', 'Mairie', null, null, null, null, null, null, 'FR', null);
        self::assertSame($person->auditValues(), $input->auditValues(), 'same keys and values: diffs compare like with like');
    }

    public function testSmallValueObjects(): void
    {
        $d = new Delegation(1, 1, 2, 'A', 3, 'B', '2026-10-01', '2026-10-02', 'Congés', 2, null);
        self::assertFalse($d->isCancelled());
        self::assertSame(['delegator_id' => 2, 'delegate_id' => 3, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'reason' => 'Congés'], $d->auditValues());

        $n = new Notification(1, 1, NotificationType::Overdue, 3, [], null, new DateTimeImmutable());
        self::assertFalse($n->isRead());

        $a = new Attachment(1, 1, 1, 'a.pdf', 'p', 'application/pdf', 1, str_repeat('0', 64), 1, new DateTimeImmutable());
        self::assertFalse($a->isPurged());

        self::assertSame('Mail #4 not found.', NotFoundException::of('Mail', 4)->getMessage());
        self::assertStringContainsString('Site 9', OutOfScopeException::forSite(9)->getMessage());
        self::assertStringContainsString('unrestricted', OutOfScopeException::forSite(null)->getMessage());
    }
}
