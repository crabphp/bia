# Changelog

## 0.1.3 (2026-11-10)

- Commands run in the user's own shell (`$SHELL`, bash or zsh) with their
  aliases and functions, as at their prompt: `ssh.work` from `~/.zshrc`
  works. They are taken once a session (and again by `/reload`) from an
  interactive shell, which sees `BIA=1`, not read on every call.
- Tool calls are journaled before they run and once their result is saved:
  a session cut off by a crash resumes with a finished call's saved result,
  or tells the model a call that was running may have had side effects.

## 0.1.2 (2026-11-09)

- One line installs Bia: `curl -fsSL
  https://raw.githubusercontent.com/crabphp/bia/main/install.sh | sh` finds
  the system's build, checks it against `SHA256SUMS`, and says if its folder
  is not on PATH.
- A stream that fails midway is tried again: any answer text already shown
  is closed under a notice and the answer starts over. Before, one mid-stream
  failure ended the turn.
- A turn with tool results behind it keeps trying a failing provider for up
  to five minutes at the last delay, rather than losing that work after
  31 seconds of tries.
- A turn whose request still failed is picked back up with Enter on an empty
  input: the model is asked again where it left off, nothing joins the
  conversation.
- The user's message is one shaded row a line, with no padding.
- MCP upload tools receive attached images directly, and invalid MCP arguments
  are reported before a server request.
- Responses function tools explicitly preserve optional MCP fields.
- Providers may select the OpenAI Responses wire format with `wire_api`;
  tdaproxy is configured that way in the user's global config.
- Responses tool turns preserve and replay completed output items, including
  encrypted reasoning, so reasoning models can continue after tool calls.
- Responses requests use stateless mode and accept wrapped JSON responses and
  custom `chat/completions` endpoint prefixes.

## Unreleased

## 0.1.1 (2026-11-06)

- **`/model` and `/effort` while Bia works,** no longer refused as busy:
  the turn running ends on the model it started with, and the choice
  takes effect from the next message, as its note says.
- **`bia update`** installs the latest release, for the binary and
  `bia.phar`: downloaded, checked against its `SHA256SUMS` and run once
  before it replaces the old one. A conversation says when one is out,
  asking GitHub in the background at most once a day, never making Bia
  wait; `update.check => false` turns that off. A Bia whose file another
  replaced as it ran stops with a message to start it again, rather than
  fail in a confusing way. 0.1.0 has no `bia update`: install it once more
  with the README's line, and from then on `bia update` does.

## 0.1.0 (2026-11-06)

- **Bia as one file, with PHP inside,** for Linux and macOS on x86_64 and
  arm64: each release has it, built by static-php-cli with only the
  extensions Bia calls. 14 MB, it starts in about 17 ms and needs no PHP
  installed. `bia.phar` is there too, for a PHP 8.4 of your own. A release
  is a tag; the release workflow tests, builds and publishes it.
- Reasoning effort goes up to `max`, in `--effort`, `/effort` and the config.
- `crabphp/framework` is required at `^0.6`, from Packagist, rather than any
  development checkout.
- On PHP 8.5, text other than ASCII no longer prints a deprecation notice.
- `/reload` reads the config, instructions and MCP servers again in place,
  rather than restarting Bia: the screen stays, background commands go on,
  and it no longer needs `pcntl`. A config with an error leaves everything as
  it was. New Bia code takes quitting and `bia resume`.
- On macOS a command stopped or timed out takes what it started with it, as
  on Linux: its process group is made with perl, as there is no `setsid`.
- On Windows Bia says to run it in WSL rather than failing on its way in.
- Bia keeps all its files in `~/.bia` (or `$BIA_HOME`): config.php,
  AGENTS.md, mcp.json, the sessions' database and the cache, not across
  `~/.config`, `~/.local/state` and `~/.cache`.
- While the model thinks, the spinner says so in beer words, as Claude Code
  has its verbs: Drinking, Brewing, Fermenting… a new one every ten seconds.
- `/stats` shows live drawing, model speed, token and memory figures at the
  top right.
- Ctrl-C clears what is typed first, even while Bia works; on an empty input
  it stops the turn.
