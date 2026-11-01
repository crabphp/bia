# Working on Bia

Bia is a fast PHP 8.4 coding-agent CLI built on the Crab engine. Keep the
interactive path small: it should not start a web application, open a database,
or load a terminal UI framework just to print help or stream a model reply.

## Boundaries

- `Bia\Contracts` contains stable, provider-neutral contracts and value objects.
- `Bia\Model` contains model adapters. The agent loop never names a provider.
- `Bia\Session` owns SQLite persistence. The database is opened lazily.
- `Bia\Terminal` renders events; the agent loop never writes directly to a TTY.
- Use Crab's event loop and HTTP client for network streaming and cancellation.
- Keep model, tool, and session operations cancellable and bounded.
- Run subprocesses through `Bia\Tool\ProcessRunner` (Crab's `Subprocess`), never
  `proc_open` or `shell_exec` directly: that is what makes them cancellable,
  concurrent and free of Bia's credentials. The one exception is
  `Tui\Terminal`'s `stty` calls on /dev/tty, which need the terminal itself.
- `Bia\Tui` names nothing of Bia outside it: only the Crab engine. Its
  components take keys and return lines and never write to the terminal;
  only `Region` and `Terminal` do. Keep the theme minimal: dim, one accent,
  green and red, and nothing when `NO_COLOR` is set.
- Draw through `Region`, never with a direct write while the chat runs, or the
  live lines are drawn over.
- Tools run with full permission by default; do not make approval the default.
- A project's `.bia/config.json` is untrusted data. Never `require` a project
  file, and never let it set where requests or keys go.

## Fast path

- Do not initialize Crab's full web `App` from `bin/bia`.
- Load the TUI only for a conversation in a terminal; `--help`, one-shot
  prompts and pipes never touch it.
- Do not log tokens, prompts, or credentials by default.
- Do not persist a token delta; persist completed messages and tool boundaries.
- Measure startup, first-token latency, tool latency, and memory before keeping an optimization.

## Checks

Run `composer test` from this directory. `php bin/bia --help` and
`php bin/bia doctor` should work without an API key or a writable project.
