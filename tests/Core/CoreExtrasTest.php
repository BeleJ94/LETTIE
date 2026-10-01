<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Transaction;
use App\Core\Url;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreExtrasTest extends TestCase
{
    private static function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE t (v TEXT)');
        return $pdo;
    }

    public function testTransactionCommitsAndReturnsTheResult(): void
    {
        $pdo = self::sqlite();
        $result = (new Transaction($pdo))->run(static function () use ($pdo): string {
            $pdo->exec("INSERT INTO t VALUES ('a')");
            return 'done';
        });
        self::assertSame('done', $result);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        $pdo = self::sqlite();
        try {
            (new Transaction($pdo))->run(static function () use ($pdo): void {
                $pdo->exec("INSERT INTO t VALUES ('a')");
                throw new RuntimeException('boom');
            });
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testNestedTransactionsJoinTheOuterOne(): void
    {
        $pdo = self::sqlite();
        $tx = new Transaction($pdo);
        try {
            $tx->run(static function () use ($tx, $pdo): void {
                $tx->run(static fn () => $pdo->exec("INSERT INTO t VALUES ('inner')"));
                throw new RuntimeException('outer fails');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(), 'inner work is rolled back with the outer transaction');
    }

    public function testUrl(): void
    {
        $router = new Router();
        $router->get('/mails/{id:\d+}', ['C', 'show'], 'mails.show');
        $url = new Url('/Lettie/', $router);
        self::assertSame('/Lettie/assets/css/app.css', $url->to('assets/css/app.css'));
        self::assertSame('/Lettie/mails/7', $url->route('mails.show', ['id' => 7]));
        self::assertSame('/x', (new Url('', $router))->to('/x'));
    }

    public function testHttpExceptionFactories(): void
    {
        self::assertSame(401, HttpException::unauthorized()->status());
        self::assertSame(403, HttpException::forbidden()->status());
        self::assertSame(404, HttpException::notFound()->status());
        self::assertSame(419, HttpException::csrfMismatch()->status());
        $e = HttpException::methodNotAllowed(['GET', 'PUT']);
        self::assertSame([405, ['Allow' => 'GET, PUT']], [$e->status(), $e->headers()]);
    }

    public function testRequestAccessors(): void
    {
        $r = (new Request('POST', '/x', ['a' => '1'], ['b' => '2'], ['SERVER_PORT' => 443], ['c' => 'cookie']))->withRouteParams(['id' => '5']);
        self::assertSame(['a' => '1', 'b' => '2'], $r->all());
        self::assertSame('cookie', $r->cookie('c'));
        self::assertNull($r->cookie('missing'));
        self::assertSame(443, $r->server('SERVER_PORT'));
        self::assertTrue($r->isSecure());
        self::assertSame(['id' => '5'], $r->params());
        self::assertNull($r->file('none'));
    }

    public function testRouterAddPatchAndListing(): void
    {
        $router = new Router();
        $router->patch('/a', ['C', 'a'], 'a.patch');
        $router->add('options', '/b', ['C', 'b']);
        self::assertSame(['C', 'a'], $router->match('PATCH', '/a')['handler']);
        self::assertSame(['PATCH', 'OPTIONS'], array_column($router->routes(), 'method'));
        self::assertSame(['a.patch', null], array_column($router->routes(), 'name'));
    }

    public function testInMemorySessionCarriesDataAndAgesFlashBetweenRequests(): void
    {
        $first = Session::inMemory();
        $first->set('user', 5);
        $first->flash('notice', 'Saved');

        $second = Session::inMemory($first->all());
        self::assertSame(5, $second->get('user'));
        self::assertSame('Saved', $second->getFlash('notice'));

        $third = Session::inMemory($second->all());
        self::assertNull($third->getFlash('notice'), 'flash lasts one request');
        $third->destroy();
        self::assertFalse($third->has('user'));
        $third->regenerate(); // no-op without a native session
        $this->addToAssertionCount(1);
    }

    public function testResponseSendWritesFileBody(): void
    {
        $this->expectOutputString((string) file_get_contents(__FILE__));
        Response::file(__FILE__, 'text/plain', 'x.txt')->send();
    }
}