- The banner's cat is drawn in pixels, amber like the working beer.
- A 501 or 505 from the model's server is not retried.
- **A terminal UI for conversations,** inline like Pi's: output scrolls, and
  the live lines at the bottom hold a spinner with what Bia is doing and for
  how long, the input, menus and a status line (workspace, git branch, model,
  context used). Drawn at most once a frame and only when changed; on screen
  in about 85 ms.
  - Multi-line input with history kept across runs, word moves and paste.
  - Type while Bia works: Enter queues the message. Esc or Ctrl-C stops the
    turn.
  - `/model` picks from every keyed provider's models, listed at once and
    fuzzy-filtered; `/model provider/name` switches directly. The session
    goes on with the new model.
  - `/new`, `/compact`, `/clear`, `/help`, `/exit`, `/quit`; `/` and `@`
    open completion menus of commands and workspace files.
  - A tool whose permission is `ask` asks in a Yes/No picker.
- **Resuming:** `/resume` picks one of this folder's sessions, titled by its
  first prompt, with when it was last used; `/resume <id>` and
  `bia resume <id>` take the start of an id; `bia resume` without one opens
  the picker. Resuming replays the end of the conversation. Leaving a
  session with messages prints `bia resume <id>`, in every mode.
- A conversation's session is created by its first message, so starting and
  leaving Bia no longer leaves empty sessions; `bia sessions` lists only
  sessions with entries, with their first prompt.
- `SessionStore::list()` takes a workspace and returns each session's title;
  `SessionStore::resolve()` finds a session by the start of its id.
- `Bia\Tui`: the kit it is built from (terminal, keys, live region, editor,
  select list, spinner, fuzzy matching, theme), on Crab's event loop alone.
- One-shot runs print the model next to the session id.
- `bia init --global` creates `~/.config/bia/config.php` (mode 0600) listing
  every setting at its default, commented out. It fills a missing or blank
  file and never overwrites one with content.
- A blank global config file no longer stops Bia from starting.
- **Providers:** set a model server up once under `providers` and pick any
  of its models as `provider/model` (`--model cliproxyapi/claude-sonnet-4-6`).
  `openai`, `anthropic` and `cliproxyapi` are built in. A provider sets any
  `model` setting; `models.<name>` overrides it for one model. Its key is
  `api_key`, pasted in or read with `env()`; without one, `<NAME>_API_KEY` is
  read. Explicit `--base-url`, `--api-key` and `BIA_*` variables still win.
- A key set in `config.php` is no longer replaced by `<PROVIDER>_API_KEY`;
  that variable is only a fallback now. `BIA_API_KEY` still overrides.
- `bia doctor` shows the model and the providers it knows.
- A missing API key's error names the model and provider Bia resolved, the
  variables it read and where to set the key.
- `bia config` showed `compaction.reserve_tokens` and `keep_tokens` as
  `[configured]`; only key, secret, password and token settings are hidden now.

## 0.1.0-beta.2 (2026-11-01)

Fixes found by the first runs against a real provider (gpt-6-astra and
gpt-6-sol through tdaproxy):

- **A tool without parameters broke every request.** `git_diff`'s empty
  `properties` was sent as a JSON list, which OpenAI-style APIs refuse; empty
  schema objects are now sent as `{}`.
- **An empty `path` is the workspace** for `list_files` and `search`; models
  often send optional arguments as `""`.
- **Leaving a conversation exits 0,** even after a cancelled turn.

## 0.1.0-beta.1 (2026-11-01)

The first beta: a terminal coding agent for any OpenAI-compatible Chat
Completions API.

- **Tools:** `read_file` (paged, 2000 lines / 50 KB), `list_files`, `search`,
  `edit` (exact text replacement, several edits per call, tolerant of
  typographic characters and trailing spaces), `write_file`, `apply_patch`,
  `shell` and `git_diff`, with full permission by default. Read-only calls
  run concurrently; commands run on Crab's event loop in their own process
  group, cancellable with Ctrl-C.
- **Project instructions:** `AGENTS.md` / `CLAUDE.md` from the repository
  root down to the working directory, and `~/.config/bia/AGENTS.md`.
- **Sessions:** an append-only SQLite log in no provider's format, with
  `resume`, `fork` and `export`. Sessions from earlier development builds are
  migrated on first use.
- **Compaction:** automatic before the context window fills, `/compact` on
  demand, and a retry after a provider's context-length error.
- **Long output:** cut to its first and last lines for the model, saved in
  full for `read_file`.
- **Configuration:** your `~/.config/bia/config.php`, and a project's
  `.bia/config.json`, read as data and limited to safe keys.
- **Errors:** a provider's own error message is shown; a failed turn keeps
  the conversation open.
