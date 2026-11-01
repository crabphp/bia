# Changelog

## Unreleased

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
