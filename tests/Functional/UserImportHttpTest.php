<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Response;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Auth\UserImport;
use App\Domain\RuleViolation;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** CSV import of user accounts and the roles matrix: pure parser, then the screens through the Kernel. */
final class UserImportHttpTest extends FunctionalTestCase
{
    private int $siteA;
    private User $admin;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = TestDatabase::insertSite('A');
        TestDatabase::insertSite('B');
        TestDatabase::insertDepartment($this->siteA, 'RH');
        $this->admin = $this->user(TestDatabase::insertUser($this->siteA, 'admin@example.org', 'password-123456', 'admin'));
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
        parent::tearDown();
    }

    private function upload(string $csv): Response
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);
        $this->files[] = $path;
        return $this->post('/users/import', [], files: ['file' => ['name' => 'comptes.csv', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]]);
    }

    public function testParserReadsHeadersSeparatorsAndBom(): void
    {
        $rows = UserImport::parse("\xEF\xBB\xBFPrénom;Nom;E-mail;Rôle;Site;Service\r\n Jeanne ;Durand;Jeanne@Example.org;Agent;a;rh\r\n\r\nPaul;\"Le Goff; fils\";paul@example.org;agent;;\r\n");
        self::assertCount(2, $rows, 'empty lines are skipped');
        self::assertSame(['line' => 2, 'first_name' => 'Jeanne', 'last_name' => 'Durand', 'email' => 'jeanne@example.org', 'role' => 'Agent', 'site' => 'A', 'department' => 'RH'], $rows[0]);
        self::assertSame(4, $rows[1]['line'], 'line numbers are those of the file');
        self::assertSame('Le Goff; fils', $rows[1]['last_name'], 'quoted cells keep their separator');

        $english = UserImport::parse("email,role,last_name,first_name\nana@example.org,agent,Martin,Ana\n");
        self::assertSame(['Ana', 'Martin', ''], [$english[0]['first_name'], $english[0]['last_name'], $english[0]['site']], 'comma separator, any column order, optional columns');

        self::assertSame(Role::HeadOfDepartment, UserImport::role(' HEAD_OF_DEPARTMENT '));
        self::assertSame(Role::HeadOfDepartment, UserImport::role('chef de service', ['Chef de service' => 'head_of_department']));
        self::assertNull(UserImport::role('directeur'));
    }

    public function testParserRefusesAFileItCannotUse(): void
    {
        $cases = [
            'no header' => "Jeanne;Durand;jeanne@example.org;agent\n",
            'no account' => "prenom;nom;email;role\n\n",
            'not UTF-8' => "prenom;nom;email;role\nJos\xE9;Durand;jose@example.org;agent\n",
            'too many lines' => "prenom;nom;email;role\n" . str_repeat("A;B;c@example.org;agent\n", UserImport::MAX_ROWS + 1),
        ];
        foreach ($cases as $name => $csv) {
            try {
                UserImport::parse($csv);
                self::fail("{$name}: should be refused");
            } catch (RuleViolation $e) {
                self::assertSame(['file'], array_keys($e->violations()), $name);
            }
        }
    }

    public function testCheckThenCreateTheValidLines(): void
    {
        self::assertStringContainsString('<ui5-file-uploader id="file" name="file"', $this->get('/users/import')->body());
        self::assertSame(422, $this->post('/users/import')->status(), 'no file');
        self::assertSame(422, $this->upload("n'importe quoi\n")->status());

        $checked = $this->upload(implode("\n", [
            'prenom;nom;email;role;site;service',
            'Jeanne;Durand;jeanne@example.org;agent;A;RH',
            'Paul;Petit;paul@example.org;Chef de service;;',
            'Sans;Role;sans@example.org;directeur;A;',
            'Double;Email;jeanne@example.org;agent;A;',
            'Deja;Pris;admin@example.org;agent;A;',
            'Autre;Site;autre@example.org;agent;ZZ;',
            'Mauvais;Service;service@example.org;agent;B;RH',
            ';Vide;pasdemail;agent;A;',
        ]));
        self::assertSame(200, $checked->status());
        $page = $checked->body();
        self::assertStringContainsString('2 ligne(s) valide(s) sur 8', $page);
        self::assertStringContainsString('Rôle inconnu', $page);
        self::assertStringContainsString('déjà présente à la ligne 2', $page);
        self::assertStringContainsString('déjà utilisée par un autre compte', $page);
        self::assertStringContainsString('Site inconnu ou inactif : ZZ', $page);
        self::assertStringContainsString('Service inconnu ou inactif dans ce site : RH', $page);
        self::assertStringContainsString('Adresse e-mail invalide', $page);
        self::assertStringContainsString('Créer 2 compte(s)', $page);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'the check writes nothing');

        $created = $this->post('/users/import/confirm');
        self::assertSame(200, $created->status());
        $rows = $this->pdo->query("SELECT u.email, u.must_change_password, u.site_id, d.code AS department, r.code AS role, u.password_hash
                                   FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id
                                   WHERE u.email <> 'admin@example.org' ORDER BY u.email")->fetchAll();
        self::assertSame(['jeanne@example.org', 'paul@example.org'], array_column($rows, 'email'));
        self::assertSame(['agent', 'head_of_department'], array_column($rows, 'role'), 'the role may be given by its label');
        self::assertSame(['RH', null], array_column($rows, 'department'));
        self::assertSame([$this->siteA, $this->siteA], array_map('intval', array_column($rows, 'site_id')), 'an empty site is the administrator\'s site');
        self::assertSame(['1', '1'], array_map('strval', array_column($rows, 'must_change_password')));

        // Each temporary password is shown once, and it is the one stored.
        preg_match_all('#<td data-label="Mot de passe provisoire">([a-z0-9-]{19})</td>#', $created->body(), $m);
        self::assertCount(2, $m[1]);
        self::assertNotSame($m[1][0], $m[1][1], 'one password per account');
        self::assertTrue(password_verify($m[1][0], $rows[0]['password_hash']));
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'user' AND action = 'create'")->fetchColumn());

        self::assertSame('/users/import', $this->post('/users/import/confirm')->header('Location'), 'a confirmation cannot be replayed');
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testRolesMatrixFollowsTheCode(): void
    {
        $page = $this->get('/roles')->body();
        self::assertStringContainsString('Rôles et droits', $page);
        foreach (\App\Domain\Auth\Permission::cases() as $permission) {
            self::assertStringContainsString('data-permission="' . $permission->value . '"', $page);
            self::assertStringNotContainsString('permissions.' . $permission->value, $page, 'every permission has a label');
        }
        // Agent row cells: mail.assign is not granted, mail.view is.
        self::assertMatchesRegularExpression('#data-permission="mail\.view".*?data-role="agent">\s*<ui5-tag design="Positive"#s', $page);
        self::assertMatchesRegularExpression('#data-permission="mail\.assign".*?data-role="agent">\s*<ui5-text>—#s', $page);
        self::assertStringContainsString('data-lt-href="/roles"', $this->get('/users')->body());
    }
}
