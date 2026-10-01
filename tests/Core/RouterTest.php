<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', static fn (Request $r): Response => new Response('home'), 'home');
        $this->router->get('/mails/{id:\d+}', ['MailController', 'show'], 'mails.show');
        $this->router->put('/mails/{id:\d+}', ['MailController', 'update']);
        $this->router->get('/mails/{id:\d+}/files/{name}', ['FileController', 'show'], 'files.show');
    }

    public function testMatchesStaticRoute(): void
    {
        $match = $this->router->match('GET', '/');
        self::assertSame([], $match['params']);
        self::assertInstanceOf(\Closure::class, $match['handler']);
    }

    public function testExtractsParameters(): void
    {
        $match = $this->router->match('GET', '/mails/42/files/scan.pdf');
        self::assertSame(['FileController', 'show'], $match['handler']);
        self::assertSame(['id' => '42', 'name' => 'scan.pdf'], $match['params']);
    }

    public function testSelectsByMethod(): void
    {
        self::assertSame(['MailController', 'update'], $this->router->match('PUT', '/mails/1')['handler']);
    }

    public function testHeadFallsBackToGet(): void
    {
        self::assertSame(['MailController', 'show'], $this->router->match('HEAD', '/mails/1')['handler']);
    }

    public function testConstraintMismatchIsNotFound(): void
    {
        try {
            $this->router->match('GET', '/mails/abc');
            self::fail('Expected 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        try {
            $this->router->match('DELETE', '/mails/1');
            self::fail('Expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame('GET, PUT', $e->headers()['Allow']);
        }
    }

    public function testGeneratesPathFromName(): void
    {
        self::assertSame('/mails/7/files/a%20b.pdf', $this->router->path('files.show', ['id' => 7, 'name' => 'a b.pdf']));
        self::assertSame('/', $this->router->path('home'));
    }

    public function testGroupsStackMiddleware(): void
    {
        $router = new Router();
        $router->group(['auth'], static function (Router $r): void {
            $r->get('/a', ['C', 'a'], null, ['can:mail.view']);
            $r->group(['can:reports.view'], static fn (Router $r) => $r->get('/b', ['C', 'b']));
        });
        $router->get('/c', ['C', 'c']);

        self::assertSame(['auth', 'can:mail.view'], $router->match('GET', '/a')['middleware']);
        self::assertSame(['auth', 'can:reports.view'], $router->match('GET', '/b')['middleware']);
        self::assertSame([], $router->match('GET', '/c')['middleware']);
    }

    public function testDuplicateRouteNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->get('/other', ['X', 'y'], 'home');
    }

    public function testPathGenerationErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->path('mails.show');
    }
}
