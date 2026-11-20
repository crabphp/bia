<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Terminal\Loop;
use Bia\Tool\ProcessRunner;
use Crab\Engine\Process\SubprocessResult;
use PHPUnit\Framework\TestCase;

final class JoinCliTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/bia-join-cli-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700);
    }

    protected function tearDown(): void
    {
        rmdir($this->home);
    }

    public function testHelpListsTheDirectJoinCommandWithoutLoadingAConversation(): void
    {
        foreach ([['--help'], ['join', '--help']] as $arguments) {
            $result = $this->runBia($arguments);
            self::assertSame(0, $result->exitCode);
            self::assertSame('', $result->stderr);
            self::assertStringContainsString('bia join <code> [relay-url ...]', $result->stdout);
        }
    }

    public function testMissingCodeAndTooManyRelaysShowUsage(): void
    {
        foreach ([['join'], ['join', ''], ['join', 'a7K2m9Q4x8Lp', ...array_fill(0, 4, 'wss://relay.example')]] as $arguments) {
            $result = $this->runBia($arguments);
            self::assertSame(1, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertStringContainsString('Usage: bia join <code> [relay-url ...]', $result->stderr);
        }
    }

    public function testJoinRefusesJsonAndResumeBeforeOpeningASession(): void
    {
        foreach ([['--json'], ['--resume', 'session-id']] as $options) {
            $result = $this->runBia(['join', 'a7K2m9Q4x8Lp', ...$options]);
            self::assertSame(1, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertStringContainsString('bia join cannot be used with --json or --resume.', $result->stderr);
        }
    }

    public function testInvalidCodeAndRelayUrlsFailBeforeOpeningTheTui(): void
    {
        foreach ([
            [['join', 'short'], 'Expected a 12-character share code'],
            [['join', 'a7K2m9Q4x8Lp', 'http://relay.example'], 'Share relays must be wss:// URLs'],
            [['join', 'a7K2m9Q4x8Lp', 'ws://relay.example'], 'Share relays must be wss:// URLs'],
            [['join', 'bia1.invalid'], 'Invalid share invite'],
        ] as [$arguments, $message]) {
            $result = $this->runBia($arguments);
            self::assertSame(1, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertStringContainsString($message, $result->stderr);
        }
    }

    public function testNonInteractiveJoinNeverFallsThroughToAModelPrompt(): void
    {
        foreach ([[], ['--api-key', 'fixture-key'], ['ws://127.0.0.1:12345']] as $extra) {
            $result = $this->runBia(['join', 'a7K2m9Q4x8Lp', ...$extra]);
            self::assertSame(1, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertStringContainsString('bia join needs an interactive terminal.', $result->stderr);
        }
    }

    private function runBia(array $arguments): SubprocessResult
    {
        $runner = new ProcessRunner(environment: ['HOME' => $this->home, 'TERM' => 'xterm-256color']);
        $result = Loop::run(fn ($cancellation) => $runner->run(
            [PHP_BINARY, dirname(__DIR__) . '/bin/bia', ...$arguments], $this->home,
            timeout: 10, cancellation: $cancellation, environment: ['BIA_HOME' => $this->home . '/bia'],
        ));
        self::assertFalse($result->timedOut);
        self::assertSame([], glob($this->home . '/*'), 'Help and rejected joins must not create a config, history, or database.');
        return $result;
    }
}
