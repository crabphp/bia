# Bia

```text
▙▄▄▄▄▄▟   bia 0.1.7 · provider/model
█▙███▟█   ~/code/my-project
```

Bia is a fast, resumable coding agent for the terminal, written in PHP 8.4 on
the [Crab](https://github.com/crabphp/framework) engine. It streams a model's
reply as it arrives, runs coding tools in your project, and keeps every
conversation in SQLite so you can pick it up later.

```sh
bia "find why the login test fails and fix it"
```

## Features

- **Streaming replies** over Crab's HTTP client, from OpenAI-compatible Chat
  Completions or Responses APIs: OpenAI, OpenRouter, vLLM, Ollama, LM Studio
  and others.
- **Fallback:** a provider limited or down hands the request straight to
  your next model, and gets it back after a cooldown.
- **Coding tools:** read, list, search, edit, write, patch, shell and git
  diff, run with full permission by default, like Codex or Claude Code. Edits
  are exact text replacements, the format models get right most often.
- **Project instructions:** `AGENTS.md` (or `CLAUDE.md`) files from the
  repository root down to where you are are put in the system prompt.
- **Memory:** Bia keeps a diary and what it learns about you across every
  project, and each project's own memories, as plain files; a rule you lay
  down ("always …", "never …") is written into the project's `AGENTS.md`.
- **Plugins:** ask Bia for a new slash command or tool of its own, and it
  writes a plugin and loads it into the conversation you are in; or write
  one yourself, in `~/.bia/plugins`, with skills and MCP servers to go with it.
- **Long sessions:** before a request would overflow the model's context
  window, older history is summarized automatically; `/compact` does it on
  demand.
- **Long output kept in full:** tool output past 2000 lines or 50 KB is cut
  for the model, and the full output is saved where `read_file` can page
  through it.
- **Concurrent reads:** consecutive read-only tool calls run at the same time.
- **Cancellable:** Ctrl-C (or Esc) stops the current turn, its model request and any
  running command, and returns to the prompt; a second Ctrl-C quits.
- **Clean subprocesses:** commands run in their own process group, so nothing
  a command starts in the background outlives it.
- **Resumable sessions** stored in SQLite as an append-only log, with fork,
  listing and JSONL export. Tool executions are journaled before they start and
  recorded after their result is saved, so a crash replays a saved result or
  tells the model that side effects may be uncertain instead of losing the
  boundary.
- **Automatic session names:** in an interactive conversation, the agent writes
  a short name in the background after the first meaningful exchange. Greetings
  stay untitled; once named, a session keeps its name.
- **Safe project settings:** a repository's `.agents/bia.json` is read as data,
  never run, and cannot redirect the model or its API key.
- **Scriptable:** `--json` emits every event as a JSON line.
- **Fast startup:** `bia --help` takes about 0.03 s. Bia opens no web
  application, and no database until a session is used.

## Requirements

- Linux or macOS. On Windows, run Bia in
  [WSL](https://learn.microsoft.com/windows/wsl/install).
- The prebuilt binary includes PHP 8.4. A source checkout needs PHP 8.4 with
  `pdo_sqlite` and `mbstring`; `pcntl` and `posix` are recommended (Ctrl-C
  handling and process groups), and `readline` gives line editing and history.
- `git` for `apply_patch` and `git_diff`.
- [ripgrep](https://github.com/BurntSushi/ripgrep) (`rg`) for `search`;
  `list_files` falls back to PHP without it.
- `setsid` (standard on Linux) or perl (standard on macOS) for process
  groups. Without either Bia still works, but a background child of a
  command may outlive it.
- An API key for an OpenAI-compatible endpoint.

`bia doctor` checks all of these.

## Install

### Recommended: prebuilt binary

No PHP or Composer is needed:

```sh
curl -fsSL https://raw.githubusercontent.com/crabphp/bia/main/install.sh | sh
```

The installer supports Linux and macOS on Intel or ARM. It installs the latest
release to `~/.local/bin` (or `$BIA_INSTALL_DIR`) and checks it against the
release's `SHA256SUMS`. If it says that directory is not on your `PATH`, run
this once in the current shell and add the same line to your shell's startup
file:

```sh
export PATH="$HOME/.local/bin:$PATH"
```

Check the installation, then follow [Quick start](#quick-start):

```sh
bia doctor
```

### Updating

For an installed binary, `bia update` downloads the latest release, checks its
`SHA256SUMS`, tests it once, and then replaces the old file. Bia can also check
GitHub in the background once a day and show the update command when a release
is available; set `update.check => false` to turn that off. A source checkout
is updated with `git pull` and `composer install` instead.

### From source with Composer

This option needs Git, Composer and PHP 8.4. Composer installs Crab from
Packagist at the version `composer.lock` pins.

```sh
git clone https://github.com/crabphp/bia.git
cd bia && composer install

# Put it on your PATH
mkdir -p ~/.local/bin
ln -sfn "$PWD/bin/bia" ~/.local/bin/bia
export PATH="$HOME/.local/bin:$PATH"

bia doctor
```

## Quick start

Write your global config, `~/.bia/config.php`:

```sh
bia init --global
```

Then set your provider's key and the model in it:

```php
'providers' => [
    'openai' => ['api_key' => 'sk-...'],   // or env('OPENAI_API_KEY')
],
'model' => ['name' => 'openai/gpt-5'],
```

Any other OpenAI-compatible server is a provider of your own:

```php
'providers' => [
    'openrouter' => [
        'base_url' => 'https://openrouter.ai/api/v1',
        'api_key' => 'sk-or-...',
    ],
],
'model' => ['name' => 'openrouter/<provider/model-name>'],
```

Then, in your project:

```sh
cd ~/code/my-project
bia "explain the structure of this repository"
```

See [Configuration](#configuration) for every setting.

## Usage

```text
bia [options] [prompt]              One prompt, or a conversation when run without one
bia ask [options] <prompt>          The same, explicitly
bia resume [session-id] [prompt]    Continue a session; without an id, pick one
bia fork <session-id>               Start a new session from another's latest point
bia sessions [--json]               List recent sessions
bia export <session-id>             Write a session as JSONL
bia config [--json]                 Show the merged configuration, secrets redacted
bia doctor                          Check PHP, tools, the model and MCP servers
bia init                            Create .agents/bia.json in this directory
bia init --global                   Create ~/.bia/config.php with every setting
bia update                          Install the latest release
```

Interactive sessions are named by a small background request to the configured
model, counted in `/usage`, without adding messages to the conversation or
blocking input. If there is no clear topic, or naming fails, Bia tries after the
next completed turn. Existing sessions and forks get their own name when next
answered interactively. One-shot and piped runs do not wait for naming; until
named, `bia sessions` and `/resume` show an untitled label rather than the first
chat message.

| Option | Effect |
|---|---|
| `--model NAME` | Model for this run |
| `--base-url URL` | OpenAI-compatible API base URL |
| `--api-key KEY` | API key for this run |
| `--effort LEVEL` | Reasoning effort for this run: `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`, or `default` to send none |
| `--resume ID` | Continue a session (same as `bia resume ID`) |
| `--yes` | Approve tools whose permission is `ask` |
| `--json` | Emit JSONL events on standard output |
| `--version`, `--help` | |

### A conversation

Run `bia` with no prompt in a terminal. Finished output scrolls as usual;
the bottom of the screen is live:

```text
› fix the failing test

• Explored
  └ Read CalcTest.php, Calc.php
• Edited src/Calc.php (+1 −1)
  └   12 - return $a - $b;
      12 + return $a + $b;
• Ran composer test
  └ OK (12 tests, 30 assertions)

Fixed: add() subtracted.
▀▀▀) Thinking… 4s · esc to stop
  ↳ now update the changelog
────────────────────────────────────────────────────────────────
› Ask anything · / commands · @ files
────────────────────────────────────────────────────────────────
~/code/my-app (main)                   openai/gpt-5 · 9k/1050k (1%)
```

The status line shows the workspace and its git branch, the model, and how
much of the context window the conversation uses. Each turn is saved as it
completes.

| Command | Effect |
|---|---|
| `/model` | Pick a model from every provider you have a key for, fuzzy-filtered as you type |
| `/model provider/name` | Switch straight to that model; the session goes on. While Bia works, `/model` and `/effort` take effect from the next message |
| `/effort [level]` | How hard a reasoning model thinks: `none` to `max`, or `default` to leave it to the provider. Kept across `/model`; a model that refuses it is sent none, with a note. A summary for compaction asks for at most `low` |
| `/resume` | Pick one of this folder's sessions, by its first prompt, and continue it |
| `/resume <id>` | Continue that session; the start of its id is enough |
| `/new`, `/clear` | Start a new session |
| `/compact` | Summarize the conversation so far now |
| `/memory` | What Bia keeps between sessions, and where: the facts about you, the diary's days, this project's memories, and the file its rules go in |
| `/reload` | Read the config, instructions (`AGENTS.md`), plugins and MCP servers again, on the same session, model and effort, without restarting. Background commands go on. A config with an error leaves everything as it was. For new Bia code, quit and `bia resume` |
| `/stats` | Show or hide live stats at the top right: drawing (fps, ms a frame), first token and tokens a second, tokens in and out, memory |
| `/render [normal\|smooth\|power]` | How answers appear: `normal` as the text arrives; `smooth` evened out to a steady flow of characters; `power` each character dropping into place and landing with a flash (fullscreen). `tui.render` in your config sets the default |
| `/help` | List commands and keys |
| `/exit`, `/quit` | Leave |

Typing `/` opens a menu of commands, and `@` one of the workspace's files
(those `.gitignore` does not leave out), fuzzy-matched; Tab or Enter takes
the highlighted one.

| Key | Effect |
|---|---|
| Enter | Send; while Bia works, steer it: the message reaches Bia at its next step |
| Ctrl-J, Alt-Enter, or `\` then Enter | New line; a paste keeps its lines |
| Up / Down | Move between lines, then through earlier prompts (kept across runs) |
| Esc | Stop the turn, or close a menu |
| Ctrl-C | Clear the input, even while Bia works; on an empty input, stop the turn, or twice to leave |
| Ctrl-D | Leave, on an empty input |
| Ctrl-W, Ctrl-U, Ctrl-K | Delete a word, to the line start, to the line end |
| Ctrl-A / Ctrl-E, Ctrl-Left / Ctrl-Right | Line start and end, word moves |
| Ctrl-L | Clear the screen |

A tool whose permission is `ask` asks in the same place, Yes or No (`y` and
`n` answer too). A failed turn, such as a rate limit or a dropped connection,
prints the error and keeps the conversation open.

There is no limit on the number of model/tool steps in a turn. Bia keeps
working until the model finishes, you stop it, or an error ends the turn;
request timeouts and repeated-call warnings still apply.

A session begins with its first message, so opening Bia and leaving creates
nothing. When you leave one that has messages, Bia says how to come back:

```text
Resume this session with: bia resume ede8706ee073e1d00698e128
```

Resuming shows the end of the conversation as it was, then goes on.

Without a terminal, or with `TERM=dumb`, Bia falls back to a plain `>`
prompt.

### Images

Show the model a screenshot: **Ctrl-V** takes the image on the clipboard,
and pasting or dropping an image file (PNG, JPEG, GIF or WebP) on the
terminal attaches the image rather than its path. Each is an `[Image #1]` in
the input; delete it and the image goes too. The model needs to read images.

A terminal passes only text, so Ctrl-V reads the clipboard of the machine
Bia runs on, with `wl-paste`, `xclip` or macOS's own. Over SSH there is none:
paste the path of an image on that machine instead. Images are kept under
`~/.bia/images`, and the session refers to them there.

### What you see

Answer text streams to standard output. Each tool call is one line on standard
error, followed by the last lines of its output when it is a shell command or
it failed:

```text
✓ search TODO
✓ read_file src/App.php
✗ shell composer test
  FAILURES!
  Tests: 41, Assertions: 97, Failures: 1.
```

In a conversation, tool calls are shown as Codex and Claude Code show them:
a row each, in plain words (`• Ran composer test`, `• Edited src/App.php
(+6 −2)`), with what came of it under it: a command's last lines, a
failure's reason, an edit's diff with line numbers, and `… +N lines` for the
rest. Reads, listings and searches in a row are one `• Explored` block. A
command's output streams under it while it runs. The dot is green when a
call worked and red when it failed. Click a row to open it in full, and click
again to close it; Ctrl-O opens or closes them all.

### Scripting

```sh
bia --json "list the public API of src/" | jq -c 'select(.type == "tool_result")'
```

Event types:

| Type | Meaning |
|---|---|
| `session` | The session id |
| `text_delta` | Answer text as it streams |
| `tool_call` | A tool call the model made |
| `reasoning_delta` | Reasoning text, from models that stream it |
| `tool_result` | A tool's result: `ok`, `text` (first 4 KB), `bytes` |
| `notice` | Something Bia did on its own, such as compacting |
| `usage` | Token usage |
| `completed` | One model turn finished |
| `result` | The final answer |

Without a terminal on standard input, Bia cannot ask questions, so a tool
whose permission is `ask` is declined unless `--yes` is given.

## Tools

| Tool | Permission | What it does |
|---|---|---|
| `read_file` | read | Reads a text file, at most 2000 lines / 50 KB per call, paged with `offset` and `limit`; refuses binary files |
| `list_files` | read | Lists files with ripgrep, skipping `.git` and `vendor` |
| `search` | read | Searches with a ripgrep regular expression |
| `git_diff` | read | Shows the unstaged diff |
| `edit` | write | Replaces exact text in an existing file; several edits per call, each matched once unless `replace_all`; tolerates typographic quotes, dashes and trailing spaces; keeps line endings and mode |
| `write_file` | write | Creates a file or replaces a whole one, atomically, keeping an existing file's mode |
| `apply_patch` | write | Checks, then applies, a unified diff with `git apply` |
| `shell` | shell | Runs `/bin/sh -c` in the workspace, with a timeout (default 120 s) |
| `memory` | write | Keeps and recalls what is worth knowing later: a diary entry, a fact about you, a project memory, a rule for the project's `AGENTS.md`; see [Memory](#memory) |

Every path is resolved inside the workspace, the directory Bia was started
in; `..` and absolute paths are refused. Commands get your environment minus
`BIA_*` and any variable holding the model's API key, so `gh`, `npm` and
cloud CLIs keep working while the key stays out of the model's sight. Tool
output past 2000 lines or 50 KB is cut to its first and last lines for the
model; the full output is saved under `~/.bia/cache/outputs/` and the note
tells the model where, so `read_file` can page through it.

## MCP servers

MCP servers give Bia more tools: GitHub, databases, browsers, your own. List
them in `~/.bia/mcp.json`, in the `mcpServers` format other agents use,
so a config written for one works in the other:

```json
{
  "mcpServers": {
    "github": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-github"],
      "env": { "GITHUB_PERSONAL_ACCESS_TOKEN": "${GITHUB_TOKEN}" }
    },
    "docs": { "type": "http", "url": "https://example.com/mcp", "headers": { "Authorization": "Bearer ${DOCS_KEY}" } }
  }
}
```

- A server is started here and talked to over its input and output
  (`command`, `args`, `env`), or reached over streamable HTTP (`url`,
  `headers`). `${VAR}` and `${VAR:-default}` are taken from your environment.
- Servers connect in the background once the screen is up: none slows Bia's
  start, and their tools join as each is ready, at the next step of a turn.
  `bia "…"` waits for them, briefly, before its one request.
- Their tools are named `mcp__<server>__<tool>`, shown as `github · search`,
  and need the `mcp` permission (`auto` by default). A call may take
  `mcp.timeout` seconds (120); Esc cancels it on the server too.
- A project's own `.mcp.json` starts programs from the repository: Bia asks
  once before starting its servers, and again if the file changes.
- `/mcp` shows each server: connected and its tools, connecting, needing a
  login, or failed and why.
- Every server Bia started stops with it.

### Logging in to remote servers

Many hosted servers (GitHub, Linear, Notion, Sentry…) need you to log in.
Bia does it with OAuth, as MCP specifies: `/mcp` marks such a server
`needs login`, and `/mcp login <server>` opens your browser. You log in
there, the browser comes back to Bia on `127.0.0.1`, and the server
connects. Over SSH, open the address Bia shows wherever you have a browser,
and paste the address it sends you back to into Bia.

- Bia finds the server's authorization server, registers itself with it
  when it may, and logs in with PKCE; the token is bound to that server
  alone, and kept in `~/.bia/mcp-auth.json`, readable by you
  only. It is renewed before it runs out; when it cannot be, the server
  needs a login again.
- `/mcp logout <server>` forgets the login.
- A server whose authorization server does not let Bia register itself
  takes a client of your own, and may want scopes or a fixed callback port:

  ```json
  "tracker": {
    "type": "http",
    "url": "https://tracker.example.com/mcp",
    "oauth": { "clientId": "abc123", "clientSecret": "${TRACKER_SECRET}", "scopes": ["read"], "callbackPort": 33418 }
  }
  ```

- An `Authorization` header in the server's config is sent as it is, and no
  login is asked for.

## Skills

A skill is a folder with a `SKILL.md`: instructions for a kind of task, and
files that go with them, in the format other agents share:

```markdown
---
name: release-notes
description: Writes release notes from the git log since the last tag.
---
Run `git log` since the last tag, group the changes, and follow template.md.
```

- Bia reads your skills from `~/.agents/skills/` and a project's from its
  `.agents/skills/`; a project's takes the place of yours of the same name.
- Only each skill's name and description are in front of the model. When a
  task matches one, it loads that skill's instructions, and reads its other
  files as they say, from the skill's folder and nowhere else.
- `/release-notes` runs a skill by its name, with anything you write after
  it; skills show in the `/` menu with the commands.
- The folders are read again at each step, so a skill added during a
  session can be used at the next one.
- Bia has one skill of its own, `bia-plugin`, on writing a plugin, and
  reads each installed plugin's skills too; yours and a project's take the
  place of those of the same name.

## Plugins

A plugin extends Bia itself: a slash command, a tool, something done on
each tool call or turn, with skills and MCP servers to go with it. Ask Bia
for one ("add a /standup command that summarizes today's commits"): it
writes the plugin, and Bia loads it into the conversation you are in as
soon as it is written, with a `Plugins reloaded` line.

A plugin is a folder in `~/.bia/plugins/` (or `$BIA_HOME/plugins/`), and
nowhere else: a project never brings one, and nothing installs one but you.
It holds any of:

```
~/.bia/plugins/standup/
  plugin.php     code run inside Bia
  skills/        skills, each a folder with a SKILL.md
  mcp.json       MCP servers, as in ~/.bia/mcp.json
```

`plugin.php` returns a closure, given the one object a plugin reaches Bia
through:

```php
<?php

return function (Bia\Plugin\Host $bia): void {
    $bia->command('standup', 'What I did today', function (string $rest) use ($bia): void {
        $bia->ask('Summarize my commits of today. ' . $rest);
    });

    $bia->tool('word_count', 'Counts the words of a text.', function (array $arguments): string {
        return (string) str_word_count($arguments['text']);
    }, ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]);

    $bia->on('tool.result', function (array $event) use ($bia): void {
        if ($event['tool'] === 'shell' && !$event['ok']) {
            $bia->note('A command failed');
        }
    });
};
```

| Call | What it adds |
| --- | --- |
| `command(name, description, fn (string $rest))` | A slash command, in the `/` menu with Bia's own; one of Bia's of the same name comes first |
| `tool(name, description, fn (array $arguments), schema)` | A tool the model can call, run inside Bia. A string returned is its result, anything else is sent as JSON, an exception fails the call |
| `on(event, fn (array $event))` | A listener, told and changing nothing: `turn.start` (`text`), `tool.call` (`tool`, `arguments`), `tool.result` (`tool`, `arguments`, `output`, `ok`), `turn.done` (`text`) |
| `run(command, directory, timeout)` | Runs a command, a list of arguments, without holding Bia: in the project and for 30 seconds unless told otherwise. Gives `ok`, `code`, `output`, `stdout`, `stderr`, `timed_out`. Esc stops it, and it sees none of Bia's credentials |
| `note(text)` | A dim line in the conversation |
| `ask(text)` | A message to the model, as if you wrote it |

`$bia->name`, `$bia->directory` (the plugin's folder) and `$bia->workspace`
(the project) say where it is.

- `bia plugin add <git-url or folder> [name]` installs one and says what it
  adds, `bia plugin rm <name>` removes one, `bia plugin list` and `/plugins`
  list them. `bia plugin update [name]` brings one installed from git, or
  all of them, up to date.
- A plugin written or changed while Bia runs, by Bia or by you, is loaded
  without `/reload`: at the next step of a turn, so Bia can call a tool it
  has just written, at a turn's end, and when you run a command Bia does
  not know yet. A changed `mcp.json` reads everything again, as `/reload`.
- A command runs beside the conversation: the input stays yours, and once
  it takes a second the status line says `Running /standup · Esc to stop`.
- `plugin.php` is loaded for a conversation only, not for `bia "prompt"`,
  `--help` or `doctor`; a plugin's skills and MCP servers work everywhere
  yours do. In `mcp.json`, `${BIA_PLUGIN}` is the plugin's folder.
- A plugin that throws is turned off, with a line saying why, until
  `/reload` or the next Bia; the conversation goes on. A tool that throws
  only fails its call.
- It runs inside Bia: run commands with `$bia->run()`, never `shell_exec`
  or `exec`, which hold Bia while they run. Whatever else waits long
  belongs in the plugin's `mcp.json`, a process of its own. Subagents get
  none of it.
- An edited `plugin.php` is loaded again unless it declares a named class
  or function, which PHP cannot load twice: keep it to the closure, or
  start a new `bia`.
- What `Host` offers may change between versions: a plugin is your own
  code, and Bia can mend it.

## Configuration

Later sources override earlier ones:

1. Built-in defaults
2. `~/.bia/config.php`: yours, trusted, any setting
3. `.agents/bia.json` in the project: limited to the keys below
4. Environment variables
5. Command-line options

### Your config

`~/.bia/config.php` (or `$BIA_HOME/config.php`) returns an
array. `bia init --global` writes one listing every setting at its default,
commented out, so you uncomment what you change; it fills a missing or blank
file and leaves one with content alone. A minimal one:

```php
<?php

return [
    'model' => [
        'base_url' => 'https://api.openai.com/v1',
        'name' => 'gpt-5',
        'api_key' => env('OPENAI_API_KEY'), // or paste it in
        'timeout' => 3600.0,        // longest a whole reply may take
        'idle_timeout' => 120.0,    // give up on a server silent this long
        'context_window' => 128000, // tokens; set it to your model's
    ],
    'prompt' => [
        'system' => 'You are Bia, a fast coding assistant. Be precise and concise.',
    ],
    'permissions' => [
        'read' => 'auto',           // auto | ask | deny
        'write' => 'auto',
        'shell' => 'auto',
    ],
    'tools' => [
        'shell_timeout' => 120.0,   // default seconds for a shell command
        'output_bytes' => 51200,    // tool output sent per call, in bytes
        'output_lines' => 2000,     // and in lines
    ],
    'compaction' => [
        'enabled' => true,
        'reserve_tokens' => 16384,  // room left for the reply
        'keep_tokens' => 20000,     // recent history kept word for word
        'at_tokens' => 400000,      // compact past this too, in a larger window; null waits for the window
        'idle_seconds' => 3600,     // rested this long, the prompt cache is gone: compact first...
        'idle_tokens' => 64000,     // ...when over this; idle_seconds null never does
    ],
    'storage' => [
        'path' => '~/.bia/bia.sqlite',
    ],
];
```


What you pick in a conversation is kept for the next ones: `/model`,
`/effort` and `/render` set `model.name`, `model.reasoning_effort` and
`tui.render` in this file. Only that value
changes; your comments, `env()` calls and layout stay as you wrote them.

### Providers

A provider is a model server set up once: its URL, where its key is, and its
models' context window. Pick any of its models as `provider/model`, with
`--model`, `BIA_MODEL` or `model.name`; there is no list of models to keep.

Three are built in:

| Provider | Server | Key from | Context window |
|---|---|---|---|
| `openai` | `https://api.openai.com/v1` | `OPENAI_API_KEY` | 1,050,000 |
| `anthropic` | `https://api.anthropic.com/v1` (OpenAI-compatible endpoint) | `ANTHROPIC_API_KEY` | 1,000,000 |
| `cliproxyapi` | `http://127.0.0.1:8317/v1` ([CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI)) | `CLIPROXYAPI_API_KEY` | 1,000,000 |

```sh
export CLIPROXYAPI_API_KEY=...     # one of the api-keys in CLIProxyAPI's config.yaml
bia --model cliproxyapi/claude-sonnet-4-6
bia --model anthropic/claude-sonnet-4-5
export BIA_MODEL=openai/gpt-5      # the default from now on
```

Change one, or add your own, under `providers` in your config. Give the key
as `api_key`, pasted in or read with `env()`; without one, Bia reads
`<NAME>_API_KEY`. Only a model that differs from its provider needs an entry
under `models`:

```php
'providers' => [
    'cliproxyapi' => ['api_key' => 'sk-...'],
    'myproxy' => [
        'base_url' => 'https://llm.example.com/v1',
        'wire_api' => 'responses',
        'endpoint' => 'responses',
        'api_key' => env('MYPROXY_API_KEY'),
        'context_window' => 272000,
        'models' => ['small-model' => ['context_window' => 128000]],
    ],
],
'model' => ['name' => 'myproxy/large-model'],
```

A provider takes any `model` setting (`base_url`, `api_key`, `endpoint`,
`wire_api` (`chat` or `responses`), `timeout`, `idle_timeout`,
`context_window`). Set `wire_api` and `endpoint` to `responses` for a
Responses provider. `--base-url`, `--api-key`,
`BIA_BASE_URL` and `BIA_API_KEY` still override it. A prefix that
names no provider stays part of the model name, so `meta/llama-4` on
OpenRouter works as before. A project's `.agents/bia.json` may pick a
provider's model but never define or change a provider.

### Fallback

When the chosen model's provider is rate limited, down, or refuses its key,
the same request goes at once to the next model in `fallback.models`, and one
line says so (`anthropic/claude-opus-4-5: 429 · → openai/gpt-5`). A failed
model rests for `fallback.cooldown` seconds, then is asked first again; if it
fails again, the working fallback is kept. A model is switched only before
any of its answer has arrived, so nothing is shown twice, and never for a
request that would be wrong anywhere (a `400`, a context too long).

```php
'fallback' => [
    'models' => ['anthropic/claude-sonnet-4-5', 'openai/gpt-5'],
    'cooldown' => 300.0,
],
```

A model without a key is left out; `bia doctor` lists each one. Only your own
config sets fallbacks, never a project's.

### Project config

`.agents/bia.json` is found in the current directory or the nearest parent
with one or a `composer.json`, never your home directory. It ships with the repository,
so it is read as data and may set only `model.name`, `model.context_window`,
`prompt.system`, `permissions`, `tools` and `compaction`:

```json
{
    "model": { "name": "gpt-5-mini" },
    "prompt": { "system": "You work on a Laravel app. Run tests with composer test." },
    "permissions": { "shell": "ask" }
}
```

A project file that sets anything else, such as `model.base_url`, is refused
with the offending keys named. `bia init` creates one.

### Environment

| Variable | Sets |
|---|---|
| `BIA_API_KEY` | `model.api_key` |
| `OPENAI_API_KEY` | `model.api_key` for the default provider |
| `<PROVIDER>_API_KEY` | `model.api_key` for that provider, when its `api_key` is not set |
| `BIA_BASE_URL` | `model.base_url` |
| `BIA_MODEL` | `model.name`, e.g. `cliproxyapi/claude-sonnet-4-6` |
| `BIA_PROVIDER` | `model.provider` |
| `BIA_WIRE_API` | `model.wire_api`, `chat` or `responses` |
| `BIA_HOME` | Where Bia keeps its files, `~/.bia` by default |
| `NO_COLOR` | Plain tool lines |

### Permissions

Bia runs with full permission by default: every tool runs without asking.
Set a permission to `ask` to be asked first (`Allow shell ({"command":"..."})?
[y/N]`), or to `deny` to refuse it outright. `--yes` answers `ask` with yes;
`deny` always wins.

## Project instructions

Bia puts instruction files in its system prompt, general first:

1. `~/.bia/AGENTS.md`: yours, for every project.
2. From the repository root (the nearest parent with `.git`) down to the
   working directory, one file per directory: `AGENTS.md`, or `CLAUDE.md`
   where there is none.

Together they are capped at 32 KB. `bia doctor` lists the files that apply.
The prompt also says where Bia runs (directory, platform, date, git branch)
and how to use its tools. Your `prompt.system` leads it.

## Memory

Bia keeps what is worth knowing in a later session, as it comes up and
without asking. All of it is plain text under `~/.bia` that you can read,
edit or delete; nothing of it is in your repository.

- **A diary**, shared by every project: `~/.bia/diary/2026-11-10.md`, a page
  a day, one line an entry with the time and the project. Entries are only
  ever added.
- **About you**: `~/.bia/diary/ABOUT.md`, the standing facts drawn from the
  diary, one line each: how you like answers, what you prefer. A fact is
  replaced in place when it changes.
- **A project's memories**: `~/.bia/projects/<project>/memory/`, a file a
  memory and a `MEMORY.md` index of them: decisions and why, constraints,
  where things outside the code live. One repository has one memory, shared
  by its folders and worktrees. A memory no longer true or needed is
  replaced or forgotten.
- **Rules**: say "always run the tests before a commit", "never push to
  main" or "you must …" and Bia writes the rule into the project's
  `AGENTS.md` (or its `CLAUDE.md`), under `## Rules`. It is then an
  instruction, read in every session like the rest of that file, and yours
  to commit. Bia is told to do so beside any message with such a word in it;
  it leaves alone what only concerns the task at hand.

Each session starts with `ABOUT.md`, the diary's last two pages and the
project's index in the prompt, a few KB at most. A memory's text and older
pages are read, or searched, when they are needed. Subagents get none of it.
What is kept is sent to the model you use, as the conversation is: Bia is
told never to keep a secret or a key.

`/memory` lists what is kept and where. Set `'memory' => ['enabled' => false]`
in your config to turn it all off: no tool, nothing in the prompt.

## Sessions and compaction

Sessions live in `~/.bia/bia.sqlite` as an append-only log: each
entry (a message, a tool result, a compaction) points at the one before it,
and a session is the path from its newest entry back. Bia stores completed
messages and tool results, not each streamed token. A separate execution
journal writes a tool's start before it runs and marks it recorded only after
the result joins the session; resuming repairs a saved result and marks an
interrupted call uncertain. Messages are kept in no provider's format, so the
same session works with any adapter.

```sh
bia sessions                           # id, last used, entries, model, first prompt
bia resume                             # pick one of this folder's sessions
bia resume 2e18 "now add a test for it"  # the start of an id is enough
bia fork 2e18                          # a new session from that point; the original is untouched
bia export 2e18 > session.jsonl
```

Before a request would take more than `context_window - reserve_tokens`, or
`at_tokens` (400,000) in a larger window, as answers get worse in a very long
context, Bia asks the model to summarize everything except the last `keep_tokens` of
the conversation, never splitting a tool call from its result. The summary
replaces that part in what is sent; the summarized entries stay in the log.
A later compaction folds the previous summary in. If a provider still
reports the context as too long, Bia compacts and retries the turn once.
Set `model.context_window` to your model's window, or a provider's
`context_window` to its models'.

A prompt cache lasts minutes to an hour after the last request. Once it is
gone, the next request pays full price for the whole conversation, so that is
when compacting costs about nothing extra: a prompt sent more than
`idle_seconds` (3600) after the last reply compacts a conversation over
`idle_tokens` (64,000) first. Set `idle_seconds` to your provider's cache
lifetime: compacting while the cache is still warm costs more than it saves.

## Development

```sh
composer test          # PHPUnit
php bin/bia --help     # must work without an API key or a writable project
php bin/bia doctor
.build/build.sh        # dist/bia.phar and dist/bia, PHP inside, as a release builds them
```

A release is a tag: set `Application::VERSION`, give the CHANGELOG a
`## 1.2.3 (date)` section, and push `v1.2.3`. The release workflow tests
it, builds it for each platform and publishes it with that section as its
notes; a version with a `-` (`1.2.3-beta.1`) is a pre-release.

Layout:

```text
bin/bia                  Entry point; no web App is booted
src/Bia/Cli              Commands and option parsing
src/Bia/Agent            The model/tool turn loop
src/Bia/Contracts        Provider-neutral messages, events, tools, renderer, store
src/Bia/Model            Model adapters (OpenAI-compatible streaming)
src/Bia/Tool             Workspace, tools and ProcessRunner
src/Bia/Session          SQLite session store
src/Bia/Terminal         Renderer, prompt, and the cancellable loop
src/Bia/Config           Layered configuration
tests/                   PHPUnit tests
```

From Crab, Bia uses:
- the event loop, `Task` and `Cancellation`;
- the HTTP client's `stream()` with `idle:` and server-sent events;
- `Engine\Process\Subprocess` for every command.

It boots no web application. Read [AGENTS.md](AGENTS.md) before changing
code and [ARCHITECTURE.md](ARCHITECTURE.md) for the design; startup
measurements are in [BENCHMARKS.md](BENCHMARKS.md).

A new tool implements `Bia\Contracts\Tool`:
- Return a `ToolDefinition` with a JSON Schema and a permission (`read`,
  `write` or `shell`).
- Do the work in `execute()`, which returns a `ToolResult`.
- Run any subprocess through `ProcessRunner`.

Then register it in `Cli\Application`. A new provider implements
`Bia\Contracts\Model` and emits `ModelEvent`s; the agent loop never names a
provider.

## Status and limits

Bia is at 0.1.7. Not yet built:
- **Only OpenAI-compatible wire formats.** Native Anthropic Messages and
  Gemini adapters are planned.
- **No native Windows.** Bia runs in WSL there.
- **No sandbox.** The agent has your permissions in the workspace and beyond
  it through `shell`.
- **Commands have no terminal.** They run without a controlling terminal, so
  a command that needs one (an interactive prompt, `sudo` asking for a
  password) fails rather than waits.
