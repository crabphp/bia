<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolResult;
use Bia\Tool\ProcessRunner;
use Bia\Tool\ShellOutputTool;
use Bia\Tool\ShellSnapshot;
use Bia\Tool\Shells;
use Bia\Tool\ShellTool;
use Bia\Tool\Workspace;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class ShellToolTest extends TestCase
{
    private string $root;
    private ShellTool $shell;
    private ShellOutputTool $output;

    protected function setUp(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $this->root = (string) realpath(sys_get_temp_dir()) . '/bia-shell-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/sub', 0777, true);
        $runner = new ProcessRunner();
        $shells = new Shells($runner);
        $this->shell = new ShellTool(new Workspace($this->root), $runner, 10.0, $shells);
        $this->output = new ShellOutputTool($shells);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testItIsBashAtTheRootEachCallFresh(): void
    {
        if (!is_executable('/bin/bash') && !is_executable('/usr/bin/bash')) {
            self::markTestSkipped('No bash here.');
        }
        self::assertSame("yes\n", $this->call(['command' => '[[ 1 == 1 ]] && echo yes'])->text, 'bash, as models write it.');
        $this->call(['command' => 'cd sub && export X=1']);
        self::assertSame($this->root . "\n[]\n", $this->call(['command' => 'pwd; echo "[$X]"'])->text, 'A cd and a variable do not carry over.');
    }

    public function testItRunsInAWorkdirInsideTheWorkspaceOnly(): void
    {
        self::assertSame($this->root . "/sub\n", $this->call(['command' => 'pwd', 'workdir' => 'sub'])->text);
        self::assertFalse($this->call(['command' => 'pwd', 'workdir' => 'missing'])->ok);
        $this->expectException(\InvalidArgumentException::class);
        $this->call(['command' => 'pwd', 'workdir' => '../']);
    }

    public function testNothingWaitsForATerminal(): void
    {
        $result = $this->call(['command' => 'cat; echo "$PAGER $GIT_PAGER $GIT_EDITOR $GIT_TERMINAL_PROMPT"', 'timeout' => 5]);
        self::assertSame("cat cat true 0\n", $result->text, 'stdin is empty, so cat ends at once; pagers print, editors and prompts give up.');
    }

    public function testOutputIsCleanAndAFailureSaysHowItEnded(): void
    {
        self::assertSame("green\n100%\n", $this->call(['command' => "printf '\\033[32mgreen\\033[0m\\n 10%%\\r100%%\\n'"])->text, 'No colour codes, a progress line as last drawn.');

        $failed = $this->call(['command' => 'echo oops >&2; exit 3']);
        self::assertFalse($failed->ok);
        self::assertSame("oops\n[exit code 3]", $failed->text);

        $slow = $this->call(['command' => 'echo begun; sleep 5', 'timeout' => 1]);
        self::assertFalse($slow->ok);
        self::assertStringStartsWith("begun\n[stopped after 1s, its timeout; a server or watcher belongs in the background: true]", $slow->text);
    }

    public function testABackgroundCommandIsStartedReadAndStopped(): void
    {
        $started = $this->call(['command' => 'echo up; sleep 0.3; echo done; exit 4', 'background' => true]);
        self::assertTrue($started->ok);
        self::assertSame(1, $started->data['background']);

        $read = $this->read(['id' => 1, 'wait' => 5]);
        self::assertStringContainsString('up', $read->text, 'What it printed so far.');
        $end = $this->read(['id' => 1, 'wait' => 5]);
        $text = $read->text . $end->text;
        while (str_contains($end->text, 'still running')) {
            $end = $this->read(['id' => 1, 'wait' => 5]);
            $text .= $end->text;
        }
        self::assertStringContainsString('done', $text);
        self::assertStringEndsWith('[shell 1 exited with code 4]', $end->text);
        self::assertSame("(nothing new)\n[shell 1 exited with code 4]", $this->read(['id' => 1])->text, 'Each part is read once.');

        $this->call(['command' => 'sleep 30', 'background' => true]);
        self::assertStringEndsWith('[shell 2 was stopped]', $this->read(['id' => 2, 'kill' => true])->text);
        self::assertFalse($this->read(['id' => 9])->ok, 'No such shell.');
    }

    public function testWhileTheUserHasNotWrittenACommandRunsAsInTheForeground(): void
    {
        $quiet = static fn (): ?float => null;
        self::assertSame("cat cat true 0\n", $this->call(['command' => 'cat; echo "$PAGER $GIT_PAGER $GIT_EDITOR $GIT_TERMINAL_PROMPT"', 'timeout' => 5], $quiet)->text, 'stdin is empty here too.');
        self::assertSame("green\n100%\n", $this->call(['command' => "printf '\\033[32mgreen\\033[0m\\n 10%%\\r100%%\\n'"], $quiet)->text);
        $failed = $this->call(['command' => 'echo oops >&2; exit 3'], $quiet);
        self::assertSame("oops\n[exit code 3]", $failed->text);
        $slow = $this->call(['command' => 'echo begun; sleep 5', 'timeout' => 1], $quiet);
        self::assertFalse($slow->ok);
        self::assertStringStartsWith("begun\n[stopped after 1s, its timeout", $slow->text);
        self::assertSame([], (new \ReflectionProperty(Shells::class, 'shells'))->getValue((new \ReflectionProperty(ShellOutputTool::class, 'shells'))->getValue($this->output)), 'None of them is left as a background shell.');
    }

    public function testACommandNearlyDoneWhenTheUserWritesStillGivesItsResult(): void
    {
        $result = $this->call(['command' => 'echo first; sleep 0.5; echo second; exit 5', 'timeout' => 30], static fn (): ?float => Shells::GRACE);
        self::assertSame("first\nsecond\n[exit code 5]", $result->text, 'It ended within the grace: its result whole, nothing in the background.');
        self::assertArrayNotHasKey('background', $result->data);
    }

    public function testACommandStillRunningAfterTheGraceGoesOnInTheBackground(): void
    {
        $started = microtime(true);
        $result = $this->call(['command' => 'echo first; sleep ' . (Shells::GRACE + 1) . '; echo second; exit 5', 'timeout' => 30], static fn (): ?float => Shells::GRACE);
        self::assertLessThan(Shells::GRACE + 0.5, microtime(true) - $started, 'It did not wait for the command.');
        self::assertTrue($result->ok);
        self::assertSame(1, $result->data['background']);
        self::assertStringStartsWith("first\n[still running: moved to the background for the user, as shell 1.", $result->text);

        $text = '';
        do {
            $read = $this->read(['id' => 1, 'wait' => 5]);
            $text .= $read->text;
        } while (str_contains($read->text, 'still running'));
        self::assertStringContainsString('second', $text, 'What it printed afterwards is kept for shell_output.');
        self::assertStringNotContainsString('first', $text, 'What the result already had is not read again.');
        self::assertStringEndsWith('[shell 1 exited with code 5]', $text);
    }

    public function testAskedToAtOnceACommandGoesOnInTheBackgroundWithoutTheGrace(): void
    {
        $started = microtime(true);
        $result = $this->call(['command' => 'sleep 5', 'timeout' => 30], static fn (): ?float => microtime(true) - $started > 0.2 ? 0.0 : null);
        self::assertLessThan(0.6, microtime(true) - $started);
        self::assertSame(1, $result->data['background']);
        self::assertStringEndsWith('[shell 1 was stopped]', $this->read(['id' => 1, 'kill' => true])->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shells(): iterable
    {
        foreach (['bash', 'zsh'] as $name) {
            $path = trim((string) shell_exec('command -v ' . $name));
            if ($path !== '') {
                yield $name => [$path];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shells')]
    public function testTheUsersAliasesAndFunctionsAreThere(string $path): void
    {
        $home = $this->root . '/home';
        mkdir($home);
        $rc = "alias ssh.work='echo ssh to work'\ngreet() { echo \"hi \$1\"; }\n_completion() { echo no; }\necho noise\n";
        file_put_contents($home . '/' . (basename($path) === 'zsh' ? '.zshrc' : '.bashrc'), $rc);
        $runner = new ProcessRunner();
        $shells = new Shells($runner);
        $snapshot = new ShellSnapshot($path, $runner, ['HOME' => $home, 'ZDOTDIR' => $home]);
        $tool = new ShellTool(new Workspace($this->root), $runner, 10.0, $shells, $path, $snapshot);
        $call = fn (array $arguments): ToolResult => Task::spawn(EventLoop::current(), fn (): ToolResult => $tool->execute($arguments, new ToolContext($this->root, 'test')))->await();

        self::assertSame("ssh to work box\nhi 'you'\n", $call(['command' => "ssh.work box; greet \"'you'\""])->text, 'An alias with a dot and a function, quotes kept.');
        self::assertFalse($call(['command' => '_completion'])->ok, 'Completion functions are left out.');
        self::assertSame("[exit code 3]", $call(['command' => 'exit 3'])->text);
        self::assertStringContainsString("aliases and functions from their " . basename($path), $tool->definition()->description);

        $id = $call(['command' => 'ssh.work bg', 'background' => true])->data['background'];
        $text = '';
        do {
            $read = Task::spawn(EventLoop::current(), fn (): ToolResult => (new ShellOutputTool($shells))->execute(['id' => $id, 'wait' => 5], new ToolContext($this->root, 'test')))->await();
            $text .= $read->text;
        } while (str_contains($read->text, 'still running'));
        self::assertStringContainsString('ssh to work bg', $text, 'In the background too.');

        $file = $snapshot->file();
        self::assertIsString($file);
        unset($call, $tool, $snapshot);
        self::assertFileDoesNotExist($file, 'The snapshot goes with it.');
    }

    public function testAShellThatCannotStartGivesNoSnapshot(): void
    {
        $runner = new ProcessRunner();
        self::assertNull((new ShellSnapshot('/bin/sh', $runner))->file(), 'Not bash or zsh.');
        self::assertNull((new ShellSnapshot($this->root . '/bash', $runner))->file(), 'Not there.');
        $tool = new ShellTool(new Workspace($this->root), $runner, 10.0, null, null, new ShellSnapshot($this->root . '/zsh', $runner));
        self::assertSame("ok\n", Task::spawn(EventLoop::current(), fn (): ToolResult => $tool->execute(['command' => 'echo ok'], new ToolContext($this->root, 'test')))->await()->text, 'Commands run without it.');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(array $arguments, ?\Closure $steered = null): ToolResult
    {
        return Task::spawn(EventLoop::current(), fn (): ToolResult => $this->shell->execute($arguments, new ToolContext($this->root, 'test', steered: $steered)))->await();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function read(array $arguments): ToolResult
    {
        return Task::spawn(EventLoop::current(), fn (): ToolResult => $this->output->execute($arguments, new ToolContext($this->root, 'test')))->await();
    }
}
