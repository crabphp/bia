<?php

declare(strict_types=1);

namespace Bia\Tool;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Process;
use Crab\Engine\Process\Subprocess;
use Crab\Engine\Process\SubprocessResult;

/**
 * Runs tool subprocesses on Crab's event loop, so they can be cancelled and
 * run beside each other, with Bia's own credentials kept out of their
 * environment: a model that runs `env` must not see the key it is called with.
 */
final readonly class ProcessRunner
{
    /**
     * @var array<string, string>
     */
    private array $environment;

    /**
     * @param list<string> $secrets Values no variable may carry, such as the model's API key.
     * @param array<string, string>|null $environment The environment to filter; null for this process's.
     */
    public function __construct(array $secrets = [], ?array $environment = null)
    {
        $this->environment = self::filter($environment ?? getenv(), $secrets);
    }

    /**
     * @param list<string> $command
     */
    public function run(
        array $command,
        string $cwd,
        float $timeout = 30.0,
        int $maxBytes = 1_048_576,
        ?string $stdin = null,
        ?Cancellation $cancellation = null,
    ): SubprocessResult {
        return Subprocess::run(
            $command,
            directory: $cwd,
            environment: $this->environment,
            input: $stdin,
            timeout: $timeout,
            maxBytes: $maxBytes,
            cancellation: $cancellation,
        );
    }

    /**
     * Starts a command that keeps running, to be talked to while it runs, as
     * an MCP server is; with the same filtered environment, and what it is
     * given added on top.
     *
     * @param list<string> $command
     * @param array<string, string> $environment Variables of its own, over the filtered environment.
     */
    public function start(array $command, string $cwd, array $environment = []): Process
    {
        return Process::start($command, directory: $cwd, environment: [...$this->environment, ...$environment]);
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return $this->environment;
    }

    /**
     * Drops Bia's own settings and every variable holding one of the secrets.
     * Other credentials stay: the agent works with the user's tools (gh,
     * npm, cloud CLIs) as the user would.
     *
     * @param array<string, string> $environment
     * @param list<string> $secrets
     * @return array<string, string>
     */
    public static function filter(array $environment, array $secrets = []): array
    {
        $secrets = array_filter($secrets, static fn (string $secret): bool => $secret !== '');
        return array_filter(
            $environment,
            static fn (string $value, string $name): bool => !str_starts_with($name, 'BIA_')
                && !in_array($value, $secrets, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
