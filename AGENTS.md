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
- Run subprocesses through `Bia\Tool\ProcessRunner` (Crab's `Subprocess`, or its
  `Process` for one that keeps running, as an MCP server), never `proc_open` or
  `shell_exec` directly: that is what makes them cancellable, concurrent and
  free of Bia's credentials, and what stops them when Bia ends. The one exception is
  `Tui\Terminal`'s `stty` calls on /dev/tty, which need the terminal itself.
- `Bia\Tui` names nothing of Bia outside it: only the Crab engine. Its
  components take keys and return lines and never write to the terminal;
  only the two `Surface`s, `Screen` and `Region`, and `Terminal` do. Keep the
  theme minimal: dim, one accent, green and red, a grey shade behind the
  user's messages and nothing else, for code only
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
- The user's message is the only shaded row: it and a rule (`─`) span the
  full width, the message's margin inside its colour. Nothing else has a
  background: not code, not a diff (its `+` and `-` are green and red).
- What belongs under a line is indented two columns: a tool's output, a
  diff's lines, a running command's output, a group opened, a code block.
- Code has no background: inline code is the accent, a code block is set in
  and highlighted.
- A marker is one character and one space: `›` the user, `•` a tool call
  (its colour says how it went), `·` a note, `•` a bullet in an answer. Rows a line wraps into hang
  under the text after its marker; a closed tool call's lines do not wrap:
  each takes one row, cut with `…`, and wraps in full once opened.

**Space down**
- Blocks are: the banner, the user's message, the answer's text, a run of
  tool calls and notes, and the live lines at the bottom. Exactly one blank
  line separates two blocks: start each with `Surface::gap()`, which never
  makes a second one and none at the top.
- Nothing inside a block is blank, except the answer's own paragraph breaks;
  an answer's leading blank lines are dropped. Shaded rows have no blank rows
  of padding.

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
