# Bia benchmarks

Measure the CLI from this directory with the same PHP binary used in
production:

```sh
/usr/bin/time -f 'real=%e rss=%MKB' php bin/bia --help >/dev/null
/usr/bin/time -f 'real=%e rss=%MKB' php bin/bia doctor >/dev/null
```

The first development measurement on PHP 8.4.22 reports about 0.03 seconds for
`--help` and about 41 MB of resident memory. That is a startup baseline on one
host, not a general performance claim. Bare `php -r ''` uses about 38 MB on
the same host, so Bia's own startup cost is about 3 MB.

Shell tool calls run `/bin/sh -c`, not a login shell: on the same host one
`sh -lc true` took 62 ms and one `sh -c true` 1.4 ms (50 runs each, through
`Subprocess`, whose own overhead is about 1 ms a call). Model first-token time, tool completion,
total task time, token usage, and memory need separate matched trials.

## Against other coding agents

`php bench/agents.php` measures, without a model and without sending a
prompt, how each installed agent starts: `--version` (median of 5), the
interactive screen in a 120×40 terminal until it stops drawing (median of 3;
"ready" is the last output before 1.5 s of stillness), the memory of the
agent and every process it started at that moment, and what it installs.

On 2026-11-02, one Fedora host, PHP 8.4.22:

| Agent | `--version` | Peak memory | Ready (interactive) | Memory when ready | Install size |
|---|---:|---:|---:|---:|---:|
| Bia 0.1.0-beta.2 | 38 ms | 40 MB | **82 ms** | **42 MB** | **2.8 MB** + PHP (~20 MB) |
| Claude Code 2.1.316 | 11 ms | 26 MB | 546 ms | 221 MB | 313 MB |
| Codex 0.166.0 | 7 ms | 26 MB | 2159 ms | 377 MB | 516 MB |
| opencode 2.2.4 | 72 ms | 78 MB | 899 ms | 148 MB | 241 MB |
| Amp | 216 ms | 187 MB | 981 ms* | 167 MB | 128 MB |
| pi 1.4.0 | 241 ms | 108 MB | 786 ms | 148 MB | 107 MB + Node (118 MB) |

\* Amp was not signed in, so its screen was a sign-in question, not the chat.
opencode showed an update notice. The others reached their chat screen.

What it says, and what it does not:

- Bia's interactive screen is ready 7–26× sooner and uses 3.5–9× less
  memory than the others; its install is a fraction of theirs, PHP included.
- `--version` is slower for Bia than for Claude Code and Codex, whose
  native binaries answer it before loading anything: PHP's own start is most
  of Bia's 38 ms.
- Ready time includes whatever an agent does as it starts: update checks,
  sign-in, loading its UI framework. That is what a user waits for, but it
  depends on the network and the account, so compare runs on one host.
- Nothing here measures a model: first token, tool calls and whole tasks
  need every agent pointed at the same model, ideally a local fake one, to
  measure the harness rather than the provider.
