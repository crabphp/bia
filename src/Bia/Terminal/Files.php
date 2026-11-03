<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Tool\ProcessRunner;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * The workspace's files for `@` completion, listed once, in the background,
 * the first time they are asked for: ripgrep's list, which leaves out what
 * .gitignore does, else git's.
 */
final class Files
{
    /** @var list<string>|null */
    private ?array $files = null;
    private bool $loading = false;
    /** Whether files may have come or gone since they were listed, as after a turn. */
    private bool $stale = false;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $root,
        private readonly int $limit = 50_000,
    ) {}

    /**
     * The files, or null while they are being listed; then $loaded is
     * called once they are.
     *
     * @param \Closure(): void $loaded
     * @return list<string>|null
     */
    public function get(\Closure $loaded): ?array
    {
        if (($this->files === null || $this->stale) && !$this->loading) {
            // Listed afresh in the background; a stale list is shown meanwhile, never an empty one.
            $this->loading = true;
            $this->stale = false;
            Task::spawn(EventLoop::current(), function () use ($loaded): void {
                try {
                    $files = $this->list();
                } finally {
                    $this->loading = false;
                }
                if ($files !== $this->files) {
                    $this->files = $files;
                    $loaded();
                }
            });
        }
        return $this->files;
    }

    /**
     * Files may have changed, as a turn's tools can make and remove them:
     * they are listed again the next time they are asked for.
     */
    public function changed(): void
    {
        $this->stale = true;
    }

    /**
     * @return list<string>
     */
    private function list(): array
    {
        foreach ([['rg', '--files', '--hidden', '-g', '!.git'], ['git', 'ls-files', '--cached', '--others', '--exclude-standard']] as $command) {
            try {
                $result = $this->runner->run($command, $this->root, 10.0, 16_777_216);
            } catch (\Throwable) {
                continue;
            }
            if ($result->exitCode === 0 || $result->truncated) {
                $files = array_values(array_filter(explode("\n", $result->stdout), static fn (string $file): bool => $file !== ''));
                sort($files);
                return array_slice($files, 0, $this->limit);
            }
        }
        return [];
    }
}
