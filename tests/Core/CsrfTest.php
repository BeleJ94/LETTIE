<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->csrf = new Csrf(Session::inMemory());
    }

    public function testTokenIsStableAndRandom(): void
    {
        $token = $this->csrf->token();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $this->csrf->token());
        self::assertNotSame($token, $this->csrf->rotate());
    }

    public function testIsValid(): void
    {
        $token = $this->csrf->token();
        self::assertTrue($this->csrf->isValid($token));
        self::assertFalse($this->csrf->isValid('wrong'));
        self::assertFalse($this->csrf->isValid(null));
        self::assertFalse((new Csrf(Session::inMemory()))->isValid(''));
    }

    public function testSafeRequestsAreNotChecked(): void
    {
        $this->csrf->verify(new Request('GET', '/'));
        $this->addToAssertionCount(1);
    }

    public function testAcceptsFormFieldAndHeader(): void
    {
        $token = $this->csrf->token();
        $this->csrf->verify(new Request('POST', '/', post: [Csrf::FIELD => $token]));
        $this->csrf->verify(new Request('DELETE', '/', server: ['HTTP_X_CSRF_TOKEN' => $token]));
        $this->addToAssertionCount(2);
    }

    public function testRejectsMissingToken(): void
    {
        $this->csrf->token();
        try {
            $this->csrf->verify(new Request('POST', '/'));
            self::fail('Expected 419');
        } catch (HttpException $e) {
            self::assertSame(419, $e->status());
        }
    }

    public function testFieldIsEscapedHiddenInput(): void
    {
        $field = $this->csrf->field();
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="_csrf"', $field);
        self::assertStringContainsString($this->csrf->token(), $field);
    }
}
