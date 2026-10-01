<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testHtml(): void
    {
        $response = Response::html('<p>x</p>', 201);
        self::assertSame(201, $response->status());
        self::assertSame('<p>x</p>', $response->body());
        self::assertSame('text/html; charset=UTF-8', $response->header('content-type'));
    }

    public function testJsonKeepsUnicode(): void
    {
        $response = Response::json(['name' => 'Élodie', 'path' => 'a/b']);
        self::assertSame('{"name":"Élodie","path":"a/b"}', $response->body());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
    }

    public function testRedirect(): void
    {
        $response = Response::redirect('/login');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testWithersAreImmutable(): void
    {
        $response = new Response('x');
        $changed = $response->withHeader('X-Test', '1')->withStatus(404);

        self::assertNull($response->header('X-Test'));
        self::assertSame(200, $response->status());
        self::assertSame('1', $changed->header('X-Test'));
        self::assertSame(404, $changed->status());
    }

    public function testCookiesAreSecureHttpOnlySameSiteByDefault(): void
    {
        $cookie = (new Response())->withCookie('pref', 'x')->cookies()['pref'];
        self::assertSame('x', $cookie['value']);
        self::assertTrue($cookie['options']['secure']);
        self::assertTrue($cookie['options']['httponly']);
        self::assertSame('Lax', $cookie['options']['samesite']);
    }

    public function testCookieOptionsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->withCookie('x', 'y', ['samesite' => 'None', 'secure' => false]);
    }

    public function testWithoutCookieExpires(): void
    {
        $cookie = (new Response())->withoutCookie('pref')->cookies()['pref'];
        self::assertLessThan(time(), $cookie['options']['expires']);
    }

    public function testSendOutputsBody(): void
    {
        $this->expectOutputString('hello');
        (new Response('hello'))->send();
    }
}
