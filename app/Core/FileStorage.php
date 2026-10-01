<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Private file store (STORAGE_PATH, outside public/). Paths handed out are
 * relative; every access is confined to the root directory.
 */
class FileStorage
{
    private readonly string $root;

    /** @param bool $uploadsOnly true in production: only files received through HTTP upload can be stored */
    public function __construct(string $root, private readonly bool $uploadsOnly = true)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /** Moves a received file into the store. */
    public function storeUpload(string $tmpPath, string $relativePath): void
    {
        $target = $this->path($relativePath);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
        if (is_file($target)) {
            throw new RuntimeException("File already exists: {$relativePath}");
        }

        $moved = $this->uploadsOnly ? move_uploaded_file($tmpPath, $target) : rename($tmpPath, $target);
        if (!$moved) {
            throw new RuntimeException('Cannot store the uploaded file.');
        }
        @chmod($target, 0640);
    }

    public function isUpload(string $tmpPath): bool
    {
        return $this->uploadsOnly ? is_uploaded_file($tmpPath) : is_file($tmpPath);
    }

    public function delete(string $relativePath): void
    {
        $path = $this->path($relativePath);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->path($relativePath));
    }

    /** Absolute path; rejects traversal and absolute input. */
    public function path(string $relativePath): string
    {
        $relative = str_replace('\\', '/', $relativePath);
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.?(/|$)|[\x00-\x1F]|^[a-z]:#i', $relative)) {
            throw new InvalidArgumentException("Invalid storage path: {$relativePath}");
        }
        return $this->root . '/' . $relative;
    }
}
