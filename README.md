# Bia

```text
▙▄▄▄▄▄▟   bia 0.1.0-beta.2 · provider/model
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

- **Streaming replies** over Crab's HTTP client, from any OpenAI-compatible
  Chat Completions API: OpenAI, OpenRouter, vLLM, Ollama, LM Studio, tdaproxy
  and others.
- **Fallback:** a provider limited or down hands the request straight to
  your next model, and gets it back after a cooldown.
- **Coding tools:** read, list, search, edit, write, patch, shell and git
  diff, run with full permission by default, like Codex or Claude Code. Edits
  are exact text replacements, the format models get right most often.
- **Project instructions:** `AGENTS.md` (or `CLAUDE.md`) files from the
  repository root down to where you are are put in the system prompt.
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
  listing and JSONL export. A session cut off mid-turn by a crash is repaired
  when resumed.
- **Safe project settings:** a repository's `.agents/bia.json` is read as data,
  never run, and cannot redirect the model or its API key.
- **Scriptable:** `--json` emits every event as a JSON line.
- **Fast startup:** `bia --help` takes about 0.03 s. Bia opens no web
  application, and no database until a session is used.

## Requirements

- PHP 8.4 with `pdo_sqlite` and `mbstring`; `pcntl` and `posix` are
  recommended (Ctrl-C handling and process groups), and `readline` gives line
  editing and history.
- `git` for `apply_patch` and `git_diff`.
- [ripgrep](https://github.com/BurntSushi/ripgrep) (`rg`) for `search`;
  `list_files` falls back to PHP without it.
- `setsid` (util-linux, standard on Linux) for process groups. Without it
  Bia still works, but a background child of a command may outlive it.
- An API key for an OpenAI-compatible endpoint.

`bia doctor` checks all of these.

## Install

Bia is not on Packagist yet. It installs from a checkout that sits beside the
framework, which its `composer.json` loads by path.

```sh
mkdir crabphp && cd crabphp
git clone git@github.com:crabphp/framework.git
git clone git@github.com:crabphp/bia.git
cd framework && composer install && cd ..
cd bia && composer install

# Put it on your PATH
ln -s "$PWD/bin/bia" ~/.local/bin/bia

bia doctor
```

## Quick start

```sh
export OPENAI_API_KEY=sk-...
cd ~/code/my-project
bia "explain the structure of this repository"
```

Through tdaproxy (tested with `gpt-6-astra` and `gpt-6-sol`, which have a
272,000-token window):

```sh
export BIA_PROVIDER=tdaproxy
export TDAPROXY_API_KEY=...
export BIA_BASE_URL=<tdaproxy base URL>/v1
export BIA_MODEL=gpt-6-astra
bia
```

and in `~/.config/bia/config.php`, `'model' => ['context_window' => 272000]`.

Any other OpenAI-compatible server:

```sh
export BIA_BASE_URL=https://openrouter.ai/api/v1
export BIA_API_KEY=sk-or-...
export BIA_MODEL=<provider/model-name>
bia
```

## Usage

```text
bia [options] [prompt]              One prompt, or a conversation when run without one
bia ask [options] <prompt>          The same, explicitly
bia resume [session-id] [prompt]    Continue a session; without an id, pick one
bia fork <session-id>               Start a new session from another's latest point
bia sessions [--json]               List recent sessions
bia export <session-id>             Write a session as JSONL
bia config [--json]                 Show the merged configuration, secrets redacted
bia doctor                          Check PHP, tools and configuration
bia init                            Create .agents/bia.json in this directory
bia init --global                   Create ~/.config/bia/config.php with every setting
```

| Option | Effect |
|---|---|
| `--model NAME` | Model for this run |
| `--base-url URL` | OpenAI-compatible API base URL |
| `--api-key KEY` | API key for this run |
| `--resume ID` | Continue a session (same as `bia resume ID`) |
| `--yes` | Approve tools whose permission is `ask` |
| `--json` | Emit JSONL events on standard output |
| `--version`, `--help` | |

### A conversation

Run `bia` with no prompt in a terminal. Finished output scrolls as usual;
the bottom of the screen is live:

```text
› fix the failing test

✓ read_file tests/CalcTest.php
✓ edit src/Calc.php
✓ shell composer test
  OK (12 tests, 30 assertions)
Fixed: add() subtracted.
▀▀▀) Thinking… 4s · esc to stop
  ↳ now update the changelog
