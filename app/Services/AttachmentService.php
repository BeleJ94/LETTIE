<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\FileStorage;
use App\Core\Transaction;
use App\Domain\Attachment\Attachment;
use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Audit\Actor;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Repositories\AttachmentRepository;
use App\Repositories\MailRepository;
use finfo;
use Throwable;

final class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly MailRepository $mails,
        private readonly FileStorage $storage,
        private readonly AttachmentPolicy $policy,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed>|null $file one $_FILES entry
     * @throws NotFoundException
     * @throws RuleViolation
     */
    public function upload(Actor $actor, int $mailId, ?array $file): Attachment
    {
        $mail = $this->mails->findById($mailId) ?? throw NotFoundException::of('Mail', $mailId);
        if (!$mail->isEditable()) {
            throw RuleViolation::single('file', 'rules.mail.archived');
        }

        $tmp = $this->checkUpload($file);
        $size = (int) filesize($tmp);
        // Type from the content, never from the browser or the file name.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = $this->policy->check($mime, $size);
        if (str_starts_with($mime, 'image/') && $mime !== 'image/tiff' && @getimagesize($tmp) === false) {
            throw RuleViolation::single('file', 'rules.attachment.type');
        }

        $sha256 = (string) hash_file('sha256', $tmp);
        $name = AttachmentPolicy::sanitizeName((string) ($file['name'] ?? ''));
        $relative = sprintf('%d/%s/%s.%s', $mail->siteId, $this->clock->now()->format('Y/m'), bin2hex(random_bytes(16)), $extension);

        $this->storage->storeUpload($tmp, $relative);
        try {
            return $this->transaction->run(function () use ($actor, $mail, $name, $relative, $mime, $size, $sha256): Attachment {
                $id = $this->attachments->create($mail->id, $mail->siteId, $name, $relative, $mime, $size, $sha256, $actor->user->id);
                $this->audit->event($actor, MailService::ENTITY, $mail->id, 'attachment_added', $mail->siteId, null, [
                    'attachment_id' => $id,
                    'original_name' => $name,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                ]);
                return $this->attachments->findById($id) ?? throw NotFoundException::of('Attachment', $id);
            });
        } catch (Throwable $e) {
            // No orphan file when the database refuses the row.
            $this->storage->delete($relative);
            throw $e;
        }
    }

    /**
     * @return array{0: Attachment, 1: string} the attachment and its absolute path
     * @throws NotFoundException
     */
    public function forDownload(int $attachmentId): array
    {
        $attachment = $this->attachments->findById($attachmentId) ?? throw NotFoundException::of('Attachment', $attachmentId);
        if ($attachment->isPurged()) {
            throw NotFoundException::of('Attachment', $attachmentId);
        }
        if (!$this->storage->exists($attachment->storedPath)) {
            error_log("Attachment #{$attachmentId}: file missing ({$attachment->storedPath})");
            throw NotFoundException::of('Attachment', $attachmentId);
        }
        return [$attachment, $this->storage->path($attachment->storedPath)];
    }

    /** @return list<Attachment> */
    public function forMail(int $mailId): array
    {
        return $this->attachments->listForMail($mailId);
    }

    /** @param array<string, mixed>|null $file */
    private function checkUpload(?array $file): string
    {
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $key = match ($error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_NO_FILE => 'rules.attachment.required',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'rules.attachment.too_large',
            default => 'rules.attachment.failed',
        };
        if ($key !== null) {
            throw RuleViolation::single('file', $key, ['max' => (int) ceil($this->policy->maxBytes() / 1048576)]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !$this->storage->isUpload($tmp)) {
            throw RuleViolation::single('file', 'rules.attachment.failed');
        }
        return $tmp;
    }
}
