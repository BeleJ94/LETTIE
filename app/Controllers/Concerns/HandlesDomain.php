<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Translator;
use App\Core\ValidationException;
use App\Domain\AccessDeniedException;
use App\Domain\Audit\Actor;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @property-read AuthService $auth
 * @property-read Translator $translator
 */
trait HandlesDomain
{
    private function actor(Request $request): Actor
    {
        $user = $this->auth->user() ?? throw HttpException::unauthorized();
        return new Actor($user, $request->ip(), $request->header('User-Agent'));
    }

    private function routeId(Request $request): int
    {
        return (int) $request->param('id');
    }

    /**
     * Runs $work, mapping "not found / out of scope" to HTTP 404 and
     * "visible but not allowed" to HTTP 403.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function orNotFound(Closure $work): mixed
    {
        try {
            return $work();
        } catch (NotFoundException) {
            throw HttpException::notFound();
        } catch (AccessDeniedException) {
            throw HttpException::forbidden();
        }
    }

    /** Flashes every field error as one message (forms embedded in a detail page). */
    private function flashErrors(ValidationException|RuleViolation $e): void
    {
        $this->session->flash('flash.error', implode(' ', array_merge(...array_values($this->errorsOf($e)))));
    }

    /** @return array<string, list<string>> */
    private function violationMessages(RuleViolation $violation): array
    {
        $messages = [];
        foreach ($violation->violations() as $field => $items) {
            foreach ($items as [$key, $params]) {
                $messages[$field][] = $this->translator->get($key, $params);
            }
        }
        return $messages;
    }

    /** Field errors from either a format (Validator) or a business (Domain) failure. */
    private function errorsOf(ValidationException|RuleViolation $e): array
    {
        return $e instanceof RuleViolation ? $this->violationMessages($e) : $e->errors();
    }

    /** HTML datetime-local value in the application zone → UTC instant. */
    private static function localToUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable(str_replace('T', ' ', $value), new DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /** UTC instant → HTML datetime-local value in the application zone. */
    private static function utcToLocalInput(?DateTimeImmutable $value): string
    {
        return $value === null ? '' : $value->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i');
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
