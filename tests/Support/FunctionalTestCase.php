<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Csrf;
use App\Core\Env;
use App\Core\FileStorage;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\User;
use App\Domain\SiteScope;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * HTTP requests through the real Kernel (routes, middleware, controllers,
 * views) on the test database. The session lives in memory and persists
 * across requests of one test, like a browser cookie.
 *
 * actingAs($user) replaces authentication; realAuth() keeps the real
 * AuthService (for login/logout tests).
 */
abstract class FunctionalTestCase extends TestCase
{
    protected PDO $pdo;
    protected Session $session;
    protected ?User $actingAs = null;
    protected string $storageRoot;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->session = Session::inMemory();
        $this->storageRoot = sys_get_temp_dir() . '/lettie_functional_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storageRoot);
        }
    }

    protected function user(int $id): User
    {
        return (new UserRepository($this->pdo, SiteScope::system()))->findById($id) ?? throw new \RuntimeException("No user {$id}");
    }

    protected function actingAs(User $user): static
    {
        $this->actingAs = $user;
        return $this;
    }

    /** A fresh Kernel per request (like PHP-FPM), sharing the session and the database. */
    protected function kernel(): Kernel
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), new Env([
            'APP_TIMEZONE' => 'Europe/Paris',
            'APP_LOCALE' => 'fr',
            'DB_NAME' => 'unused',
            'SESSION_SECURE' => 'false',
        ]));
        $c = $kernel->container();
        $c->instance(PDO::class, $this->pdo);
        // New request, same "cookie": the session data carries over, flash messages age like in production.
        $this->session = Session::inMemory($this->session->all());
        $c->instance(Session::class, $this->session);
        // Tests cannot produce real HTTP uploads: accept files that exist on disk.
        $c->instance(FileStorage::class, new FileStorage($this->storageRoot . '/attachments', uploadsOnly: false));
        if ($this->actingAs !== null) {
            $auth = $this->createStub(AuthService::class);
            $auth->method('user')->willReturn($this->actingAs);
            $c->instance(AuthService::class, $auth);
        }
        return $kernel;
    }

    /** @param array<string, string> $query */
    protected function get(string $path, array $query = [], bool $json = false): Response
    {
        return $this->kernel()->handle(new Request('GET', $path, $query, server: $this->server($json)));
    }

    /** @return array<string, mixed> */
    protected function getJson(string $path, array $query = []): array
    {
        $response = $this->get($path, $query, json: true);
        self::assertSame(200, $response->status(), $response->body());
        return (array) json_decode($response->body(), true);
    }

    /**
     * POST (or PUT/DELETE through _method semantics) with a valid CSRF token.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $files
     */
    protected function post(string $path, array $data = [], string $method = 'POST', array $files = [], bool $json = false): Response
    {
        $kernel = $this->kernel();
        $data[Csrf::FIELD] ??= (new Csrf($this->session))->token();
        return $kernel->handle(new Request($method, $path, post: $data, server: $this->server($json), files: $files));
    }

    /** Flash message written by the last request (shown by the next page). */
    protected function flash(string $key): mixed
    {
        return ($this->session->get('_flash') ?? [])[$key] ?? null;
    }

    /** @return array<string, string> */
    private function server(bool $json): array
    {
        return ['REMOTE_ADDR' => '192.0.2.50', 'HTTP_USER_AGENT' => 'PHPUnit', 'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html'];
    }

    protected static function idFromLocation(Response $response, string $prefix = '/mails/'): int
    {
        return (int) substr((string) $response->header('Location'), strlen($prefix));
    }
}
