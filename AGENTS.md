# Working on Bia

Bia is a fast PHP 8.4 coding-agent CLI built on the Crab engine. Keep the
interactive path small: it should not start a web application, open a database,
or load a terminal UI framework just to print help or stream a model reply.

## Boundaries

- `Bia\Contracts` contains stable, provider-neutral contracts and value objects.
- `Bia\Model` contains model adapters. The agent loop never names a provider.
- `Bia\Session` owns SQLite persistence. The database is opened lazily.
- `Bia\Memory` owns what is kept between sessions, as plain files under Bia's
  home, read when a prompt is built and written only through its `memory`
  tool. It is never loaded into a subagent, and only a rule the user lays
  down is written into a project (its `AGENTS.md`).
- `Bia\Plugin` loads the user's own code from `~/.bia/plugins`, installed
  by them alone and never read from a project. A plugin reaches Bia through
  `Plugin\Host` and nothing else; widen `Host` one call at a time, for a
  plugin that needs it. It is loaded for a conversation only, never into a
  subagent, and one that throws is turned off: it never ends the chat.
  A plugin's commands go through `Host::run()`, which is `ProcessRunner`.
  Bia's own skills live in `src/Bia/Plugin/skills`, which the PHAR keeps.
- `Bia\Terminal` renders events; the agent loop never writes directly to a TTY.
- Use Crab's event loop and HTTP client for network streaming and cancellation.
- Keep model, tool, and session operations cancellable and bounded.
- Run subprocesses through `Bia\Tool\ProcessRunner` (Crab's `Subprocess`, or its
  `Process` for one that keeps running, as an MCP server), never `proc_open` or
  `shell_exec` directly: that is what makes them cancellable, concurrent and
  free of Bia's credentials, and what stops them when Bia ends. The exception is
  `Tui\Terminal`'s `stty` calls on /dev/tty, which need the terminal itself.
- `/reload` reads the config, instructions and MCP servers again in the same
  process; it never replaces Bia (no `pcntl_exec`), so it works the same from a
  checkout, a PHAR or a static build. New code needs a new `bia`.
- `Bia\Tui` names nothing of Bia outside it: only the Crab engine. Its
  components take keys and return lines and never write to the terminal;
  only the two `Surface`s, `Screen` and `Region`, and `Terminal` do. Keep the
  theme minimal: dim, one accent, green and red, a grey shade behind the
  user's messages, a dark green and a dark red behind a diff's lines added
  and removed, and nothing else, for code only
  the accent, yellow and dim, and the spinner's beer in foam white, amber and grey,
  and power mode's landing flash in amber; nothing when `NO_COLOR` is set.
- Rendering runs on every streamed line: keep `Markdown` and `Highlight` to
  one pass a line, with no library, and measure before adding to them.
- Draw through the `Surface`, never with a direct write while the chat runs, or
  the live lines are drawn over.
- Tools run with full permission by default; do not make approval the default.
- A project's `.agents/bia.json` is untrusted data. Never `require` a project
  file, and never let it set where requests or keys go.

## Design system

Every change to what a conversation shows follows these rules, in both
`Screen` (fullscreen) and `Region` (inline). Do not add an exception; change
the rule here first, with the user's agreement.

**Space across**
- Every row of output and of the live lines is set in by `Screen::MARGIN`
  (one column). The `Surface` applies it; a component never pads a line's
  start itself.
- The user's message is shaded: it and a rule (`─`) span the full width,
  the message's margin inside its colour. A diff's lines added and removed
  are on dark green and dark red from their `+` and `−` to the row's end
  (the marks green and red). Nothing else has a background: not code.
- What belongs under a line is indented two columns: a tool's output, a
  diff's lines, a running command's output, a group opened, a code block.
- Code has no background: inline code is the accent, a code block is set in
  and highlighted.
- A table is lined up in columns; one wider than the screen is a list
  instead: a bullet for each row, titled by its first cell, and under it
  each other cell as `Header: value`, the header dim.
- A marker is one character and one space: `›` the user, `•` a tool call
  (its colour says how it went), `·` a note, `•` a bullet in an answer. Rows a line wraps into hang
  under the text after its marker; a closed tool call's lines do not wrap:
  each takes one row, cut with `…`, and wraps in full once opened.

**Space down**
- Blocks are: the banner, the user's message, the answer's text, a run of
  tool calls and notes, a recap, and the live lines at the bottom. Exactly one blank
  line separates two blocks: start each with `Surface::gap()`, which never
  makes a second one and none at the top.
- A message fully answered ends with a note, dim: `· Worked for 4m 57s ·
  1:54 AM`, how long Bia worked on it and when it finished, in the
  machine's time zone. Not after one stopped or failed, nor on resume.
- Nothing inside a block is blank, except the answer's own paragraph breaks;
  an answer's leading blank lines are dropped. The user's message has no
  padding: one shaded row for each of its lines.

**Words**
- A line starts with a capital, unless it starts with a name: a tool, a
  command, a path, a model (`Ran composer test`, `Explored`).
