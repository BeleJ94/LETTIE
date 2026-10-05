<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Auth\User;
use App\Domain\Organization\OrganizationRules;
use App\Domain\RuleViolation;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** Sites and departments through the Kernel with MariaDB, and their pure rules. */
final class OrganizationHttpTest extends FunctionalTestCase
{
    private int $siteA;
    private int $siteB;
    private int $departmentA;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = TestDatabase::insertSite('A');
        $this->siteB = TestDatabase::insertSite('B');
        $this->departmentA = TestDatabase::insertDepartment($this->siteA, 'DA');
        $this->admin = $this->user(TestDatabase::insertUser($this->siteA, 'admin@example.org', 'password-123456', 'admin'));
    }

    private function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    public function testRules(): void
    {
        self::assertSame('SIEGE-2', OrganizationRules::normalizeCode('  siege-2 '));
        OrganizationRules::assertValid('RH_1', 'Ressources humaines', false);
        foreach ([['a b', 'Nom', false, 'code'], ['', 'Nom', false, 'code'], ['RH', 'Nom', true, 'code'], ['RH', '  ', false, 'name'], [str_repeat('A', 21), 'Nom', false, 'code']] as [$code, $name, $taken, $field]) {
            try {
                OrganizationRules::assertValid($code, $name, $taken);
                self::fail("'{$code}' / '{$name}' should be refused");
            } catch (RuleViolation $e) {
                self::assertSame([$field], array_keys($e->violations()));
            }
        }
        OrganizationRules::assertCanDeactivate(0);
        $this->expectException(RuleViolation::class);
        OrganizationRules::assertCanDeactivate(2);
    }

    public function testOnlyAdministratorsReachTheScreen(): void
    {
        $secretary = $this->user(TestDatabase::insertUser($this->siteA, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->actingAs($secretary);
        self::assertSame(403, $this->get('/organization')->status());
        self::assertSame(403, $this->post('/departments', ['site_id' => (string) $this->siteA, 'code' => 'X', 'name' => 'X'])->status());

        $this->actingAs($this->admin);
        $page = $this->get('/organization')->body();
        self::assertStringContainsString('Site A', $page);
        self::assertStringContainsString('Dept DA', $page);
        self::assertStringContainsString('data-lt-open-dialog="site-new"', $page);
        self::assertMatchesRegularExpression('#<ui5-side-navigation-item text="Sites et services"[^>]*href="/organization"#', $page);
    }

    public function testCreateRenameAndDeactivateADepartment(): void
    {
        $this->actingAs($this->admin);
        $created = $this->post('/departments', ['site_id' => (string) $this->siteB, 'code' => ' rh ', 'name' => 'Ressources humaines']);
        self::assertSame(302, $created->status(), $created->body());
        $id = (int) $this->value("SELECT id FROM departments WHERE site_id = {$this->siteB} AND code = 'RH'");
        self::assertGreaterThan(0, $id, 'code stored in upper case, on the chosen site');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'department' AND action = 'create' AND entity_id = {$id}"));

        $duplicate = $this->post('/departments', ['site_id' => (string) $this->siteB, 'code' => 'RH', 'name' => 'Autre']);
        self::assertSame(422, $duplicate->status());
        self::assertMatchesRegularExpression('#<ui5-dialog id="department-new"[^>]* open>#', $duplicate->body(), 'the dialog reopens with its error');
        self::assertStringContainsString('déjà utilisé', $duplicate->body());
        self::assertStringContainsString('value="Autre"', $duplicate->body());
        self::assertSame(302, $this->post('/departments', ['site_id' => (string) $this->siteA, 'code' => 'RH', 'name' => 'RH du site A'])->status(), 'the same code is free on another site');

        self::assertSame(302, $this->post("/departments/{$id}", ['code' => 'DRH', 'name' => 'Direction des RH'], 'PUT')->status());
        self::assertSame('Direction des RH', $this->value("SELECT name FROM departments WHERE id = {$id}"));
        self::assertSame(422, $this->post("/departments/{$id}", ['code' => 'bad code', 'name' => 'X'], 'PUT')->status());

        self::assertSame(302, $this->post("/departments/{$id}/toggle", ['active' => '0'])->status());
        self::assertSame(0, (int) $this->value("SELECT is_active FROM departments WHERE id = {$id}"));
        self::assertSame(404, $this->post('/departments/999999/toggle', ['active' => '0'])->status());
    }

    public function testADepartmentOrASiteWithActiveAccountsStaysActive(): void
    {
        $this->pdo->exec("UPDATE users SET department_id = {$this->departmentA} WHERE id = {$this->admin->id}");
        $this->actingAs($this->admin);

        $this->post("/departments/{$this->departmentA}/toggle", ['active' => '0']);
        self::assertSame(1, (int) $this->value("SELECT is_active FROM departments WHERE id = {$this->departmentA}"));
        self::assertStringContainsString('1 compte(s) actif(s)', (string) $this->flash('flash.error'));

        $this->post("/sites/{$this->siteA}/toggle", ['active' => '0']);
        self::assertSame(1, (int) $this->value("SELECT is_active FROM sites WHERE id = {$this->siteA}"), 'own site');
        self::assertStringContainsString('votre propre site', (string) $this->flash('flash.error'));
    }

    public function testCreateRenameAndDeactivateASite(): void
    {
        $this->actingAs($this->admin);
        self::assertSame(302, $this->post('/sites', ['code' => 'annexe', 'name' => 'Annexe nord'])->status());
        $id = (int) $this->value("SELECT id FROM sites WHERE code = 'ANNEXE'");
        self::assertGreaterThan(0, $id);
        self::assertSame(422, $this->post('/sites', ['code' => 'A', 'name' => 'Doublon'])->status(), 'site codes are unique');

        self::assertSame(302, $this->post("/sites/{$id}", ['code' => 'ANNEXE', 'name' => 'Annexe du nord'], 'PUT')->status());
        self::assertSame('Annexe du nord', $this->value("SELECT name FROM sites WHERE id = {$id}"));

        self::assertSame(302, $this->post("/sites/{$id}/toggle", ['active' => '0'])->status());
        self::assertSame(0, (int) $this->value("SELECT is_active FROM sites WHERE id = {$id}"));
        // A deactivated site is no longer offered for a new account.
        self::assertStringNotContainsString('Annexe du nord', $this->get('/users/new')->body());
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'site' AND action = 'update' AND entity_id = {$id}"));
    }
}
