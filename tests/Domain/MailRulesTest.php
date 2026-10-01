<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailReference;
use App\Domain\Mail\MailRules;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\RuleViolation;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailRulesTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-30 10:00:00', new \DateTimeZone('UTC'));
    }

    private function input(array $over = []): MailInput
    {
        $args = array_merge([
            'subject' => 'Facture',
            'summary' => null,
            'correspondentId' => 1,
            'departmentId' => null,
            'channel' => Channel::Postal,
            'priority' => Priority::Normal,
            'confidentiality' => Confidentiality::Internal,
            'documentDate' => '2026-09-28',
            'receivedAt' => $this->now->modify('-1 hour'),
            'sentAt' => null,
            'dueDate' => '2026-10-15',
            'externalReference' => null,
        ], $over);
        return new MailInput(...$args);
    }

    private static function mail(MailStatus $status): Mail
    {
        $at = new DateTimeImmutable('2026-01-01');
        return new Mail(1, 1, Direction::Incoming, 'ENT-2026-00001', 2026, 1, 's', null, 1, null, Channel::Postal,
            Priority::Normal, Confidentiality::Internal, $status, null, $at, null, null, null, null, 1, null, $at, $at);
    }

    /** @return array<string, list<string>> field => keys */
    private function violations(callable $check): array
    {
        try {
            $check();
        } catch (RuleViolation $e) {
            return array_map(static fn (array $items): array => array_column($items, 0), $e->violations());
        }
        return [];
    }

    public function testValidIncomingMailPasses(): void
    {
        MailRules::check(Direction::Incoming, $this->input(), $this->now, '2026-09-30');
        $this->addToAssertionCount(1);
    }

    public function testIncomingNeedsReceptionDateAndNoSendingDate(): void
    {
        $v = $this->violations(fn () => MailRules::check(Direction::Incoming, $this->input(['receivedAt' => null, 'sentAt' => $this->now]), $this->now, '2026-09-30'));
        self::assertSame(['rules.mail.received_at_required'], $v['received_at']);
        self::assertSame(['rules.mail.sent_at_incoming'], $v['sent_at']);
    }

    public function testOutgoingHasNoReceptionDate(): void
    {
        $v = $this->violations(fn () => MailRules::check(Direction::Outgoing, $this->input(), $this->now, '2026-09-30'));
        self::assertSame(['rules.mail.received_at_outgoing'], $v['received_at']);
        MailRules::check(Direction::Outgoing, $this->input(['receivedAt' => null, 'sentAt' => null]), $this->now, '2026-09-30');
    }

    public function testDatesCannotBeInTheFutureBeyondTolerance(): void
    {
        MailRules::check(Direction::Incoming, $this->input(['receivedAt' => $this->now->modify('+4 minutes')]), $this->now, '2026-09-30');
        $v = $this->violations(fn () => MailRules::check(Direction::Incoming, $this->input([
            'receivedAt' => $this->now->modify('+1 day'),
            'documentDate' => '2026-10-01',
        ]), $this->now, '2026-09-30'));
        self::assertSame(['rules.mail.not_in_future'], $v['received_at']);
        self::assertSame(['rules.mail.not_in_future'], $v['document_date']);
    }

    public function testDueDateNotBeforeDocumentDate(): void
    {
        $v = $this->violations(fn () => MailRules::check(Direction::Incoming, $this->input(['dueDate' => '2026-09-01']), $this->now, '2026-09-30'));
        self::assertSame(['rules.mail.due_before_document'], $v['due_date']);
    }

    public function testArchivedMailIsFrozen(): void
    {
        $v = $this->violations(fn () => MailRules::check(Direction::Incoming, $this->input(), $this->now, '2026-09-30', self::mail(MailStatus::Archived)));
        self::assertSame(['rules.mail.archived'], $v['status']);
    }

    public function testOverdue(): void
    {
        $mail = self::mail(MailStatus::InProgress);
        $due = new \ReflectionProperty(Mail::class, 'dueDate');
        $withDue = (new \ReflectionClass(Mail::class))->newInstanceWithoutConstructor();
        foreach ((new \ReflectionClass(Mail::class))->getProperties() as $p) {
            $p->setValue($withDue, $p->getName() === 'dueDate' ? '2026-09-29' : $p->getValue($mail));
        }
        self::assertTrue($withDue->isOverdue('2026-09-30'));
        self::assertFalse($withDue->isOverdue('2026-09-29'));
        self::assertFalse($mail->isOverdue('2026-09-30'), 'no due date');
        self::assertSame(['answered', 'closed', 'archived'], MailStatus::finishedValues());
        unset($due);
    }

    public function testReferenceFormat(): void
    {
        self::assertSame('ENT-2026-00001', MailReference::format(Direction::Incoming, 2026, 1));
        self::assertSame('SOR-2027-00042', MailReference::format(Direction::Outgoing, 2027, 42));
        self::assertSame('ENT-2026-123456', MailReference::format(Direction::Incoming, 2026, 123456));
        self::assertSame(['direction' => Direction::Outgoing, 'year' => 2027, 'number' => 42], MailReference::parse('SOR-2027-00042'));
        self::assertNull(MailReference::parse('ENT-26-1'));

        $this->expectException(InvalidArgumentException::class);
        MailReference::format(Direction::Incoming, 2026, 0);
    }
}
