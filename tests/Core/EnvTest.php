<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    public function testParsesValuesCommentsAndQuotes(): void
    {
        $env = Env::parse(<<<'ENV'
            # comment
            LETTIE_T_HOST=127.0.0.1
            LETTIE_T_EMPTY=
            LETTIE_T_DQ="hello world"
            LETTIE_T_SQ='a # not a comment'
            LETTIE_T_INLINE=value # trailing comment
            export LETTIE_T_EXPORTED=yes
            ENV);

        self::assertSame('127.0.0.1', $env->get('LETTIE_T_HOST'));
        self::assertSame('', $env->get('LETTIE_T_EMPTY'));
        self::assertSame('hello world', $env->get('LETTIE_T_DQ'));
        self::assertSame('a # not a comment', $env->get('LETTIE_T_SQ'));
        self::assertSame('value', $env->get('LETTIE_T_INLINE'));
        self::assertSame('yes', $env->get('LETTIE_T_EXPORTED'));
        self::assertSame('fallback', $env->get('LETTIE_T_MISSING', 'fallback'));
    }

    public function testTypedAccessors(): void
    {
        $env = new Env(['LETTIE_T_ON' => 'true', 'LETTIE_T_OFF' => 'false', 'LETTIE_T_PORT' => '3307', 'LETTIE_T_BAD' => 'x']);

        self::assertTrue($env->bool('LETTIE_T_ON'));
        self::assertFalse($env->bool('LETTIE_T_OFF', true));
        self::assertTrue($env->bool('LETTIE_T_MISSING', true));
        self::assertSame(3307, $env->int('LETTIE_T_PORT'));
        self::assertSame(5, $env->int('LETTIE_T_BAD', 5));
    }

    public function testRealEnvironmentOverridesFile(): void
    {
        putenv('LETTIE_T_OVERRIDE=real');
        try {
            self::assertSame('real', (new Env(['LETTIE_T_OVERRIDE' => 'file']))->get('LETTIE_T_OVERRIDE'));
        } finally {
            putenv('LETTIE_T_OVERRIDE');
        }
    }

    public function testRequireThrowsOnMissingKey(): void
    {
        $this->expectException(RuntimeException::class);
        (new Env())->require('LETTIE_T_MISSING');
    }

    public function testInvalidLineThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Env::parse("not a valid line");
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Env::load(__DIR__ . '/does-not-exist.env');
    }
}
