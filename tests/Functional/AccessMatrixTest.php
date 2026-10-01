<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Env;
use App\Core\Kernel;
use App\Core\Router;
use App\Domain\Auth\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/**
 * Who can open which page: the expected HTTP status of every GET route for
 * each of the five roles. This table IS the access specification: a change of
 * permissions must be reflected here on purpose.
 */
final class AccessMatrixTest extends FunctionalTestCase
{
    /** Column order of the matrix below. */
    private const ROLES = ['admin', 'secretariat', 'head_of_department', 'agent', 'management'];

    /**
     * GET routes => expected status per role (admin, secretariat, head, agent, management).
     * {mail} / {correspondent} are replaced by records of the user's site.
     */
    private const MATRIX = [
        '/' => [200, 200, 200, 200, 200],
        '/mails' => [200, 200, 200, 200, 200],
        '/mails/data' => [200, 200, 200, 200, 200],
        '/mails/export' => [200, 200, 200, 200, 200],
        '/mails/new' => [200, 200, 403, 403, 403],
        '/mails/{mail}' => [200, 200, 200, 200, 200],
        '/mails/{mail}/edit' => [200, 200, 200, 200, 403],
        '/mails/{mail}/slip' => [200, 200, 200, 200, 200],
        '/attachments/{attachment}' => [200, 200, 200, 200, 200],
        '/register' => [200, 200, 200, 200, 200],
        '/register/data' => [200, 200, 200, 200, 200],
        '/statistics' => [200, 403, 200, 403, 200],
        '/statistics/data' => [200, 403, 200, 403, 200],
        '/delegations' => [200, 200, 200, 200, 200],
        '/notifications' => [200, 200, 200, 200, 200],
        '/notifications/count' => [200, 200, 200, 200, 200],
        '/retention-rules' => [200, 403, 403, 403, 403],
        '/correspondents' => [200, 200, 403, 403, 403],
        '/correspondents/data' => [200, 200, 403, 403, 403],
        '/correspondents/new' => [200, 200, 403, 403, 403],
        '/correspondents/{correspondent}/edit' => [200, 200, 403, 403, 403],
        '/correspondents/search' => [200, 200, 200, 200, 200],
        // Signed-in users are sent away from the login page.
        '/login' => [302, 302, 302, 302, 302],
    ];

    /** Query strings required by some JSON endpoints. */
    private const QUERY = [
        '/register/data' => ['direction' => 'incoming', 'from' => '2026-01-01', 'to' => '2026-12-31'],
        '/correspondents/search' => ['q' => 'Mai'],
    ];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        foreach (self::ROLES as $role) {
            $this->ids[$role] = TestDatabase::insertUser($site, "{$role}@example.org", 'password-123456', $role);
        }
        $this->ids['correspondent'] = $correspondent;

        $this->actingAs($this->user($this->ids['secretariat']));
        $created = $this->post('/mails', [
            'direction' => 'incoming', 'subject' => 'Objet', 'correspondent_id' => (string) $correspondent,
            // Yesterday: "today 08:00" would be in the future when the suite runs before 8 a.m.
            'received_at' => date('Y-m-d', strtotime('-1 day')) . 'T08:00', 'channel' => 'postal', 'priority' => 'normal', 'confidentiality' => 'internal',
        ]);
        self::assertSame(302, $created->status(), $created->body());
        $this->ids['mail'] = self::idFromLocation($created);

        $pdf = (string) tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $this->post("/mails/{$this->ids['mail']}/attachments", [], files: ['file' => ['name' => 'a.pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK, 'size' => 15]]);
        $this->ids['attachment'] = (int) $this->pdo->query('SELECT id FROM attachments')->fetchColumn();
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function matrix(): iterable
    {
        foreach (self::MATRIX as $route => $statuses) {
            foreach (self::ROLES as $i => $role) {
                yield "{$role} GET {$route}" => [$role, $route, $statuses[$i]];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testAccess(string $role, string $route, int $expected): void
    {
        $this->actingAs($this->user($this->ids[$role]));
        $path = preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => (string) $this->ids[$m[1]], $route);
        $json = str_ends_with($route, '/data') || str_ends_with($route, '/count') || str_ends_with($route, '/export') || str_ends_with($route, '/search');

        $response = $this->get($path, self::QUERY[$route] ?? [], $json);

        self::assertSame($expected, $response->status(), "{$role} GET {$path}: " . mb_substr(strip_tags($response->body()), 0, 300));
    }

    /** The matrix lists every GET route: a new route cannot ship without an access decision. */
    public function testMatrixCoversEveryGetRoute(): void
    {
        $router = Kernel::boot(dirname(__DIR__, 2), new Env(['DB_NAME' => 'unused']))->container()->get(Router::class);
        $declared = [];
        foreach ($router->routes() as $route) {
            if ($route['method'] === 'GET') {
                $declared[] = preg_replace(['/\{id:[^}]+\}(?=\/(edit|slip)|$)/', '/\{[^}]+\}/'], ['{X}', '{X}'], $route['pattern']);
            }
        }
        $covered = array_map(static fn (string $r): string => preg_replace('/\{\w+\}/', '{X}', $r), array_keys(self::MATRIX));
        self::assertSame([], array_values(array_diff($declared, $covered)), 'GET routes missing from the access matrix');
    }

    /** Roles in the matrix are exactly the roles of the application. */
    public function testMatrixRolesMatchDomain(): void
    {
        self::assertSame(array_column(Role::cases(), 'value'), self::ROLES);
    }
}
