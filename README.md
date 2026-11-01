# Bia

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
- **Cancellable:** Ctrl-C stops the current turn, its model request and any
  running command, and returns to the prompt; a second Ctrl-C quits.
- **Clean subprocesses:** commands run in their own process group, so nothing
  a command starts in the background outlives it.
- **Resumable sessions** stored in SQLite as an append-only log, with fork,
  listing and JSONL export. A session cut off mid-turn by a crash is repaired
  when resumed.
- **Safe project settings:** a repository's `.bia/config.json` is read as data,
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
framework, which its `composer.json` loads by path. Until the framework's
`subprocess-and-stream-idle` branch is merged, check out that branch: Bia
needs its `Subprocess` and streaming idle timeout.

```sh
mkdir crabphp && cd crabphp
git clone -b subprocess-and-stream-idle git@github.com:crabphp/framework.git
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
bia resume <session-id> [prompt]    Continue a session
bia fork <session-id>               Start a new session from another's latest point
bia sessions [--json]               List recent sessions
bia export <session-id>             Write a session as JSONL
bia config [--json]                 Show the merged configuration, secrets redacted
bia doctor                          Check PHP, tools and configuration
bia init                            Create .bia/config.json in this directory
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

Run `bia` with no prompt in a terminal to get a `>` prompt. Each turn is
saved as it completes.

| Command | Effect |
|---|---|
| `/compact` | Summarize the conversation so far now |
| `/help` | List these commands |
| `/exit`, `/quit`, Ctrl-D | Leave |

- **Ctrl-C during a turn** cancels it: the model request and any running
  command stop, and you are back at the prompt.
- **A second Ctrl-C** quits.
- **A failed turn,** such as a rate limit or a dropped connection, prints the
  error and keeps the conversation open.

The session id is printed at the start (`session: 2e18...`). Continue it later
with `bia resume <id>`.

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

## Configuration

Later sources override earlier ones:

1. Built-in defaults
2. `~/.config/bia/config.php`: yours, trusted, any setting
3. `.bia/config.json` in the project: limited to the keys below
4. Environment variables
5. Command-line options

### Your config

`~/.config/bia/config.php` (or `$XDG_CONFIG_HOME/bia/config.php`) returns an
array:

```php
<?php

return [
    'model' => [
        'base_url' => 'https://api.openai.com/v1',
        'name' => 'gpt-5',
        'api_key' => null,          // prefer the environment
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

### Project config

`.bia/config.json` is found in the current directory or the nearest parent
with a `.bia/` directory or a `composer.json`. It ships with the repository,
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
| `<PROVIDER>_API_KEY` | `model.api_key` for `BIA_PROVIDER` (e.g. `TDAPROXY_API_KEY`) |
| `BIA_BASE_URL` | `model.base_url` |
| `BIA_MODEL` | `model.name` |
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
bia sessions
bia resume 2e1854a1819f72436858d0c0 "now add a test for it"
bia fork 2e1854a1819f72436858d0c0      # a new session from that point; the original is untouched
bia export 2e1854a1819f72436858d0c0 > session.jsonl
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
