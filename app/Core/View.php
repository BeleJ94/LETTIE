<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use Throwable;

/**
 * Native PHP template engine.
 * In a template: $this->layout('layouts/main') wraps the output; the layout
 * prints it with $this->section('content'). Other sections: start()/stop().
 * Always escape output with e().
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    private ?string $layout = null;

    public function __construct(private readonly string $viewsDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        $this->sections = [];
        $this->layout = null;

        $content = $this->renderFile($name, $data);

        // Layouts may themselves declare a parent layout.
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            $this->sections['content'] ??= $content;
            $content = $this->renderFile($layout, $data);
        }

        return $content;
    }

    /** @param array<string, mixed> $data */
    public function partial(string $name, array $data = []): string
    {
        return $this->renderFile($name, $data);
    }

    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    public function stop(): void
    {
        $section = array_pop($this->sectionStack);
        if ($section === null) {
            throw new InvalidArgumentException('stop() called without matching start().');
        }
        $this->sections[$section] = (string) ob_get_clean();
    }

    /** Raw HTML of a section (already rendered by templates). */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function resolve(string $name): string
    {
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#i', $name)) {
            throw new InvalidArgumentException("Invalid view name: {$name}");
        }
        $file = rtrim($this->viewsDir, '/\\') . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException("View not found: {$name}");
        }
        return $file;
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $name, array $data): string
    {
        $__file = $this->resolve($name);
        $__vars = array_merge($this->shared, $data);
        $level = ob_get_level();
        ob_start();
        try {
            (function () use ($__file, $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })->call($this);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
