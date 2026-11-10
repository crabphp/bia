---
name: bia-plugin
description: Extends Bia itself with a plugin: a new slash command, a tool, something done on each tool call or turn, a skill or an MCP server. Use when the user asks Bia to add, change or fix something of Bia's own.
---
A plugin is a folder `<home>/plugins/<name>/`, where `<home>` is `$BIA_HOME`
or `~/.bia`: `echo "${BIA_HOME:-$HOME/.bia}/plugins"` prints it. Never put
one in a project. List that folder first: the user may mean a plugin that
is there already, to change or mend. It holds any of:

- `plugin.php`: PHP run inside Bia, for commands, tools and events.
- `skills/<skill>/SKILL.md`: skills, in the usual format.
- `mcp.json`: MCP servers, in the `mcpServers` format; `${BIA_PLUGIN}` is
  the plugin's folder, as in `"args": ["${BIA_PLUGIN}/server.php"]`.

Choose the lightest part that does it: a skill for knowing how, `mcp.json`
for a tool that is heavy, slow or not in PHP, `plugin.php` for the rest.

## plugin.php

It returns a closure, given the one object a plugin reaches Bia through:

```php
<?php

return function (Bia\Plugin\Host $bia): void {
    // /standup, with what the user wrote after it.
    $bia->command('standup', 'What I did today', function (string $rest) use ($bia): void {
        $bia->ask('Summarize my commits of today. ' . $rest);
    });

    // A tool the model can call; the schema is a JSON Schema object.
    $bia->tool('word_count', 'Counts the words of a text.', function (array $arguments): string {
        return (string) str_word_count($arguments['text']);
    }, ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]);

    // Told what the main agent does; it changes nothing.
    $bia->on('tool.result', function (array $event) use ($bia): void {
        if ($event['tool'] === 'shell' && !$event['ok']) {
            $bia->note('A command failed');
        }
    });
};
```

- `$bia->command(name, description, fn (string $rest))`: a slash command.
- `$bia->tool(name, description, fn (array $arguments), schema)`: a tool; a
  string returned is its result, anything else is sent as JSON, and an
  exception is the call's failure.
- `$bia->on(event, fn (array $event))`, the events being `turn.start`
  (`text`), `tool.call` (`tool`, `arguments`), `tool.result` (`tool`,
  `arguments`, `output`, `ok`) and `turn.done` (`text`).
- `$bia->run(['git', 'log', '--oneline', '-5'])`: runs a command without
  holding Bia, in the project unless a folder is given second, for 30
  seconds unless a timeout is given third. It returns `ok`, `code`,
  `output`, `stdout`, `stderr` and `timed_out`. It is a list of arguments,
  not a shell line: `['sh', '-c', '…']` for one. Only inside a command, a
  tool or a listener, never as the plugin loads.
- `$bia->note(text)`: a dim line in the conversation.
- `$bia->ask(text)`: sends the model a message as the user would.
- `$bia->name`, `$bia->directory` (the plugin's folder, for its own files)
  and `$bia->workspace` (the project).

Rules:

- Keep `plugin.php` to the closure: closures and anonymous classes only. A
  named class or function belongs in another file, loaded with
  `require_once __DIR__ . '/…'`; a file that declares one cannot be loaded
  again in a running Bia.
- It runs in Bia's process: never `exit`, never write to the terminal
  (`echo`, `print`), and never `shell_exec`, `exec`, `system` or
  `proc_open`, which hold Bia while they run: `$bia->run()` does not.
  Whatever else waits long (a slow request, a big file) belongs in an
  `mcp.json` server.
- An exception turns the plugin off until the next load, with a line saying why.

## After writing it

Check it before saying it is done: `php -l` on each PHP file, then
`bia plugin list`, which loads every plugin and shows what each adds, or
`failed:` and why. Mend what failed and check again.

Bia loads it by itself once it is written, and says `Plugins reloaded`:
a tool it adds can be called from your next step on, as
`plugin__<plugin>__<tool>`, so call it once to see it work. A command is
the user's to run. Say what was added and how to use it.
`/plugins` lists what each plugin adds, or why it is off. `bia plugin rm
<name>` removes one, `bia plugin add <git-url or folder>` installs one.
