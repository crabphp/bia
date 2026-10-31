# Bia

Bia is a fast, resumable coding-agent CLI built on the Crab networking engine.
It streams model responses over Crab's event loop, keeps local conversations in
SQLite, and leaves the terminal renderer replaceable.

The first development checkout runs with:

```sh
composer install
php bin/bia --help
php bin/bia doctor
php bin/bia "inspect the repository and explain its structure"
```

Configuration is loaded from built-in defaults, `~/.config/bia/config.php`,
the project's `.bia/config.json`, environment variables, and command-line
options. Later sources override earlier ones. State defaults to
`~/.local/state/bia/bia.sqlite`.

Your own `config.php` may set anything. A project's `.bia/config.json` arrives
with the repository, so it is read as data, never run, and may set only
`model.name`, `prompt.system`, `permissions` and `tools`; a project that tries
to set the base URL, key or storage path is refused by name.

An API key may be fixed in `config.php` or supplied by an environment variable.
The environment wins when both are present.

Bia runs with full permission by default, like Codex or Claude Code: every
tool runs without asking. Set a permission to `ask` or `deny` to change that:

```php
return [
    'permissions' => [
        'read' => 'auto',
        'write' => 'ask',
        'shell' => 'deny',
    ],
];
```

`--yes` answers `ask` with yes for one invocation; `deny` always wins.

Bia's coding tools are `read_file` (whole or by line range), `list_files`,
`search`, `write_file`, `apply_patch`, `shell`, and `git_diff`. Consecutive
read-only calls run concurrently. Subprocesses run on Crab's event loop in a
process group of their own, so Ctrl-C cancels the turn (a second Ctrl-C
quits) and nothing a command started outlives it. Commands get your
environment minus `BIA_*` and any variable holding the model's API key.

Sessions can be listed and exported without opening a model connection:

```sh
bia sessions
bia resume <session-id> [prompt]
bia export <session-id> > conversation.jsonl
```

## Development status

The current slice establishes the contracts, configuration loader, SQLite
session store, terminal renderer, OpenAI-compatible streaming adapter, agent
turn loop, and bounded workspace tools. Durable Crab Runs integration and MCP
follow these foundations.
