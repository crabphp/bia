<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ToolContext;
use Bia\Tool\ApplyPatchTool;
use Bia\Tool\ListFilesTool;
use Bia\Tool\ProcessRunner;
use Bia\Tool\ReadFileTool;
use Bia\Tool\SearchTool;
use Bia\Tool\ShellTool;
use Bia\Tool\Workspace;
use Bia\Tool\WriteFileTool;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    public function testWorkspaceRejectsTraversal(): void
    {
        $workspace = new Workspace($this->directory());

        $this->expectException(\InvalidArgumentException::class);
        $workspace->path('../outside');
    }

    public function testApplyPatchAppliesInsideWorkspaceAndRejectsTraversal(): void
    {
        $root = $this->directory();
        file_put_contents($root . '/a.txt', "one\n");
        $patch = new ApplyPatchTool(new Workspace($root), new ProcessRunner());
        $context = new ToolContext($root, 'test');

        $ok = $patch->execute([
            'patch' => "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-one\n+two\n",
        ], $context);
        self::assertTrue($ok->ok, $ok->text);
        self::assertSame("two\n", file_get_contents($root . '/a.txt'));

        $this->expectException(\InvalidArgumentException::class);
        $patch->execute([
            'patch' => "--- /dev/null\n+++ b/../outside.txt\n@@ -0,0 +1 @@\n+no\n",
        ], $context);
    }

    public function testReadWriteSearchAndShell(): void
    {
        $root = $this->directory();
        $workspace = new Workspace($root);
        $context = new ToolContext($root, 'test');

        self::assertTrue((new WriteFileTool($workspace))->execute(['path' => 'nested/a.txt', 'content' => 'hello'], $context)->ok);
        self::assertSame('hello', (new ReadFileTool($workspace))->execute(['path' => 'nested/a.txt'], $context)->text);

        $result = (new ShellTool($workspace, new ProcessRunner()))->execute(['command' => 'printf ok'], $context);
        self::assertTrue($result->ok);
        self::assertSame('ok', $result->text);

        $failed = (new ShellTool($workspace, new ProcessRunner()))->execute(['command' => 'echo no; exit 4'], $context);
        self::assertFalse($failed->ok);
        self::assertStringContainsString('[exit code 4]', $failed->text);

        $found = (new SearchTool($workspace, new ProcessRunner()))->execute(['pattern' => 'hello'], $context);
        self::assertTrue($found->ok);
        self::assertStringContainsString('nested/a.txt:1:hello', $found->text);
    }

    public function testWritingKeepsAnExistingFilesMode(): void
    {
        $root = $this->directory();
        file_put_contents($root . '/run.sh', "#!/bin/sh\n");
        chmod($root . '/run.sh', 0755);

        (new WriteFileTool(new Workspace($root)))->execute(['path' => 'run.sh', 'content' => "#!/bin/sh\necho hi\n"], new ToolContext($root, 'test'));
        clearstatcache();

        self::assertSame(0755, fileperms($root . '/run.sh') & 0777);
    }

    public function testReadingByLinesAndRefusingBinary(): void
    {
        $root = $this->directory();
        file_put_contents($root . '/lines.txt', implode("\n", range(1, 10)) . "\n");
        file_put_contents($root . '/blob.bin', "ab\0cd");
        $read = new ReadFileTool(new Workspace($root));
        $context = new ToolContext($root, 'test');

        $part = $read->execute(['path' => 'lines.txt', 'offset' => 3, 'limit' => 2], $context);
        self::assertSame("3\n4\n[lines 3-4 shown; read on with offset 5]", $part->text);
        self::assertFalse($read->execute(['path' => 'blob.bin'], $context)->ok);
    }

    public function testListingKeepsHiddenFileNames(): void
    {
        $root = $this->directory();
        mkdir($root . '/sub');
        touch($root . '/.env.example');
        touch($root . '/sub/.hidden');
        $list = new ListFilesTool(new Workspace($root), new ProcessRunner());
        $context = new ToolContext($root, 'test');

        $all = explode("\n", $list->execute([], $context)->text);
        sort($all);
        self::assertSame(['.env.example', 'sub/.hidden'], $all);
        self::assertSame('sub/.hidden', $list->execute(['path' => 'sub'], $context)->text);
        self::assertTrue($list->execute(['path' => ''], $context)->ok, 'An empty path is the workspace.');
    }

    public function testShellCommandsDoNotSeeTheModelKey(): void
    {
        $root = $this->directory();
        $runner = new ProcessRunner(['secret-key'], ['PATH' => '/usr/bin:/bin', 'OPENAI_API_KEY' => 'secret-key', 'BIA_MODEL' => 'x', 'GH_TOKEN' => 'kept']);

        $result = (new ShellTool(new Workspace($root), $runner))->execute(['command' => 'env'], new ToolContext($root, 'test'));

        self::assertStringNotContainsString('secret-key', $result->text);
        self::assertStringNotContainsString('BIA_MODEL', $result->text);
        self::assertStringContainsString('GH_TOKEN=kept', $result->text, 'The user\'s other credentials belong to the agent too.');
    }

    public function testAShellCommandCanBeCancelled(): void
    {
        $root = $this->directory();
        $cancellation = new Cancellation();
        EventLoop::current()->delay(0.1, static fn () => $cancellation->cancel());
        $started = microtime(true);

        try {
            (new ShellTool(new Workspace($root), new ProcessRunner()))->execute(['command' => 'sleep 10'], new ToolContext($root, 'test'), $cancellation);
            self::fail('A cancelled command returned.');
        } catch (CancelledException) {
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/bia-tools-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        return realpath($directory);
    }
}
