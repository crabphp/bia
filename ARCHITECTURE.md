# Bia architecture

Bia is a CLI layer above Crab. The Crab engine owns nonblocking transport,
fibers, timers, cancellation, and process primitives. Bia owns model messages,
tool calls, workspace permissions, conversation storage, and terminal output.

```text
CLI -> Agent runtime -> Model adapter
                   -> Tool registry
                   -> Session store
                   -> Renderer
```

The model protocol is provider-neutral. A request contains normalized messages,
tool definitions, and model options. A stream produces text deltas, reasoning
deltas, tool calls, usage, completion, and failure events. OpenAI Responses,
Anthropic Messages, Gemini, OpenAI-compatible servers, and tdaproxy are
adapters, not concepts in the agent loop.

The local path is deliberately small. It starts no web `App`, opens SQLite only
when a session is used, and uses Crab's event loop only when a model call needs
network I/O. Sessions are rows in SQLite; JSONL is an export format. A live
conversation may use an actor for per-session ordering. Durable multi-step work
uses Crab Runs and Queue, with every model and tool boundary checkpointed.

The terminal is an event consumer. The first renderer uses ANSI text and line
input, with plain text and JSONL modes for pipes and tests. A full-screen TUI is
an optional later package and does not enter the agent core.

The first tools are deliberately small and bounded: file reads and writes,
listing, ripgrep search, shell commands, patch application, and git diff. Every
path is resolved beneath the workspace. Tools run with full permission by
default; an approval callback exists only when a permission is set to `ask` or
`deny`. Subprocesses go through Crab's `Engine\Process\Subprocess`: they run
on the event loop, in their own process group, with a timeout, an output cap
and the turn's cancellation, and without Bia's own credentials in their
environment. Patch application is checked before it is committed.

Every tool call gets a stored result, even when the tool throws or the turn is
cancelled, and a session left inconsistent by a crash is repaired as it is
loaded, so a session can always be resumed. Stored tool output is valid UTF-8
and, past its limit, keeps its head and tail.

## Speed rules

- Keep CLI startup independent of database and model initialization.
- Reuse HTTP connections and stream as soon as headers arrive.
- Run independent tools concurrently when their permissions allow it.
- Bound model output, tool output, subprocess time, and session writes.
- Store large files and command output as artifacts referenced by SQLite rows.
- Benchmark startup, first token, tool completion, total task time, and memory.
