<?php

declare(strict_types=1);

namespace Bia\Plugin;

/**
 * What a plugin is given: the whole of Bia it can reach. Its `plugin.php`
 * returns a closure taking this, which says what the plugin adds:
 *
 *     return function (Bia\Plugin\Host $bia): void {
 *         $bia->command('standup', 'What I did today', fn (string $rest) => $bia->ask('Summarize today\'s commits.'));
 *         $bia->tool('word_count', 'Counts the words of a text.', fn (array $arguments) => str_word_count($arguments['text']), [
 *             'type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'],
 *         ]);
 *         $bia->on('tool.result', function (array $event) use ($bia): void { ... });
 *     };
 *
 * It may change from one Bia to the next: a plugin is the user's own code,
 * mended when it breaks.
 */
final readonly class Host
{
    /**
     * @param string $name The plugin's name: its folder's.
     * @param string $directory The plugin's folder, for files of its own.
     * @param string $workspace The project Bia runs in.
     */
    public function __construct(
        public string $name,
        public string $directory,
        public string $workspace,
        private Plugins $plugins,
    ) {}

    /**
     * Adds a slash command, `/name`. One of Bia's own of that name is run
     * in its place.
     *
     * @param \Closure(string): void $handler Given what follows the name, trimmed.
     */
    public function command(string $name, string $description, \Closure $handler): void
    {
        $this->plugins->register($this->name, $name, $description, $handler);
    }

    /**
     * Adds a tool the model can call, run in Bia's own process: for what is
     * small or needs this plugin's own state. A tool that could wait long
     * or fail hard belongs in the plugin's mcp.json, a process of its own.
     *
     * What the handler returns is the tool's result, a string as it is and
     * anything else as JSON; what it throws is told to the model as the
     * call's failure.
     *
     * @param \Closure(array<string, mixed>): mixed $handler Given the call's arguments.
     * @param array<string, mixed> $schema The arguments, as a JSON Schema object; none without one.
     */
    public function tool(string $name, string $description, \Closure $handler, array $schema = []): void
    {
        $this->plugins->tool($this->name, $name, $description, $handler, $schema);
    }

    /**
     * Listens to what the main agent does; a listener is told, and changes
     * nothing. It runs in Bia's own process: one that waits holds Bia.
     *
     * - `turn.start`: `text`, the user's message.
     * - `tool.call`: `tool`, `arguments`, as a call starts.
     * - `tool.result`: `tool`, `arguments`, `output`, `ok`.
     * - `turn.done`: `text`, the answer, once a message is fully answered.
     *
     * @param \Closure(array<string, mixed>): void $listener
     */
    public function on(string $event, \Closure $listener): void
    {
        $this->plugins->listen($this->name, $event, $listener);
    }

    /**
     * Runs a command and waits for it without holding Bia: the conversation
     * goes on, Esc stops it, and it sees none of Bia's credentials. Use it
     * in place of `shell_exec`, `exec` and `proc_open`, inside a command, a
     * tool or a listener, not as the plugin loads.
     *
     *     $log = $bia->run(['git', 'log', '--oneline', '-5']);
     *     if ($log['ok']) { $bia->note(strtok($log['output'], "\n")); }
     *
     * @param list<string> $command The program and its arguments, not a shell line: `['sh', '-c', '…']` for one.
     * @param string|null $directory Where it runs: the project, unless given.
     * @param float $timeout Seconds it may take, 600 at most.
     * @return array{ok: bool, code: ?int, output: string, stdout: string, stderr: string, timed_out: bool}
     */
    public function run(array $command, ?string $directory = null, float $timeout = 30.0): array
    {
        return $this->plugins->execute($command, $directory, $timeout);
    }

    /**
     * Shows a dim line in the conversation.
     */
    public function note(string $text): void
    {
        $this->plugins->say($text);
    }

    /**
     * Sends the model a message, as if the user wrote it; while Bia works,
     * it steers the turn at its next step.
     */
    public function ask(string $text): void
    {
        $this->plugins->send($text);
    }
}
