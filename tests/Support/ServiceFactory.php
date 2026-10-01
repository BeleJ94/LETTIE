<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Clock;
use App\Core\FileStorage;
use App\Core\Transaction;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Audit\Actor;
use App\Domain\Auth\User;
use App\Domain\SiteScope;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AnnotationRepository;
use App\Repositories\AssignmentRepository;
use App\Repositories\DelegationRepository;
use App\Repositories\MailLinkRepository;
use App\Repositories\DeadlineRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\RetentionRepository;
use App\Repositories\ScheduledRunRepository;
use App\Services\DailyTaskService;
use App\Services\DeadlineService;
use App\Services\DelegationService;
use App\Services\NotificationService;
use App\Services\ReminderService;
use App\Services\RetentionService;
use App\Services\WorkflowService;
use App\Repositories\AttachmentRepository;
use App\Repositories\CorrespondentRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MailRepository;
use App\Repositories\MailSequenceRepository;
use App\Repositories\UserRepository;
use App\Services\AttachmentService;
use App\Services\AuditTrail;
use App\Services\CorrespondentService;
use App\Services\MailService;
use PDO;

/** Builds services the way the container does, for a given user scope. */
final class ServiceFactory
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    public function actor(int $userId): Actor
    {
        $user = (new UserRepository($this->pdo, SiteScope::system()))->findById($userId);
        return new Actor($user ?? throw new \RuntimeException("No user {$userId}"), '192.0.2.10', 'PHPUnit');
    }

    public static function scopeOf(User $user): SiteScope
    {
        return SiteScope::forUser($user);
    }

    public function audit(SiteScope $scope): AuditTrail
    {
        return new AuditTrail(new ActivityLogRepository($this->pdo, $scope), $this->clock);
    }

    public function mail(SiteScope $scope): MailService
    {
        return new MailService(
            new MailRepository($this->pdo, $scope),
            new MailSequenceRepository($this->pdo, $scope),
            new CorrespondentRepository($this->pdo, $scope),
            new DepartmentRepository($this->pdo, $scope),
            $this->audit($scope),
            new Transaction($this->pdo),
            $this->clock,
            new ActivityLogRepository($this->pdo, $scope),
        );
    }

    public function workflow(SiteScope $scope): WorkflowService
    {
        return new WorkflowService(
            new MailRepository($this->pdo, $scope),
            new AssignmentRepository($this->pdo, $scope),
            new AnnotationRepository($this->pdo, $scope),
            new DelegationRepository($this->pdo, $scope),
            new MailLinkRepository($this->pdo, $scope),
            new UserRepository($this->pdo, $scope),
            new DepartmentRepository($this->pdo, $scope),
            $this->mail($scope),
            $this->audit($scope),
            new Transaction($this->pdo),
            $this->clock,
            $this->notifications($scope),
        );
    }

    public function notifications(SiteScope $scope): NotificationService
    {
        return new NotificationService(new NotificationRepository($this->pdo, $scope), $this->clock);
    }

    public function dailyTask(FileStorage $storage): DailyTaskService
    {
        $scope = SiteScope::system();
        return new DailyTaskService(
            new ReminderService(
                new DeadlineRepository($this->pdo, $scope),
                new DelegationRepository($this->pdo, $scope),
                new NotificationRepository($this->pdo, $scope),
                $this->clock,
            ),
            $this->retention($scope, $storage),
            new NotificationRepository($this->pdo, $scope),
            new LoginAttemptRepository($this->pdo, $scope),
            new ScheduledRunRepository($this->pdo, $scope),
            $this->clock,
        );
    }

    public function retention(SiteScope $scope, FileStorage $storage): RetentionService
    {
        return new RetentionService(
            new RetentionRepository($this->pdo, $scope),
            new MailRepository($this->pdo, $scope),
            new NotificationRepository($this->pdo, $scope),
            $storage,
            $this->audit($scope),
            new Transaction($this->pdo),
            $this->clock,
        );
    }

    public function deadlines(SiteScope $scope): DeadlineService
    {
        return new DeadlineService(new DeadlineRepository($this->pdo, $scope), $this->workflow($scope), $this->clock);
    }

    public function delegation(SiteScope $scope): DelegationService
    {
        return new DelegationService(
            new DelegationRepository($this->pdo, $scope),
            new UserRepository($this->pdo, $scope),
            $this->audit($scope),
            new Transaction($this->pdo),
            $this->clock,
        );
    }

    public function correspondent(SiteScope $scope): CorrespondentService
    {
        return new CorrespondentService(new CorrespondentRepository($this->pdo, $scope), $this->audit($scope), new Transaction($this->pdo));
    }

    public function attachment(SiteScope $scope, FileStorage $storage, int $maxBytes = AttachmentPolicy::DEFAULT_MAX_BYTES): AttachmentService
    {
        return new AttachmentService(
            new AttachmentRepository($this->pdo, $scope),
            new MailRepository($this->pdo, $scope),
            $storage,
            new AttachmentPolicy($maxBytes),
            $this->audit($scope),
            new Transaction($this->pdo),
            $this->clock,
        );
    }
}
