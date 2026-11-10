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
