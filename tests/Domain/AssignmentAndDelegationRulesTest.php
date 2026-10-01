<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Annotation\Annotation;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Assignment\AssignmentRules;
use App\Domain\Delegation\Delegation;
use App\Domain\Delegation\DelegationResolver;
use App\Domain\Delegation\DelegationRules;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailLinkRules;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\RuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AssignmentAndDelegationRulesTest extends TestCase
{
    /** @return array<string, list<string>> */
    private static function violations(callable $fn): array
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return array_map(static fn (array $items): array => array_column($items, 0), $e->violations());
        }
        return [];
    }

    /* ------------------------------------------------------------ assignment */

    public function testAssignmentNeedsATargetAndAFutureDueDate(): void
    {
        $v = self::violations(fn () => AssignmentRules::check(new AssignmentRequest(null, null, AssignmentRole::ForAction, null, '2026-09-01'), '2026-09-30', false, false));
        self::assertSame(['rules.assignment.target_required'], $v['user_id']);
        self::assertSame(['rules.assignment.due_in_past'], $v['due_date']);

        self::assertSame([], self::violations(fn () => AssignmentRules::check(new AssignmentRequest(null, 3, AssignmentRole::ForAction, null, '2026-09-30'), '2026-09-30', false, false)));
    }

    public function testOnlyOneOwnerAtATime(): void
    {
        $forAction = new AssignmentRequest(5, null, AssignmentRole::ForAction);
        self::assertSame(['rules.assignment.already_assigned'], self::violations(fn () => AssignmentRules::check($forAction, '2026-09-30', true, false))['role']);
        // Copies for information are unlimited.
        self::assertSame([], self::violations(fn () => AssignmentRules::check(new AssignmentRequest(5, null, AssignmentRole::ForInformation), '2026-09-30', true, false)));
    }

    public function testReassignmentRules(): void
    {
        self::assertSame([], self::violations(fn () => AssignmentRules::check(new AssignmentRequest(5, null, AssignmentRole::ForAction), '2026-09-30', true, true)));
        self::assertSame(['rules.assignment.nothing_to_reassign'], self::violations(fn () => AssignmentRules::check(new AssignmentRequest(5, null, AssignmentRole::ForAction), '2026-09-30', false, true))['user_id']);
        self::assertSame(['rules.assignment.reassign_for_action'], self::violations(fn () => AssignmentRules::check(new AssignmentRequest(5, null, AssignmentRole::ForInformation), '2026-09-30', true, true))['role']);
    }

    /* ------------------------------------------------------------ delegation */

    private static function delegation(string $from, string $to, bool $cancelled = false): Delegation
    {
        return new Delegation(1, 1, 10, 'A', 20, 'B', $from, $to, null, 10, $cancelled ? new DateTimeImmutable() : null);
    }

    public function testDelegationPeriod(): void
    {
        $d = self::delegation('2026-10-01', '2026-10-10');
        self::assertFalse($d->isActiveOn('2026-09-30'));
        self::assertTrue($d->isActiveOn('2026-10-01'));
        self::assertTrue($d->isActiveOn('2026-10-10'));
        self::assertFalse($d->isActiveOn('2026-10-11'));
        self::assertTrue($d->overlaps('2026-10-10', '2026-10-12'));
        self::assertFalse($d->overlaps('2026-10-11', '2026-10-12'));
        self::assertFalse(self::delegation('2026-10-01', '2026-10-10', cancelled: true)->isActiveOn('2026-10-05'));
    }

    public function testDelegationRules(): void
    {
        $check = static fn (int $delegate, int $delegateSite, bool $active, string $from, string $to, array $existing = []) =>
            self::violations(fn () => DelegationRules::check(10, 1, $delegate, $delegateSite, $active, $from, $to, '2026-09-30', $existing));

        self::assertSame([], $check(20, 1, true, '2026-09-30', '2026-10-05'));
        self::assertSame(['rules.delegation.same_user'], $check(10, 1, true, '2026-10-01', '2026-10-05')['delegate_id']);
        self::assertSame(['rules.delegation.other_site', 'rules.delegation.inactive'], $check(20, 2, false, '2026-10-01', '2026-10-05')['delegate_id']);
        self::assertSame(['rules.delegation.end_before_start'], $check(20, 1, true, '2026-10-05', '2026-10-01')['ends_on']);
        self::assertSame(['rules.delegation.in_past'], $check(20, 1, true, '2026-09-01', '2026-09-29')['ends_on']);
        self::assertSame(['rules.delegation.too_long'], $check(20, 1, true, '2026-10-01', '2027-10-05')['ends_on']);
        self::assertSame(['rules.delegation.overlap'], $check(20, 1, true, '2026-10-04', '2026-10-08', [self::delegation('2026-10-01', '2026-10-05')])['starts_on']);
        self::assertSame([], $check(20, 1, true, '2026-10-04', '2026-10-08', [self::delegation('2026-10-01', '2026-10-05', cancelled: true)]), 'cancelled ones do not count');
    }

    public function testResolverFollowsChainsAndStopsOnCycles(): void
    {
        self::assertSame(['user_id' => 7, 'delegated_from' => null], DelegationResolver::resolve(7, [1 => 2]));
        self::assertSame(['user_id' => 2, 'delegated_from' => 1], DelegationResolver::resolve(1, [1 => 2]));
        self::assertSame(['user_id' => 3, 'delegated_from' => 1], DelegationResolver::resolve(1, [1 => 2, 2 => 3]));
        // A → B → A: stays on B (the last user before looping back).
        self::assertSame(['user_id' => 2, 'delegated_from' => 1], DelegationResolver::resolve(1, [1 => 2, 2 => 1]));
        // Depth is bounded.
        $chain = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8];
        self::assertSame(6, DelegationResolver::resolve(1, $chain)['user_id']);
    }

    /* ------------------------------------------------------ links, annotations */

    private static function mail(int $id, Direction $direction, int $site = 1, MailStatus $status = MailStatus::InProgress): Mail
    {
        $at = new DateTimeImmutable('2026-01-01');
        return new Mail($id, $site, $direction, 'X', 2026, $id, 's', null, 1, null, Channel::Postal, Priority::Normal,
            Confidentiality::Internal, $status, null, null, null, null, null, null, 1, null, $at, $at);
    }

    public function testReplyLinkRules(): void
    {
        $out = self::mail(1, Direction::Outgoing);
        $in = self::mail(2, Direction::Incoming);
        self::assertSame([], self::violations(fn () => MailLinkRules::checkReply($out, $in, false)));

        self::assertSame(
            ['rules.link.source_outgoing', 'rules.link.target_incoming'],
            self::violations(fn () => MailLinkRules::checkReply($in, $out, false))['reference'],
        );
        self::assertContains('rules.link.same_site', self::violations(fn () => MailLinkRules::checkReply($out, self::mail(3, Direction::Incoming, site: 2), false))['reference']);
        self::assertContains('rules.link.target_archived', self::violations(fn () => MailLinkRules::checkReply($out, self::mail(3, Direction::Incoming, status: MailStatus::Archived), false))['reference']);
        self::assertContains('rules.link.duplicate', self::violations(fn () => MailLinkRules::checkReply($out, $in, true))['reference']);
        self::assertContains('rules.link.same_mail', self::violations(fn () => MailLinkRules::checkReply($out, $out, false))['reference']);
    }

    public function testAnnotations(): void
    {
        self::assertSame('Bonjour', Annotation::checkBody("  Bonjour \n"));
        self::assertSame(['rules.annotation.empty'], self::violations(fn () => Annotation::checkBody('   '))['body']);
        self::assertSame(['rules.annotation.too_long'], self::violations(fn () => Annotation::checkBody(str_repeat('a', 5001)))['body']);

        $private = new Annotation(1, 1, 7, 'A', 'x', true, new DateTimeImmutable());
        self::assertTrue($private->isVisibleTo(7));
        self::assertFalse($private->isVisibleTo(8));
        self::assertTrue((new Annotation(1, 1, 7, 'A', 'x', false, new DateTimeImmutable()))->isVisibleTo(8));
    }
}
