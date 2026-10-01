<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Transaction;
use App\Domain\Auth\Role;
use App\Domain\OutOfScopeException;
use App\Domain\SiteScope;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Services\UserService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class SiteScopeRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $siteA;
    private int $siteB;
    private int $userB;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->siteA = TestDatabase::insertSite('A');
        $this->siteB = TestDatabase::insertSite('B');
        TestDatabase::insertUser($this->siteA, 'a@example.org', 'password-a-123');
        $this->userB = TestDatabase::insertUser($this->siteB, 'b@example.org', 'password-b-123');
    }

    public function testReadsAreFilteredBySite(): void
    {
        $repoA = new UserRepository($this->pdo, SiteScope::sites($this->siteA));

        self::assertNotNull($repoA->findByEmail('a@example.org'));
        self::assertNull($repoA->findByEmail('b@example.org'));
        self::assertNull($repoA->findById($this->userB));

        $both = $repoA->withScope(SiteScope::sites($this->siteA, $this->siteB));
        self::assertNotNull($both->findById($this->userB));
        self::assertNull($repoA->findById($this->userB), 'withScope() must not change the original');
    }

    public function testEmptyScopeSeesNothingAndSystemSeesAll(): void
    {
        self::assertNull((new UserRepository($this->pdo, SiteScope::none()))->findByEmail('a@example.org'));
        self::assertNotNull((new UserRepository($this->pdo, SiteScope::system()))->findByEmail('b@example.org'));
    }

    public function testUpdatesOutsideScopeAffectNothing(): void
    {
        $repoA = new UserRepository($this->pdo, SiteScope::sites($this->siteA));
        self::assertFalse($repoA->updatePasswordHash($this->userB, 'hacked'));
        self::assertNotSame('hacked', $this->pdo->query("SELECT password_hash FROM users WHERE id = {$this->userB}")->fetchColumn());
    }

    public function testCreateOutsideScopeThrows(): void
    {
        $this->expectException(OutOfScopeException::class);
        (new UserRepository($this->pdo, SiteScope::sites($this->siteA)))
            ->create($this->siteB, null, Role::Agent, 'x@example.org', 'hash', 'X', 'Y');
    }

    public function testSiteCreationNeedsUnrestrictedScope(): void
    {
        $this->expectException(OutOfScopeException::class);
        (new SiteRepository($this->pdo, SiteScope::sites($this->siteA)))->create('C', 'Site C');
    }

    public function testUserServiceCreatesSiteAndUser(): void
    {
        $scope = SiteScope::system();
        $service = new UserService(
            new UserRepository($this->pdo, $scope),
            new SiteRepository($this->pdo, $scope),
            new Transaction($this->pdo),
        );
        $id = $service->create('NEW', 'New site', Role::Admin, 'Admin@Example.org', 'long enough password', 'Ada', 'Admin');

        $user = (new UserRepository($this->pdo, $scope))->findById($id);
        self::assertSame('admin@example.org', $user?->email);
        self::assertSame(Role::Admin, $user?->role);
        self::assertTrue(password_verify('long enough password', (string) $user?->passwordHash));

        $this->expectException(DomainException::class);
        $service->create('NEW', null, Role::Agent, 'admin@example.org', 'long enough password', 'Dup', 'Dup');
    }

    public function testUserServiceRollsBackOnFailure(): void
    {
        $scope = SiteScope::system();
        $service = new UserService(new UserRepository($this->pdo, $scope), new SiteRepository($this->pdo, $scope), new Transaction($this->pdo));
        try {
            $service->create('ROLLBACK', 'Site', Role::Agent, 'a@example.org', 'long enough password', 'A', 'A');
            self::fail('Duplicate email should fail');
        } catch (DomainException) {
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sites WHERE code = 'ROLLBACK'")->fetchColumn());
    }
}