- Terse: counts and names, not sentences; dim for what is secondary.

**Tool calls**, as Codex and Claude Code show them
- A call is a row: a dot (`•` dim running, green done, red failed), then
  what was done in plain words, dim: `Ran composer test`, `Edited
  src/App.php (+6 −2)`, `Called github · search`; never a tool's own name.
- What came of it hangs under it on a `└`, set in two columns: a
  command's last two lines, a tool's first two, a failure's last three,
  an edit's first ten diff lines numbered in the new file; then
  `… +N lines (ctrl+o to expand)` for the rest, and `(no output)` for none.
- Reads, listings and searches in a row are one block, `• Explored`
  (`Exploring` while one runs), a line under it for each kind: the files
  read, the places listed, each search.
- Running: `• Running …` with its last three lines, live.
- Once a turn is done, or the next message starts, each of its runs of
  tool calls collapses into one line of counts, `• Ran 2 commands, edited
  1 file (+2 −1), read 2 files · 1 failed`, its dot red if any failed.
  A resumed session shows its runs so too. Inline, where printed rows are
  the terminal's, they stay as they were drawn.
- Every row opens in place with a click, and all of them with Ctrl-O: a
  collapsed run opens under its line, and closes by a click on that line.

**Subagents**
- The main agent hands a subagent a task with the `agent` tool and gets
  back its answer alone; its row is `• Asked readme · Check the README
  links`, the answer's first lines under it, and a run of them collapses
  to `asked 2 agents`.
- While there are subagents, the footer lists them under the status line,
  one row each: `main` first, then each, a dot (`●` green working, `○` dim
  idle or done, `●` red failed or stopped), the name, and dim what it does
  now; cut with `…`. Finished ones go at the user's next message.
- ↓ from the input's last line enters the list, `❯` marks the row chosen;
  Enter or a click views that agent, `x` stops it, or closes it once done, Esc or ↑ past `main`
  gives the keys back, and typing goes back to the input. With the input
  empty, Alt+←/→ steps through the agents.
- Fullscreen, a subagent's page takes the conversation's place, drawn by
  these same rules, its task shaded as a user's message; the input steers
  it while it works, and Esc returns to the conversation. Inline, viewing
  one prints its run so far under `· agent · readme`.

**Memory**
- A memory call's row says what was kept, never the tool: `• Noted Chose
  SQLite over files`, `• Remembered crab-install`, `• Forgot crab-install`,
  `• Recalled crab-install`, `• Searched memory for "sqlite"`, and a rule
  as an edit, `• Added a rule to AGENTS.md (+1 −0)` with its diff. Under
  each, what was written or found.
- A run of them collapses to counts: `added 1 rule, saved 2 memories,
  forgot 1 memory, recalled once`.
- What Bia reminds the model of beside a message (a rule laid down with
  "must", "always", "never") is sent and kept with it, and never shown.
- `/memory` is `· Memory` dim and under it, set in two columns, a row for
  each of `About you`, `Diary`, `Project` and `Rules`: a count and the
  path, and under `Project` each memory's name and, dim, what it holds.

**Plugins**
- A plugin's tool is a call as an MCP server's is: `• Called standup ·
  word_count`. A plugin's note is a `·` note, dim, as Bia's own are.
- One that fails is a line in red, `bia: plugin standup: …`, under the
  banner when it failed as it loaded; during a turn it is a note among the
  tool calls, `· Plugin standup failed: …`.
- Read again because their files changed: `· Plugins reloaded · standup`,
  a note, among the tool calls when it happens during a turn.
- A plugin's command at work for a second or more, with no turn running,
  takes the status line, dim: `Running /standup · Esc to stop`.
- `/plugins` is `· Plugins` dim and under it, set in two columns, a row for
  each: its name, and dim what it adds, `/standup · 1 tool · tool.result ·
  2 skills · 1 MCP server`, or in red why it is off.

**Recap**
- `· Recap` dim, and under it, set in two columns, a few sentences of where
  things stand and a dim `Next:` line when something is. Written by
  `/recap`, or on its own after `recap.idle` away (the terminal unfocused,
  or no key where it does not tell focus) once three turns are done and
  two since the last. It is one small request of its own, counted in
  `/usage`, never part of the conversation the model sees; a session
  keeps it, shown again on resume while nothing has happened since.

**Render modes**
- `normal` shows text as it arrives; `smooth` paces it through `Tui\Pacer`;
  `power` paces it and draws `Tui\Effects` over it. Effects only ever draw
  over a frame: they change nothing kept, nothing copied, and nothing once
  they have settled. Anything else drawn first catches the paced text up.
- The banner's cat blinks once as it is first drawn, at the start
  only (not on `/new`), fullscreen and in colour only; nothing else in it moves.

**Checking**
- A change to what is shown is not done until it was looked at in a real
  terminal: run Bia in a named tmux session against a scripted
  OpenAI-compatible server and read `tmux capture-pane`, fullscreen and
  inline. Kill only that session, never the tmux server.

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
