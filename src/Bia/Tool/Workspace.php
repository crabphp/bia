<?php

declare(strict_types=1);

namespace Bia\Tool;

/**
 * Resolves model-provided paths inside one workspace and rejects traversal.
 */
final readonly class Workspace
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException("Workspace {$root} is not a directory.");
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolves an existing or new path and keeps it below the workspace.
     */
    public function path(string $relative, bool $allowMissingParents = false): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('A workspace path must not be empty or contain NUL.');
        }
        if (in_array('..', preg_split('/[\\\\\/]+/', $relative) ?: [], true)) {
            throw new \InvalidArgumentException('A workspace path must not contain parent traversal.');
        }
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1) {
            throw new \InvalidArgumentException('A tool path must be relative to the workspace.');
        }
        $candidate = $this->root . DIRECTORY_SEPARATOR . $relative;
        $existing = realpath($candidate);
        if ($existing !== false) {
            $resolved = $existing;
        } else {
            $parentCandidate = dirname($candidate);
            $parent = realpath($parentCandidate);
            if ($parent === false && $allowMissingParents) {
                while ($parent === false && $parentCandidate !== dirname($parentCandidate)) {
                    $parentCandidate = dirname($parentCandidate);
                    $parent = realpath($parentCandidate);
                }
            }
            if ($parent === false) {
                throw new \InvalidArgumentException("The parent of {$relative} does not exist.");
            }
            $missing = substr($candidate, strlen($parentCandidate));
            $resolved = $parent . str_replace('/', DIRECTORY_SEPARATOR, $missing);
        }
        if ($resolved !== $this->root && !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Path {$relative} leaves the workspace.");
        }
        return $resolved;
    }
}
