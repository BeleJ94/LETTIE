<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function testFactoryIsSharedAndReceivesContainer(): void
    {
        $container = new Container();
        $container->set('answer', static fn (Container $c): \stdClass => (object) ['c' => $c]);

        $first = $container->get('answer');
        self::assertSame($first, $container->get('answer'));
        self::assertSame($container, $first->c);
    }

    public function testInstance(): void
    {
        $container = new Container();
        $container->instance('config', ['a' => 1]);
        self::assertSame(['a' => 1], $container->get('config'));
        self::assertTrue($container->has('config'));
        self::assertFalse($container->has('nothing'));
    }

    public function testAutowiresConstructorDependencies(): void
    {
        $container = new Container();
        $service = $container->get(FixtureService::class);

        self::assertInstanceOf(FixtureService::class, $service);
        self::assertInstanceOf(FixtureDependency::class, $service->dependency);
        self::assertSame(10, $service->limit);
        self::assertSame($service->dependency, $container->get(FixtureDependency::class));
    }

    public function testCannotAutowireScalarWithoutDefault(): void
    {
        $this->expectException(RuntimeException::class);
        (new Container())->get(FixtureScalar::class);
    }

    public function testDetectsCircularDependencies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular');
        (new Container())->get(FixtureCircularA::class);
    }

    public function testUnknownEntryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new Container())->get('unknown.entry');
    }
}

final class FixtureDependency
{
}

final class FixtureService
{
    public function __construct(public readonly FixtureDependency $dependency, public readonly int $limit = 10)
    {
    }
}

final class FixtureScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

final class FixtureCircularA
{
    public function __construct(public readonly FixtureCircularB $b)
    {
    }
}

final class FixtureCircularB
{
    public function __construct(public readonly FixtureCircularA $a)
    {
    }
}
