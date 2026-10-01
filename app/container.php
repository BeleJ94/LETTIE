<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\FileStorage;
use App\Domain\Attachment\AttachmentPolicy;
use App\Core\Session;
use App\Core\Transaction;
use App\Domain\Auth\LoginThrottle;
use App\Domain\SiteScope;
use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\RedirectIfAuthenticated;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/** Application services (the core ones are wired in App\Core\Kernel). */
return static function (Container $container): void {
    $container->instance('middleware.aliases', [
        'auth' => Authenticate::class,
        'guest' => RedirectIfAuthenticated::class,
        'can' => Authorize::class,
    ]);

    // Authentication runs before any user is known: system scope, stated explicitly.
    $container->set(AuthService::class, static fn (Container $c): AuthService => new AuthService(
        new UserRepository($c->get(PDO::class), SiteScope::system()),
        new LoginAttemptRepository($c->get(PDO::class), SiteScope::system()),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Transaction::class),
        $c->get(Clock::class),
        new LoginThrottle(),
    ));

    // Private files, outside public/.
    $container->set(FileStorage::class, static function (Container $c): FileStorage {
        $env = $c->get(Env::class);
        $root = $env->get('STORAGE_PATH') ?: dirname(__DIR__) . '/storage';
        return new FileStorage(rtrim($root, '/\\') . '/attachments');
    });
    $container->set(AttachmentPolicy::class, static fn (Container $c): AttachmentPolicy => new AttachmentPolicy(
        $c->get(Env::class)->int('ATTACHMENT_MAX_BYTES', AttachmentPolicy::DEFAULT_MAX_BYTES),
    ));

    // Scope injected into every autowired repository.
    $container->set(SiteScope::class, static function (Container $c): SiteScope {
        $user = $c->get(AuthService::class)->user();
        return $user !== null ? SiteScope::forUser($user) : SiteScope::none();
    });
};