────────────────────────────────────────────────────────────────
› Ask anything · / commands · @ files
────────────────────────────────────────────────────────────────
~/code/my-app (main)                 tdaproxy/gpt-6-astra · 9k/272k (3%)
```

The status line shows the workspace and its git branch, the model, and how
much of the context window the conversation uses. Each turn is saved as it
completes.

| Command | Effect |
|---|---|
| `/model` | Pick a model from every provider you have a key for, fuzzy-filtered as you type |
| `/model provider/name` | Switch straight to that model; the session goes on |
| `/resume` | Pick one of this folder's sessions, by its first prompt, and continue it |
| `/resume <id>` | Continue that session; the start of its id is enough |
| `/new` | Start a new session |
| `/compact` | Summarize the conversation so far now |
| `/clear` | Clear the screen |
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

A session begins with its first message, so opening Bia and leaving creates
nothing. When you leave one that has messages, Bia says how to come back:

```text
Resume this session with: bia resume ede8706ee073e1d00698e128
```

Resuming shows the end of the conversation as it was, then goes on.

Without a terminal, or with `TERM=dumb`, Bia falls back to a plain `>`
prompt.

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

In a conversation, a command's output streams below it while it runs. Once
done, the calls that worked in a row fold into one line (`✓ Ran 3 commands,
read 7 files`), so the answer stays in view; a call that failed shows why, and
an edit the first lines of its diff. Click a line to open it to every call's
whole output, or the whole diff, and click again to close it; Ctrl-O opens or
closes them all.

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

Every path is resolved inside the workspace, the directory Bia was started
in; `..` and absolute paths are refused. Commands get your environment minus
`BIA_*` and any variable holding the model's API key, so `gh`, `npm` and
cloud CLIs keep working while the key stays out of the model's sight. Tool
output past 2000 lines or 50 KB is cut to its first and last lines for the
model; the full output is saved under `~/.cache/bia/outputs/` and the note
tells the model where, so `read_file` can page through it.

## MCP servers

MCP servers give Bia more tools: GitHub, databases, browsers, your own. List
them in `~/.config/bia/mcp.json`, in the `mcpServers` format other agents use,
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
  alone, and kept in `~/.local/state/bia/mcp-auth.json`, readable by you
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
  session can be used at the next one. With none, there is nothing to load.

## Configuration

Later sources override earlier ones:

1. Built-in defaults
2. `~/.config/bia/config.php`: yours, trusted, any setting
3. `.agents/bia.json` in the project: limited to the keys below
4. Environment variables
5. Command-line options

### Your config

`~/.config/bia/config.php` (or `$XDG_CONFIG_HOME/bia/config.php`) returns an
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
    ],
    'storage' => [
        'path' => '~/.local/state/bia/bia.sqlite',
    ],
];
```


What you pick in a conversation is kept for the next ones: `/model` and
`/render` set `model.name` and `tui.render` in this file. Only that value
changes; your comments, `env()` calls and layout stay as you wrote them.

### Providers

A provider is a model server set up once: its URL, where its key is, and its
models' context window. Pick any of its models as `provider/model`, with
`--model`, `BIA_MODEL` or `model.name`; there is no list of models to keep.

Three are built in:

| Provider | Server | Key from | Context window |
|---|---|---|---|
| `openai` | `https://api.openai.com/v1` | `OPENAI_API_KEY` | 400,000 |
| `anthropic` | `https://api.anthropic.com/v1` (OpenAI-compatible endpoint) | `ANTHROPIC_API_KEY` | 200,000 |
| `cliproxyapi` | `http://127.0.0.1:8317/v1` ([CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI)) | `CLIPROXYAPI_API_KEY` | 200,000 |

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
    'tdaproxy' => [
        'base_url' => 'https://tdaproxy.example/v1',
        'api_key' => env('TDA_TOKEN'),
        'context_window' => 272000,
        'models' => ['gpt-6-mini' => ['context_window' => 128000]],
    ],
],
'model' => ['name' => 'tdaproxy/gpt-6-astra'],
```

A provider takes any `model` setting (`base_url`, `api_key`, `endpoint`,
`timeout`, `idle_timeout`, `context_window`). `--base-url`, `--api-key`,
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
| `XDG_CONFIG_HOME`, `XDG_STATE_HOME` | Where config and sessions live |
| `NO_COLOR` | Plain tool lines |

### Permissions

Bia runs with full permission by default: every tool runs without asking.
Set a permission to `ask` to be asked first (`Allow shell ({"command":"..."})?
[y/N]`), or to `deny` to refuse it outright. `--yes` answers `ask` with yes;
`deny` always wins.

## Project instructions

Bia puts instruction files in its system prompt, general first:

1. `~/.config/bia/AGENTS.md`: yours, for every project.
2. From the repository root (the nearest parent with `.git`) down to the
   working directory, one file per directory: `AGENTS.md`, or `CLAUDE.md`
   where there is none.

Together they are capped at 32 KB. `bia doctor` lists the files that apply.
The prompt also says where Bia runs (directory, platform, date, git branch)
and how to use its tools. Your `prompt.system` leads it.

## Sessions and compaction

Sessions live in `~/.local/state/bia/bia.sqlite` as an append-only log: each
entry (a message, a tool result, a compaction) points at the one before it,
and a session is the path from its newest entry back. Bia stores completed
messages and tool results, not each streamed token. Messages are kept in no
provider's format, so the same session works with any adapter.

```sh
bia sessions                           # id, last used, entries, model, first prompt
bia resume                             # pick one of this folder's sessions
bia resume 2e18 "now add a test for it"  # the start of an id is enough
bia fork 2e18                          # a new session from that point; the original is untouched
bia export 2e18 > session.jsonl
```

Before a request would take more than `context_window - reserve_tokens`,
Bia asks the model to summarize everything except the last `keep_tokens` of
the conversation, never splitting a tool call from its result. The summary
replaces that part in what is sent; the summarized entries stay in the log.
A later compaction folds the previous summary in. If a provider still
reports the context as too long, Bia compacts and retries the turn once.
Set `model.context_window` to your model's window: the default, 128000,
suits most current models.

## Development

```sh
composer test          # PHPUnit
php bin/bia --help     # must work without an API key or a writable project
php bin/bia doctor
```

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

Bia is at 0.1.0-beta.2. Not yet built:
- **Only the Chat Completions API.** Native Anthropic Messages, Gemini and
  OpenAI Responses adapters are planned.
- **No MCP servers.**
- **No sandbox.** The agent has your permissions in the workspace and beyond
  it through `shell`.
- **Commands have no terminal.** They run without a controlling terminal, so
  a command that needs one (an interactive prompt, `sudo` asking for a
  password) fails rather than waits.
