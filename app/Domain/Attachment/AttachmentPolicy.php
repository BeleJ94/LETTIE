<?php

declare(strict_types=1);

namespace App\Domain\Attachment;

use App\Domain\RuleViolation;

/**
 * Accepted files: PDF and images. The MIME type must be the one detected from
 * the file content (never the browser-declared one), and the extension is
 * derived from it (never from the uploaded name).
 */
final class AttachmentPolicy
{
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    /** @var array<string, string> MIME type => stored extension */
    public const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/tiff' => 'tif',
    ];

    /** Types browsers display safely inline (TIFF is download-only). */
    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @return string extension to store the file with
     * @throws RuleViolation
     */
    public function check(string $detectedMime, int $sizeBytes): string
    {
        if ($sizeBytes <= 0) {
            throw RuleViolation::single('file', 'rules.attachment.empty');
        }
        if ($sizeBytes > $this->maxBytes) {
            throw RuleViolation::single('file', 'rules.attachment.too_large', ['max' => (int) ceil($this->maxBytes / 1048576)]);
        }
        if (!isset(self::ALLOWED[$detectedMime])) {
            throw RuleViolation::single('file', 'rules.attachment.type');
        }
        return self::ALLOWED[$detectedMime];
    }

    public static function isInline(string $mime): bool
    {
        return in_array($mime, self::INLINE, true);
    }

    /** Display name: no path, no control characters, at most 200 characters. */
    public static function sanitizeName(string $original): string
    {
        $name = str_replace('\\', '/', $original);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name, " .\t");
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return 'document';
        }
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $keep = 200 - ($ext === '' ? 0 : mb_strlen($ext) + 1);
            $name = mb_substr($name, 0, $keep) . ($ext === '' ? '' : '.' . $ext);
        }
        return $name;
    }
}
