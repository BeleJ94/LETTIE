<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function testGetSetHasRemove(): void
    {
        $session = Session::inMemory();
        $session->set('user_id', 5);

        self::assertTrue($session->has('user_id'));
        self::assertSame(5, $session->get('user_id'));
        $session->remove('user_id');
        self::assertFalse($session->has('user_id'));
        self::assertSame('d', $session->get('user_id', 'd'));
    }

    public function testFlashIsAvailableOnNextRequestOnly(): void
    {
        $session = new Session(native: false);
        $session->start();
        $session->flash('notice', 'Saved');
        self::assertNull($session->getFlash('notice'));

        $this->simulateNextRequest($session);
        self::assertSame('Saved', $session->getFlash('notice'));

        $this->simulateNextRequest($session);
        self::assertNull($session->getFlash('notice'));
    }

    public function testCookieIsSecureHttpOnlySameSite(): void
    {
        $params = (new Session(secure: true, path: '/app/', native: false))->cookieParams();
        self::assertTrue($params['secure']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertSame('/app/', $params['path']);
        self::assertSame(0, $params['lifetime']);
        self::assertSame('Strict', (new Session(native: false, sameSite: 'Strict'))->cookieParams()['samesite']);
    }

    public function testRejectsSameSiteNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Session(native: false, sameSite: 'None');
    }

    public function testClear(): void
    {
        $session = Session::inMemory();
        $session->set('a', 1);
        $session->clear();
        self::assertFalse($session->has('a'));
    }

    public function testIdleTimeoutClearsData(): void
    {
        $session = new Session(lifetime: 60, native: false);
        $session->start();
        $session->set('user_id', 1);
        $session->set('_last_activity', time() - 120);

        $this->simulateNextRequest($session);
        self::assertFalse($session->has('user_id'));
    }

    private function simulateNextRequest(Session $session): void
    {
        $started = new \ReflectionProperty(Session::class, 'started');
        $started->setValue($session, false);
        $session->start();
    }
}
