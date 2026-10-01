<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function paths(): iterable
    {
        yield 'root' => ['/', '', '/'];
        yield 'query stripped' => ['/mails?page=2', '', '/mails'];
        yield 'trailing slash' => ['/mails/', '', '/mails'];
        yield 'base path' => ['/Lettie/public/mails/3', '/Lettie/public', '/mails/3'];
        yield 'base path root' => ['/Lettie/public/', '/Lettie/public/', '/'];
        yield 'index.php' => ['/Lettie/public/index.php', '/Lettie/public', '/'];
        yield 'similar prefix kept' => ['/Lettie2/x', '/Lettie', '/Lettie2/x'];
        yield 'decoded' => ['/search/caf%C3%A9', '', '/search/café'];
    }

    #[DataProvider('paths')]
    public function testNormalizePath(string $uri, string $base, string $expected): void
    {
        self::assertSame($expected, Request::normalizePath($uri, $base));
    }

    public function testInputAccessors(): void
    {
        $request = new Request('POST', '/mails', ['page' => '2', 'q' => 'x'], ['q' => 'body', 'subject' => 'Hi']);

        self::assertSame('body', $request->input('q'));
        self::assertSame('2', $request->input('page'));
        self::assertSame('d', $request->input('none', 'd'));
        self::assertSame('2', $request->query('page'));
        self::assertSame('Hi', $request->post('subject'));
        self::assertSame(['q' => 'body', 'subject' => 'Hi'], $request->only(['q', 'subject']));
        self::assertFalse($request->isSafe());
        self::assertTrue($request->isMethod('post'));
    }

    public function testHeadersAndFlags(): void
    {
        $request = new Request('GET', '/', server: [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_X_CSRF_TOKEN' => 'abc',
            'CONTENT_TYPE' => 'application/json',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('abc', $request->header('X-CSRF-Token'));
        self::assertSame('application/json', $request->header('content-type'));
        self::assertTrue($request->isAjax());
        self::assertTrue($request->wantsJson());
        self::assertTrue($request->isSecure());
        self::assertTrue($request->isSafe());
        self::assertSame('10.0.0.1', $request->ip());
    }

    public function testRouteParamsAreImmutable(): void
    {
        $request = new Request('GET', '/mails/5');
        $withParams = $request->withRouteParams(['id' => '5']);

        self::assertNull($request->param('id'));
        self::assertSame('5', $withParams->param('id'));
    }

    public function testFromGlobalsAppliesMethodOverride(): void
    {
        $backup = [$_SERVER, $_POST, $_GET];
        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/app/mails/1?x=1'];
            $_POST = ['_method' => 'delete'];
            $_GET = ['x' => '1'];

            $request = Request::fromGlobals('/app');
            self::assertSame('DELETE', $request->method());
            self::assertSame('/mails/1', $request->path());
            self::assertSame('1', $request->query('x'));
        } finally {
            [$_SERVER, $_POST, $_GET] = $backup;
        }
    }
}
