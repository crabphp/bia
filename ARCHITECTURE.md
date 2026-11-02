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

The terminal is an event consumer. A conversation in a terminal runs
`Terminal\Chat` on the TUI kit; a one-shot prompt, a pipe and `--json` use
`Terminal\AnsiRenderer`'s plain text or JSONL. Neither enters the agent core,
which only calls the `Renderer` contract.

## Terminal UI

`Bia\Tui` is a small TUI kit on Crab's event loop, with no dependency beyond
the engine, kept able to move into the framework when a second user needs it:

| Part | Does |
|---|---|
| `Terminal` | Raw mode with `stty` on /dev/tty (Ctrl-C and Ctrl-Z arrive as keys), bracketed paste, the alternate screen with mouse-wheel reports, size, keys read on the event loop; gives the terminal back as found, on exit too |
| `Keys`, `Key` | Turns terminal bytes into keys, pastes and mouse-wheel turns |
| `Surface` | Where the chat draws: output above, live lines below. Two kinds: |
| `Screen` | Fullscreen, the default: the alternate screen, output set in by a one-column margin on each side, wrapped between words (a row after a leading marker such as `›` or `•` hangs under its text) and scrolled by Bia (wheel, PageUp/PageDown) with the live lines kept at the bottom; only changed rows are written, at most once a frame (1/60 s), in one synchronized write; dragging selects output, drawn in inverse, and copies it on release through the terminal (OSC 52), wrapped lines joined again; the output is printed on the main screen on leaving |
| `Region` | Inline, with `tui.fullscreen` false: finished output goes into the terminal's own scrollback, only the live lines at the bottom are redrawn, at most once a frame, and the screen is drawn afresh when a resize changes the width |
| `Editor` | Multi-line input with history, word moves and paste |
| `SelectList` | A filtered list for pickers and completion menus; keys in, lines out |
| `Spinner`, `Fuzzy`, `Style` | The working line, fuzzy ranking, and the minimal theme with display-width measuring and styled wrapping |
| `Markdown` | A streamed answer's markdown, a line at a time (about 1 µs a line): headings and bold, bullets, quotes, inline code; code blocks on a dark panel, fences hidden, highlighted by language; tables with aligned columns, held back until whole but drawn as far as they have come (a row costs about 1 µs to redraw) |
| `Highlight` | Basic syntax highlighting in one split a line (about 5 µs): keywords, strings, comments, for code blocks and diffs |

Components hold no terminal: they take keys and return lines, so they are
tested without one. It is not full-screen: the scrollback stays the
terminal's own, as in Pi and Claude Code.

`Terminal\Chat` composes them. Keys are read the whole time, in the
operation's fiber; a turn runs in a task of its own, so the next message can
be typed while it runs: Enter steers it, joining the message to the
conversation at the agent's next tool step (`Agent::run`'s `steer`), or
sending it right after when no step is left. Esc or Ctrl-C cancels the turn's
`Cancellation`. A tool whose permission is `ask` suspends the turn's fiber on
a picker until it is answered. `/model` lists every keyed provider's models
at once through `Model\ModelCatalog` and rebuilds the agent from
`Config::withModel()`; the session continues, since it is stored in no
provider's format.

The first tools are deliberately small and bounded: file reads and writes,
listing, ripgrep search, shell commands, patch application, and git diff. Every
path is resolved beneath the workspace. Tools run with full permission by
default; an approval callback exists only when a permission is set to `ask` or
`deny`. Subprocesses go through Crab's `Engine\Process\Subprocess`: they run
on the event loop, in their own process group, with a timeout, an output cap
and the turn's cancellation, and without Bia's own credentials in their
environment. Patch application is checked before it is committed.

## MCP

`Bia\Mcp` connects to MCP servers and offers their tools as Bia's own:

| Part | Does |
|---|---|
| `Server` | A server as configured, from the shared `mcpServers` format in the user's `mcp.json` and a project's `.mcp.json`, with `${VAR}` taken from the environment |
| `StdioTransport` | A server started through `ProcessRunner::start()` (Crab's `Process`): a JSON message a line each way; the end of its standard error says why it failed; closing stops it, killing it and what it started if it does not stop itself |
| `HttpTransport` | Streamable HTTP: each message posted, answered as JSON or as events, the server's session sent with each |
| `Connection` | JSON-RPC: the handshake, tools listed page by page, calls with a timeout and cancelled on the server when the turn is; the server's pings answered, its tool changes heard |
| `McpTool` | One server tool as a `Tool`, named `mcp__<server>__<tool>`, its result's content as text |
| `Manager` | Connects every server in a task of its own, keeps each one's status and tools, and stops them all at once |
| `Trust` | Which projects' `.mcp.json`, as each was, the user allowed to start servers |
| `OAuth` | Logging in to a remote server: its resource metadata (RFC 9728) from the `401`'s `WWW-Authenticate` or the well-known paths, its authorization server's metadata (RFC 8414, or OpenID's), registration (RFC 7591) unless a client is configured, PKCE with S256 and a `state`, the `resource` bound (RFC 8707); the code comes to a listener on 127.0.0.1 read on the event loop, or is pasted; tokens are refreshed before they run out, and once on a `401` |
| `AuthStore` | Each server's registered client and tokens, by its name and URL, in one file readable by the user alone |

The registry takes tools from the manager each time it is asked, and the agent
asks each step, so a server that connects during a turn serves its next step.
Servers start after the first screen is drawn: none delays Bia's start.

## Sessions

A session is an append-only log in SQLite. Each entry is a message (in no
provider's format: text, reasoning, tool calls, or one tool result) or a
compaction, and points at its parent; a session's head is its newest entry,
and its history is the path from the head back to the root. A fork is a new
session whose first entry's parent is an entry of another, so nothing is
copied or rewritten.

`Agent\Context` turns a path into what is sent: the latest compaction's
summary, then the entries it kept, then everything after it. It gives every
tool call a result, so a session cut off by a crash still resumes.

`Agent\Compactor` keeps requests inside the context window. It estimates a
request from the provider's last reported usage plus a character estimate of
what followed, and when it would pass `context_window - reserve_tokens` it
summarizes everything older than the last `keep_tokens`, cutting only at the
start of a user or assistant message. A provider's context-length error
triggers one compaction and a retry.

Every tool call gets a stored result, even when the tool throws or the turn
is cancelled. Stored tool output is valid UTF-8; past 2000 lines or 50 KB the
model gets its first and last lines and the full output is saved under the
cache directory, where `read_file` may read it.

## Prompt

`Agent\SystemPrompt` builds the system prompt per run: the configured
identity, tool guidance, the environment, and the `AGENTS.md` / `CLAUDE.md`
files from the user's config directory and from the repository root down to
the working directory, capped at 32 KB.

## Speed rules

- Keep CLI startup independent of database and model initialization.
- Reuse HTTP connections and stream as soon as headers arrive.
- Run independent tools concurrently when their permissions allow it.
- Bound model output, tool output, subprocess time, and session writes.
- Store large files and command output as artifacts referenced by SQLite rows.
- Benchmark startup, first token, tool completion, total task time, and memory.
