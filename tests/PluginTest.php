<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolResult;
use Bia\Plugin\Installer;
use Bia\Plugin\Observed;
use Bia\Plugin\Plugins;
use Bia\Skill\Catalog;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\Renderer;
use Bia\Terminal\Loop;
use Bia\Tool\ProcessRunner;
use Bia\Tool\Registry;
use Bia\Tool\ReadFileTool;
use Bia\Tool\Workspace;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private string $root;
    private string $plugins;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bia-plugins-' . bin2hex(random_bytes(4));
        $this->plugins = "{$this->root}/home/plugins";
        mkdir($this->plugins, 0777, true);
    }

    public function testAPluginAddsCommandsToolsAndListeners(): void
    {
        $this->plugin('standup', <<<'PHP'
            <?php
            return function (Bia\Plugin\Host $bia): void {
                $bia->command('Standup', 'What I did today', fn (string $rest) => $bia->ask("Summarize today. {$rest}"));
                $bia->command('where', 'Where the plugin is', fn (string $rest) => $bia->note($bia->name . ' in ' . basename($bia->workspace)));
                $bia->tool('word_count', 'Counts the words of a text.', fn (array $arguments) => str_word_count($arguments['text']), [
                    'type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'],
                ]);
                $bia->tool('boom', 'Fails.', fn (array $arguments) => throw new RuntimeException('no luck'));
                $bia->on('tool.result', fn (array $event) => $bia->note($event['tool'] . ($event['ok'] ? ' ok' : ' failed')));
                $bia->on('turn.done', fn (array $event) => $bia->note('done: ' . $event['text']));
            };
            PHP);
        $plugins = (new Plugins($this->plugins, '/work/project'))->load();
        $notes = $asked = $failed = [];
        $plugins->attach(
            static function (string $text) use (&$notes): void { $notes[] = $text; },
            static function (string $text) use (&$asked): void { $asked[] = $text; },
            static function (string $plugin, string $message) use (&$failed): void { $failed[] = "{$plugin}: {$message}"; },
        );

        self::assertSame(['standup' => 'What I did today', 'where' => 'Where the plugin is'], $plugins->commands(), 'A command is named in lower case.');
        self::assertSame(['word_count', 'boom'], $plugins->all()['standup']['tools']);
        self::assertSame(['tool.result', 'turn.done'], $plugins->all()['standup']['events']);

        self::assertTrue($plugins->run('standup', 'In two lines.'));
        self::assertSame(['Summarize today. In two lines.'], $asked, 'A command may send the model a message.');
        self::assertTrue($plugins->run('where', ''));
        self::assertSame(['standup in project'], $notes);
        self::assertFalse($plugins->run('nope', ''), 'No plugin has it: the conversation says so.');

        $registry = (new Registry())->add(new ReadFileTool(new Workspace($this->root)))->from($plugins->tools(...));
        $names = array_map(static fn ($definition): string => $definition->name, $registry->definitions());
        self::assertSame(['read_file', 'plugin__standup__word_count', 'plugin__standup__boom'], $names, "Named by its plugin, so it can never take one of Bia's own tools' place.");
        $context = new ToolContext($this->root, 'session');
        $counted = $registry->execute('plugin__standup__word_count', ['text' => 'one two three'], $context);
        self::assertSame(['3', true], [$counted->text, $counted->ok], 'What is not a string is sent as JSON.');
        $boom = $registry->execute('plugin__standup__boom', [], $context);
        self::assertSame(['no luck', false], [$boom->text, $boom->ok], 'A tool that throws fails its call.');
        self::assertSame([], $failed, 'And the plugin stays on.');

        $notes = [];
        $shown = new class implements Renderer {
            public int $told = 0;
            public function event(ModelEvent $event): void {}
            public function running(ToolCall $call, string $output): void {}
            public function tool(ToolCall $call, ToolResult $result): void { $this->told++; }
            public function notice(string $message): void {}
            public function finish(ModelResult $result): void { $this->told++; }
        };
        $observed = new Observed($shown, $plugins);
        $observed->tool(new ToolCall('1', 'shell', ['command' => 'false']), new ToolResult('exit 1', false));
        $observed->finish(new ModelResult('All done.'));
        self::assertSame(2, $shown->told, 'The renderer is told as before.');
        $plugins->emit('turn.done', ['text' => 'All done.']);
        self::assertSame(['shell failed', 'done: All done.'], $notes, 'Listeners are told of tool calls by the renderer, of turns by the conversation.');
    }

    public function testAPluginThatThrowsIsOffAndTheOthersGoOn(): void
    {
        $this->plugin('good', "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('hi', 'Says hi', fn () => \$bia->note('hi'));\n};\n");
        $this->plugin('late', <<<'PHP'
            <?php
            return function (Bia\Plugin\Host $bia): void {
                $bia->command('late', 'Fails when run', fn () => throw new LogicException("broke\nlater"));
                $bia->tool('t', 'A tool.', fn () => 'x');
                $bia->on('tool.call', fn () => null);
            };
            PHP);
        $this->plugin('bad-event', "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->on('tool.block', fn () => null);\n};\n");
        $this->plugin('bad-syntax', "<?php\nreturn function ( {\n");
        $this->plugin('no-closure', "<?php\nreturn 'hello';\n");
        $this->plugin('taken', "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('hi', 'Again', fn () => null);\n};\n");
        mkdir("{$this->plugins}/.hidden");
        file_put_contents("{$this->plugins}/.hidden/plugin.php", "<?php\nthrow new Exception('never run');\n");
        mkdir("{$this->plugins}/empty");

        $plugins = (new Plugins($this->plugins))->load();
        $all = $plugins->all();
        self::assertSame(['bad-event', 'bad-syntax', 'good', 'late', 'no-closure', 'taken'], array_keys($all), 'A folder with none of the parts is no plugin, nor is a hidden one.');
        self::assertNull($all['good']['error']);
        self::assertStringContainsString('There is no event tool.block', (string) $all['bad-event']['error']);
        self::assertNotNull($all['bad-syntax']['error']);
        self::assertSame('plugin.php must return a closure', $all['no-closure']['error']);
        self::assertSame("/hi is already good's.", $all['taken']['error']);

        $failed = [];
        $plugins->attach(static fn () => null, static fn () => null, static function (string $plugin, string $message) use (&$failed): void { $failed[$plugin] = $message; });
        self::assertSame(['bad-event', 'bad-syntax', 'no-closure', 'taken'], array_keys($failed), 'What failed as it loaded is told once someone can be.');

        self::assertCount(1, $plugins->tools());
        self::assertTrue($plugins->run('late', ''));
        self::assertSame('broke later', $failed['late'], 'In one line.');
        self::assertSame(['hi' => 'Says hi'], $plugins->commands(), 'Its commands are gone,');
        self::assertSame([], $plugins->tools(), 'and its tools.');
        self::assertFalse($plugins->run('late', ''));
        self::assertTrue($plugins->run('hi', ''), 'The others go on.');

        self::assertSame(['late'], array_keys(array_filter($plugins->load()->all(), static fn (array $plugin): bool => $plugin['commands'] !== [] && isset($plugin['commands']['late']))), 'Read again, it is on again.');
    }

    public function testAChangedPluginIsLoadedAgainUnlessItDeclaresANamedClassOrFunction(): void
    {
        $body = static fn (string $said): string => "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$counter = new class { public int \$n = 0; public function next(): int { return ++\$this->n; } };\n    \$bia->command('say', '{$said}', fn () => \$bia->note(\$bia::class . \$counter->next()));\n};\n";
        $this->plugin('live', $body('First'));
        $plugins = (new Plugins($this->plugins))->load();
        self::assertSame(['say' => 'First'], $plugins->commands());

        $this->plugin('live', $body('Second'));
        self::assertSame(['say' => 'Second'], $plugins->load()->commands(), 'Closures and anonymous classes can be read again, in the same process.');

        $unique = 'BiaPluginTest' . bin2hex(random_bytes(4));
        $named = static fn (string $said): string => "<?php\nfinal class {$unique} {}\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('named', '{$said}', fn () => null);\n};\n";
        $this->plugin('named', $named('Third'));
        self::assertSame('Third', $plugins->load()->commands()['named'] ?? null, 'A named class can be declared once.');
        $this->plugin('named', $named('Fourth'));
        $plugins->load();
        self::assertSame(['say' => 'Second'], $plugins->commands(), "The other plugin's command is its own again.");
        self::assertStringContainsString('start a new bia', (string) $plugins->all()['named']['error'], 'Twice would end Bia: it is left off instead.');
        self::assertNotNull($plugins->load()->all()['named']['error'], 'And stays so while it is as it is.');
    }

    public function testAPluginBringsSkillsAndIsFoundWithoutCode(): void
    {
        mkdir("{$this->plugins}/notes/skills/release-notes", 0777, true);
        file_put_contents("{$this->plugins}/notes/skills/release-notes/SKILL.md", "---\nname: release-notes\ndescription: The plugin's way.\n---\nFrom the plugin.\n");
        mkdir("{$this->plugins}/notes/skills/changelog", 0777, true);
        file_put_contents("{$this->plugins}/notes/skills/changelog/SKILL.md", "---\nname: changelog\ndescription: Writes the changelog.\n---\nSteps.\n");
        file_put_contents("{$this->plugins}/notes/mcp.json", json_encode(['mcpServers' => ['notes' => ['command' => 'php', 'args' => ['${BIA_PLUGIN}/server.php']]]]));
        mkdir("{$this->root}/home/.agents/skills/release-notes", 0777, true);
        file_put_contents("{$this->root}/home/.agents/skills/release-notes/SKILL.md", "---\nname: release-notes\ndescription: My own way.\n---\nMine.\n");

        $plugin = (new Plugins($this->plugins))->load()->all()['notes'];
        self::assertSame([['changelog', 'release-notes'], ['notes'], null], [$plugin['skills'], $plugin['servers'], $plugin['error']]);
        self::assertSame('2 skills · 1 MCP server', \Bia\Cli\Application::pluginLine($plugin));

        $skills = Catalog::for("{$this->root}/home", "{$this->root}/project", $this->plugins)->all();
        self::assertSame(['bia-plugin', 'changelog', 'release-notes'], array_keys($skills), "Bia's own skill on writing a plugin comes with them.");
        self::assertSame('My own way.', $skills['release-notes']->description, "The user's own skill takes the place of a plugin's.");
        self::assertStringContainsString('Bia\Plugin\Host', $skills['bia-plugin']->instructions());

        $servers = \Bia\Mcp\Server::load("{$this->plugins}/notes/mcp.json", false, ['BIA_PLUGIN' => "{$this->plugins}/notes"])['servers'];
        self::assertSame(["{$this->plugins}/notes/server.php"], $servers[0]->args, "Its servers are started from the plugin's folder.");
    }

    public function testPluginsAreInstalledFromAFolderAndRemoved(): void
    {
        $source = "{$this->root}/src/Standup";
        mkdir("{$source}/.git", 0777, true);
        mkdir("{$source}/lib");
        file_put_contents("{$source}/plugin.php", "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('standup', 'Today', fn () => null);\n};\n");
        file_put_contents("{$source}/lib/helper.txt", 'kept');
        file_put_contents("{$source}/.git/config", 'not copied');
        mkdir("{$this->root}/src/nothing");
        file_put_contents("{$this->root}/src/nothing/README.md", 'no plugin');

        $installer = new Installer($this->plugins, new ProcessRunner());
        self::assertSame('standup', $installer->add($source), 'Named after its folder, in lower case.');
        self::assertSame('kept', file_get_contents("{$this->plugins}/standup/lib/helper.txt"));
        self::assertFileDoesNotExist("{$this->plugins}/standup/.git");
        self::assertSame(['standup' => 'Today'], (new Plugins($this->plugins))->load()->commands());

        try {
            $installer->add($source);
            self::fail('Installed twice.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('installed already', $error->getMessage());
        }
        self::assertSame('daily', $installer->add($source, 'daily'), 'Or under a name given.');
        try {
            $installer->add("{$this->root}/src/nothing");
            self::fail('Not a plugin.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('is not a Bia plugin', $error->getMessage());
        }
        self::assertSame(['daily', 'standup'], array_keys(Plugins::folders($this->plugins)), 'Nothing is left of one that could not be installed.');
        self::assertSame(['.', '..', 'daily', 'standup'], scandir($this->plugins));

        $installer->remove('daily');
        self::assertSame(['standup'], array_keys(Plugins::folders($this->plugins)));
        $this->expectExceptionMessage('No plugin is named ../standup');
        $installer->remove('../standup');
    }

    public function testAPluginIsInstalledFromAGitRepository(): void
    {
        $repository = "{$this->root}/repo/bia-hello.git";
        mkdir($repository, 0777, true);
        file_put_contents("{$repository}/plugin.php", "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('hello', 'Hi', fn () => null);\n};\n");
        $git = static fn (string ...$command) => exec('git -C ' . escapeshellarg($repository) . ' -c user.name=t -c user.email=t@t ' . implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $status) !== false && $status === 0;
        if (!$git('init', '-q') || !$git('add', '.') || !$git('commit', '-q', '-m', 'A plugin')) {
            self::markTestSkipped('git is not here.');
        }
        $installer = new Installer($this->plugins, new ProcessRunner());
        $name = Loop::run(static fn ($cancellation): string => $installer->add('file://' . $repository, cancellation: $cancellation));
        self::assertSame('bia-hello', $name, 'Named after the repository.');
        self::assertSame(['hello' => 'Hi'], (new Plugins($this->plugins))->load()->commands());

        try {
            $missing = 'file://' . $this->root . '/repo/none.git';
            Loop::run(static fn ($cancellation): string => $installer->add($missing, cancellation: $cancellation));
            self::fail('Cloned nothing.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('git could not clone', $error->getMessage());
        }
        self::assertSame(['.', '..', 'bia-hello'], scandir($this->plugins));
    }

    public function testPluginsWrittenDuringAConversationAreReadAgainByThemselves(): void
    {
        $command = static fn (string $name): string => "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('{$name}', 'A command', fn () => null);\n    \$bia->tool('{$name}', 'A tool.', fn () => '{$name}');\n};\n";
        $plugins = (new Plugins($this->plugins))->load();
        $notes = [];
        $plugins->attach(static function (string $text) use (&$notes): void { $notes[] = $text; }, static fn () => null, static fn () => null);
        // As the conversation's tools are asked for, at each step.
        $registry = (new Registry())->from(static function () use ($plugins): array {
            $plugins->refresh();
            return $plugins->tools();
        });
        self::assertNull($plugins->changed());
        self::assertSame([], $registry->definitions());

        $this->plugin('first', $command('one'));
        self::assertSame(['names' => ['first'], 'servers' => false], $plugins->changed());
        self::assertSame('plugin__first__one', $registry->definitions()[0]->name, 'Written a step ago, its tool is there at the next.');
        self::assertSame(['Plugins reloaded · first'], $notes);
        self::assertNull($plugins->changed());
        $registry->definitions();
        self::assertCount(1, $notes, 'Nothing changed: nothing is read or said again.');

        $this->plugin('first', $command('uno'));
        $this->plugin('second', $command('two'));
        file_put_contents("{$this->plugins}/second/mcp.json", '{"mcpServers": {}}');
        self::assertSame(['names' => ['first', 'second'], 'servers' => true], $plugins->changed());
        self::assertTrue($plugins->refresh());
        self::assertSame(['uno', 'two'], array_keys($plugins->commands()));
        self::assertSame(['names' => [], 'servers' => true], $plugins->changed(), 'Its code is read; its servers are still to be started, which /reload does.');
        self::assertFalse($plugins->refresh());
        self::assertNull($plugins->load()->changed());

        (new Installer($this->plugins, new ProcessRunner()))->remove('second');
        self::assertSame(['names' => ['second'], 'servers' => true], $plugins->changed(), 'One taken away is a change too.');
        self::assertTrue($plugins->refresh());
        self::assertSame(['uno'], array_keys($plugins->commands()));
    }

    public function testWhatAPluginKeptFromALoadBeforeDoesNoHarm(): void
    {
        $this->plugin('gone', <<<'PHP'
            <?php
            return function (Bia\Plugin\Host $bia): void {
                $GLOBALS['bia-test-host'] = $bia;
                $bia->command('wait', 'Waits, then fails', function () use ($bia): void {
                    $bia->run(['sleep', '0.3']);
                    throw new RuntimeException('after it went');
                });
            };
            PHP);
        $plugins = (new Plugins($this->plugins, $this->root))->load();
        $failed = [];
        $plugins->attach(static fn () => null, static fn () => null, static function (string $plugin, string $message) use (&$failed): void { $failed[] = "{$plugin}: {$message}"; });
        $installer = new Installer($this->plugins, new ProcessRunner());

        // Its command waits on a command of its own while the plugin is taken away and the rest read again.
        Loop::run(static function () use ($plugins, $installer): void {
            $loop = \Crab\Engine\EventLoop::current();
            $command = \Crab\Engine\Async\Task::spawn($loop, static fn () => $plugins->run('wait', ''));
            $loop->delay(0.05, static function () use ($plugins, $installer): void {
                $installer->remove('gone');
                $plugins->refresh();
            });
            $command->await();
        });
        self::assertSame(['gone: after it went'], $failed, 'What it did then is said,');
        self::assertSame([], $plugins->all(), 'and nothing of it comes back.');

        try {
            $GLOBALS['bia-test-host']->command('more', 'Too late', static fn () => null);
            self::fail('Added to a load that is over.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('adds as it loads', $error->getMessage());
        } finally {
            unset($GLOBALS['bia-test-host']);
        }
        self::assertSame([], $plugins->commands());
    }

    public function testAPluginRunsCommandsThroughBiaWithoutItsCredentials(): void
    {
        $this->plugin('runner', <<<'PHP'
            <?php
            return function (Bia\Plugin\Host $bia): void {
                $bia->tool('run', 'Runs.', fn (array $arguments) => $bia->run($arguments['command'], $arguments['directory'] ?? null, $arguments['timeout'] ?? 30.0));
            };
            PHP);
        $plugins = (new Plugins($this->plugins, $this->root, new ProcessRunner(['s3cret-key'], ['PATH' => (string) getenv('PATH'), 'BIA_API_KEY' => 's3cret-key', 'KEPT' => 'yes'])))->load();
        $tool = $plugins->tools()[0];
        $context = new ToolContext($this->root, 'session');
        $run = static fn (array $arguments): array => Loop::run(static fn ($cancellation): array => (array) json_decode($tool->execute($arguments, $context, $cancellation)->text, true));

        $result = $run(['command' => ['sh', '-c', 'pwd; echo "$KEPT:$BIA_API_KEY"; echo oops >&2; exit 3']]);
        self::assertSame([false, 3, false], [$result['ok'], $result['code'], $result['timed_out']]);
        self::assertSame(realpath($this->root) . "\nyes:\n", $result['stdout'], "In the project, with the environment Bia's own commands get: no credentials.");
        self::assertSame("oops\n", $result['stderr']);
        self::assertTrue($run(['command' => ['pwd'], 'directory' => $this->plugins])['ok']);
        self::assertSame(realpath($this->plugins) . "\n", $run(['command' => ['pwd'], 'directory' => $this->plugins])['output']);

        $started = microtime(true);
        $slow = $run(['command' => ['sleep', '5'], 'timeout' => 0.2]);
        self::assertSame([false, true], [$slow['ok'], $slow['timed_out']], 'Bounded.');
        self::assertLessThan(3.0, microtime(true) - $started);

        // Stopped with what it was run for: a turn, or the command alone.
        $cancellation = new \Crab\Engine\Async\Cancellation();
        $plugins->attach(static fn () => null, static fn () => null, static fn () => null, static fn () => $cancellation);
        $started = microtime(true);
        try {
            Loop::run(static function () use ($tool, $context, $cancellation): void {
                \Crab\Engine\EventLoop::current()->delay(0.1, static fn () => $cancellation->cancel());
                $tool->execute(['command' => ['sleep', '5']], $context);
            });
            self::fail('It ran on.');
        } catch (\Crab\Engine\Async\CancelledException) {
            self::assertLessThan(3.0, microtime(true) - $started);
        }
    }

    public function testAClonedPluginIsUpdated(): void
    {
        $repository = "{$this->root}/repo/hello";
        mkdir($repository, 0777, true);
        $write = static fn (string $said) => file_put_contents("{$repository}/plugin.php", "<?php\nreturn function (Bia\\Plugin\\Host \$bia): void {\n    \$bia->command('hello', '{$said}', fn () => null);\n};\n");
        $git = static fn (string ...$command) => exec('git -C ' . escapeshellarg($repository) . ' -c user.name=t -c user.email=t@t ' . implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $status) !== false && $status === 0;
        $write('Hi');
        if (!$git('init', '-q') || !$git('add', '.') || !$git('commit', '-q', '-m', 'A plugin')) {
            self::markTestSkipped('git is not here.');
        }
        $installer = new Installer($this->plugins, new ProcessRunner());
        Loop::run(static fn ($cancellation): string => $installer->add('file://' . $repository, cancellation: $cancellation));
        mkdir("{$this->plugins}/copied");
        file_put_contents("{$this->plugins}/copied/plugin.php", "<?php\nreturn function (): void {};\n");
        self::assertSame(['hello'], $installer->cloned());

        self::assertFalse(Loop::run(static fn ($cancellation): bool => $installer->update('hello', $cancellation)), 'Nothing new.');
        $write('Hello again');
        self::assertTrue($git('commit', '-q', '-am', 'Said again'));
        self::assertTrue(Loop::run(static fn ($cancellation): bool => $installer->update('hello', $cancellation)));
        self::assertSame('Hello again', (new Plugins($this->plugins))->load()->commands()['hello']);

        try {
            Loop::run(static fn ($cancellation): bool => $installer->update('copied', $cancellation));
            self::fail('Updated what was copied.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('was not installed from a git repository', $error->getMessage());
        }
        $this->expectExceptionMessage('No plugin is named nope');
        Loop::run(static fn ($cancellation): bool => $installer->update('nope', $cancellation));
    }

    private function plugin(string $name, string $code): void
    {
        $folder = "{$this->plugins}/{$name}";
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        // Heredocs in this file are indented: PHP takes the indentation off.
        file_put_contents("{$folder}/plugin.php", $code);
    }
}
