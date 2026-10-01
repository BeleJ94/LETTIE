<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Domain\Attachment\Attachment;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\RuleViolation;
use App\Services\AttachmentService;
use App\Services\AuthService;

final class AttachmentController
{
    use HandlesDomain;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AuthService $auth,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly Url $url,
    ) {
    }

    public function store(Request $request): Response
    {
        $mailId = $this->routeId($request);
        try {
            $attachment = $this->orNotFound(
                fn (): Attachment => $this->attachments->upload($this->actor($request), $mailId, $request->file('file'))
            );
            $message = $this->translator->get('attachment.added', ['name' => $attachment->originalName]);
            if ($request->wantsJson()) {
                return Response::json(['data' => ['id' => $attachment->id, 'name' => $attachment->originalName]], 201);
            }
            $this->session->flash('flash.success', $message);
        } catch (RuleViolation $e) {
            $errors = $this->violationMessages($e);
            if ($request->wantsJson()) {
                return Response::json(['error' => $this->translator->get('js.errors.validation'), 'errors' => $errors], 422);
            }
            $this->session->flash('flash.error', implode(' ', array_merge(...array_values($errors))));
        }
        return Response::redirect($this->url->route('mails.show', ['id' => $mailId]) . '#attachments');
    }

    /** Streams the file (?inline=1 displays PDF/images in the browser). */
    public function download(Request $request): Response
    {
        [$attachment, $path] = $this->orNotFound(fn (): array => $this->attachments->forDownload($this->routeId($request)));
        $inline = $request->query('inline') === '1' && AttachmentPolicy::isInline($attachment->mimeType);

        return Response::file($path, $attachment->mimeType, $attachment->originalName, $inline)
            // A stored file is never a page: no script may run in its context.
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; object-src 'self'; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
