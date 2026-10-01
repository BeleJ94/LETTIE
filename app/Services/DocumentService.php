<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\TableRequest;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailFilter;
use App\Domain\NotFoundException;
use App\Domain\Stats\StatsCalculator;
use App\Repositories\AttachmentRepository;
use App\Repositories\CorrespondentRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MailRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;

/**
 * Data for documents that leave the application: list exports, the mail
 * register, the registration slip. The subject of secret mail is masked.
 */
final class DocumentService
{
    public const EXPORT_LIMIT = 5000;
    public const REGISTER_LIMIT = 10000;

    public function __construct(
        private readonly MailRepository $mails,
        private readonly CorrespondentRepository $correspondents,
        private readonly DepartmentRepository $departments,
        private readonly SiteRepository $sites,
        private readonly UserRepository $users,
        private readonly AttachmentRepository $attachments,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{count: int, truncated: bool, limit: int}} */
    public function listExport(MailFilter $filter, TableRequest $table): array
    {
        return self::envelope($this->mails->exportRows($filter, $table, self::EXPORT_LIMIT + 1), self::EXPORT_LIMIT);
    }

    /**
     * @param string $from local date (inclusive)
     * @param string $to local date (inclusive)
     * @return array{data: list<array<string, mixed>>, meta: array{count: int, truncated: bool, limit: int}}
     * @throws \App\Domain\RuleViolation
     */
    public function register(Direction $direction, string $from, string $to): array
    {
        StatsCalculator::checkRange($from, $to);
        [$fromUtc, $toUtc] = StatsService::bounds($from, $to, date_default_timezone_get());
        return self::envelope($this->mails->register($direction, $fromUtc, $toUtc, self::REGISTER_LIMIT + 1), self::REGISTER_LIMIT);
    }

    /**
     * Everything the registration slip prints.
     *
     * @return array{mail: Mail, correspondent: ?\App\Domain\Correspondent\Correspondent, department: ?string, site: ?string,
     *               registeredBy: ?string, attachments: int, subject: ?string}
     */
    public function slip(int $mailId): array
    {
        $mail = $this->mails->findById($mailId) ?? throw NotFoundException::of('Mail', $mailId);
        return [
            'mail' => $mail,
            'correspondent' => $this->correspondents->findById($mail->correspondentId),
            'department' => $mail->departmentId !== null ? $this->departments->findName($mail->departmentId) : null,
            'site' => $this->sites->findName($mail->siteId),
            'registeredBy' => $this->users->findById($mail->createdBy)?->fullName(),
            'attachments' => count(array_filter($this->attachments->listForMail($mail->id), static fn ($a): bool => !$a->isPurged())),
            'subject' => $mail->confidentiality->masksSubjectInDocuments() ? null : $mail->subject,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows fetched with limit + 1 to detect truncation
     * @return array{data: list<array<string, mixed>>, meta: array{count: int, truncated: bool, limit: int}}
     */
    private static function envelope(array $rows, int $limit): array
    {
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $masked = Confidentiality::tryFrom((string) ($row['confidentiality'] ?? ''))?->masksSubjectInDocuments() ?? false;
            if ($masked) {
                $row['subject'] = null;
            }
            $row['masked'] = $masked;
        }
        unset($row);
        return ['data' => $rows, 'meta' => ['count' => count($rows), 'truncated' => $truncated, 'limit' => $limit]];
    }
}
