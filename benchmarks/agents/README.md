# Agent benchmark

Runs one task through Bia, Claude Code, Codex and pi on the same model, and
writes a page to compare what each made, how long it took and what it spent.

```sh
php benchmarks/agents/run.php --task=voxel-world
php benchmarks/agents/run.php --task=smoke --agents=bia,pi   # check the adapters
php benchmarks/agents/run.php --help
```

Open `results/<time>-<task>/index.html`. It shows each agent's page live and as
a headless screenshot, what the agent said it built, its stdout and stderr,
and a table of time, requests and tokens.

## Fair by construction

- **One model, one meter.** Every request goes through `meter.mjs`, a local
  proxy to the same upstream (the `tdaproxy` provider in `~/.bia/config.php`,
  or `BENCH_UPSTREAM` and `BENCH_UPSTREAM_KEY`). It logs each request's time to
  first byte, total time and token usage, read the same way from Chat
  Completions, Responses and Anthropic Messages. Each agent's own count is not
  used. Bia, Codex and pi speak Responses; Claude Code speaks Messages, so the
  upstream must serve both.
- **Clean start.** Each run gets a fresh git repository under
  `~/.cache/bia-bench`, outside any project, and an empty home of its own
  (`BIA_HOME`, `CLAUDE_CONFIG_DIR`, `CODEX_HOME`, `PI_CODING_AGENT_DIR`). No user
  instructions, memory, settings or MCP servers reach an agent. No agent holds
  the real key: the meter sends it.
- **Same terms.** The same reasoning effort, full permissions, and a 64k
  output limit where an agent sets one. All agents run at once by default,
  under the same upstream conditions; `--sequential` runs one at a time.

## The check

`check()` loads `index.html` in headless Chromium with software WebGL, and
records whether it loads, whether the screenshot draws more than a blank
screen, and the console errors. It is a smoke check: whether the result is
good is for you to judge, playing each one.

## Tasks

A task is `tasks/<name>/prompt.md`. `voxel-world` asks for a playable
Minecraft-style world in one WebGL2 file; `smoke` asks for a rotating
triangle, to check the adapters cheaply.
