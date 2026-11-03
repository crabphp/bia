<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Terminal\Commands;
use Bia\Terminal\History;
use Bia\Terminal\Stats;
use Bia\Terminal\TuiRenderer;
use Bia\Tool\Diff;
use Bia\Tui\Highlight;
use Bia\Tui\Markdown;
use Bia\Tui\Editor;
use Bia\Tui\Fuzzy;
use Bia\Tui\Key;
use Bia\Tui\Keys;
use Bia\Tui\Region;
use Bia\Tui\Screen;
use Bia\Tui\SelectList;
use Bia\Tui\Spinner;
use Bia\Tui\Style;
use Bia\Tui\Terminal;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class TuiTest extends TestCase
{
    protected function setUp(): void
    {
        Style::enable(false);
    }

    public function testKeysAreReadFromTerminalBytes(): void
    {
        $buffer = "a\e[A\r\x7f\x03\e[1;5D\eb\e\r\e[200~one\r\ntwo\e[201~é\e[99X\x17";
        $names = [];
        while (($key = Keys::take($buffer)) !== null) {
            $names[] = $key->name === 'char' || $key->name === 'paste' ? "{$key->name}:{$key->text}" : $key->name;
        }
        self::assertSame(['char:a', 'up', 'enter', 'backspace', 'ctrl-c', 'ctrl-left', 'ctrl-left', 'alt-enter', "paste:one\ntwo", 'char:é', 'unknown', 'ctrl-w'], $names);
    }

    public function testEscapeFollowedByAControlKeyIsTwoKeys(): void
    {
        $buffer = "\e\x04\ex";
        self::assertSame('escape', Keys::take($buffer)?->name);
        self::assertSame('ctrl-d', Keys::take($buffer)?->name);
        self::assertSame('alt-x', Keys::take($buffer)?->name);
    }

    public function testAKeyCutShortWaitsForTheRest(): void
    {
        $buffer = "\xc3";
        self::assertNull(Keys::take($buffer), 'Half a character is not a key yet.');
        $buffer .= "\xa9";
        self::assertSame('é', Keys::take($buffer)?->text);

        $buffer = "\e[200~half a paste";
        self::assertNull(Keys::take($buffer));
        self::assertSame("\e[200~half a paste", $buffer, 'A paste waits for its end.');
    }

    public function testTheEditorEditsLinesAndSubmits(): void
    {
        $editor = new Editor();
        foreach (['h', 'e', 'y'] as $char) {
            $editor->key(new Key('char', $char));
        }
        $editor->key(new Key('ctrl-j'));
        $editor->key(new Key('paste', "second\tline"));
        self::assertSame("hey\nsecond    line", $editor->text());

        $editor->key(new Key('ctrl-w'));
        self::assertSame("hey\nsecond    ", $editor->text());
        $editor->key(new Key('backspace'));
        $editor->key(new Key('ctrl-u'));
        $editor->key(new Key('backspace'));
        self::assertSame('hey', $editor->text(), 'Backspace at a line start joins it to the line above.');

        $editor->key(new Key('char', '\\'));
        self::assertFalse($editor->key(new Key('enter')), 'A line ending in a backslash continues.');
        self::assertSame("hey\n", $editor->text());
        self::assertTrue($editor->key(new Key('enter')));
    }

    public function testTheEditorWalksTheHistory(): void
    {
        $editor = new Editor(['first', 'second']);
        $editor->key(new Key('char', 'x'));
        $editor->key(new Key('up'));
        self::assertSame('second', $editor->text());
        $editor->key(new Key('up'));
        $editor->key(new Key('up'));
        self::assertSame('first', $editor->text());
        $editor->key(new Key('down'));
        $editor->key(new Key('down'));
        self::assertSame('x', $editor->text(), 'Coming back down gives the draft back.');
    }

    public function testTheEditorWrapsAndPlacesTheCursor(): void
    {
        $editor = new Editor();
        $editor->set(str_repeat('a', 12));
        [$rows, $cursor] = $editor->render(10);
        self::assertSame(['› aaaaaaaa', '  aaaa'], $rows);
        self::assertSame([1, 6], $cursor);

        $editor->set('');
        [$rows] = $editor->render(30, 'Ask anything');
        self::assertSame(['› Ask anything'], $rows);
    }

    public function testASelectListFiltersMovesAndChooses(): void
    {
        $list = new SelectList([
            ['value' => 'tdaproxy/gpt-6-astra'],
            ['value' => 'tdaproxy/claude-sonnet-5-5'],
            ['value' => 'openai/gpt-5'],
        ], height: 2, selected: 'openai/gpt-5');
        self::assertSame('openai/gpt-5', $list->selected()['value']);
        $list->key(new Key('down'));
        self::assertSame('tdaproxy/gpt-6-astra', $list->selected()['value'], 'Moving past the end wraps.');

        foreach (str_split('sonnet') as $char) {
            $list->key(new Key('char', $char));
        }
        self::assertSame(1, $list->count());
        self::assertSame('choose', $list->key(new Key('enter')));
        self::assertSame('tdaproxy/claude-sonnet-5-5', $list->selected()['value']);
        self::assertSame('cancel', $list->key(new Key('escape')));
    }

    public function testASelectListDrawsAWindowOfItems(): void
    {
        $list = new SelectList(array_map(static fn (int $n): array => ['value' => "item{$n}", 'hint' => "hint {$n}"], range(1, 5)), height: 2);
        $list->key(new Key('down'));
        $list->key(new Key('down'));
        self::assertSame(['  item2  hint 2', '› item3  hint 3', '  3/5'], $list->render(40));
    }

    public function testFuzzyMatchingPrefersFileNames(): void
    {
        $sorted = Fuzzy::sort('app', ['src/application/x.php', 'src/app.php', 'docs/a-p-p.md', 'README.md'], static fn (string $file): string => $file);
        self::assertSame('src/app.php', $sorted[0]);
        self::assertNotContains('README.md', $sorted);
        self::assertNull(Fuzzy::score('zz', 'src/app.php'));
    }

    public function testThinkingIsShownAsAPlayfulWordThatChanges(): void
    {
        $spinner = new Spinner('Thinking', since: 100.0, word: 0);
        self::assertStringContainsString(Spinner::WORDS[0] . '…', $spinner->render(80, now: 101.0));
        self::assertStringContainsString(Spinner::WORDS[1] . '…', $spinner->render(80, now: 111.0), 'A new word every ten seconds.');
        self::assertStringNotContainsString('Thinking', $spinner->render(80, now: 101.0));
        $spinner->label('Running shell');
        self::assertStringContainsString('Running shell…', $spinner->render(80, now: 101.0), 'Real work keeps its own label.');
    }

    public function testTheSpinnerShowsWhatAndHowLong(): void
    {
        $spinner = new Spinner('Running shell', since: 100.0);
        self::assertSame('▀▄▀) Running shell… 5s · esc to stop', $spinner->render(80, now: 105.3, hint: 'esc to stop'), 'Without colour the bubble is a lower half.');
        self::assertSame('▀▀▄) Running shell… 5s', $spinner->render(80, now: 105.5), 'A quarter second on, the bubble has moved.');
        self::assertSame('▀▀▀) Running shell… 1m 5s', $spinner->render(80, now: 165.75), 'Then it rests.');
        self::assertSame(20, Style::width($spinner->render(20, now: 105.3)), 'The beer is counted in the width.');
        Style::enable(true);
        self::assertSame(
            "\e[38;5;255;48;5;214m▀\e[38;5;255;48;5;222m▀\e[38;5;255;48;5;214m▀\e[0m\e[38;5;245m)\e[0m",
            explode(' ', $spinner->render(80, now: 105.3))[0],
            'In colour, each cell is foam over beer, and the bubble lighter.',
        );
    }

    public function testTheRegionRedrawsOnlyTheBottomAndKeepsFinishedOutput(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $region = new Region(new Terminal(STDIN, $output, [40, 20]));

        $region->set(['input', 'status']);
        $region->flush();
        $region->print("hello ");
        $region->print("world\npartial");
        $region->flush();
        self::assertFalse($region->atLineStart());
        $region->set(['input', 'status']);
        $region->flush();
        $before = ftell($output);
        $region->flush();
        self::assertSame($before, ftell($output), 'An unchanged frame writes nothing.');
        $region->close();

        rewind($output);
        $screen = (string) stream_get_contents($output);
        self::assertStringContainsString("input\r\nstatus", $screen);
        self::assertStringContainsString(" hello world\r\n partial\r\ninput", $screen, 'The unfinished line stays above the region, set in by the margin as all output is.');
        self::assertStringContainsString("\e[1A\e[J", $screen, 'Redrawing goes back to the top of the region and clears it.');
        self::assertStringEndsWith(" partial\r\n\e[?25l\e[?2026l\e[?25h", $screen, 'Closing finishes the line and removes the region.');
    }

    public function testSlashCommandsRunAndComplete(): void
    {
        $ran = [];
        $commands = (new Commands())
            ->add('model', 'Switch model', function (string $arguments) use (&$ran): void {
                $ran[] = "model:{$arguments}";
            }, '[name]')
            ->add('exit', 'Leave', function (string $arguments) use (&$ran): void {
                $ran[] = 'exit';
            }, aliases: ['quit']);

        self::assertTrue($commands->run('/model  openai/gpt-5 '));
        self::assertTrue($commands->run('/QUIT'));
        self::assertFalse($commands->run('/nope'));
        self::assertSame(['model:openai/gpt-5', 'exit'], $ran);
        self::assertSame(['/exit'], array_column($commands->matching('/qu'), 'value'));
        self::assertTrue(Commands::looksLikeOne('/model x'));
        self::assertFalse(Commands::looksLikeOne('/etc/hosts is broken'), 'A path goes to the model.');
        self::assertTrue($commands->takesArguments('/model'));
    }

    public function testHistoryKeepsMultiLinePromptsAcrossRuns(): void
    {
        $path = sys_get_temp_dir() . '/bia-history-' . bin2hex(random_bytes(4)) . '/history';
        $history = new History($path, limit: 2);
        $history->add('one');
        $history->add("two\nlines");
        $history->add('three');
        self::assertSame(["two\nlines", 'three'], $history->load());
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testStyleMeasuresWhatTheTerminalShows(): void
    {
        Style::enable(true);
        self::assertSame(5, Style::width(Style::accent('hello')));
        self::assertSame(['日本', '語x'], Style::wrap('日本語x', 4));
        self::assertSame('abc…', Style::truncate('abcdef', 4));
    }

    public function testStyledTextWrapsWithItsColourCarriedOver(): void
    {
        self::assertSame(['abcd', 'ef'], Style::wrapAnsi('abcdef', 4), 'A word longer than the row is cut.');
        self::assertSame(['the quick', 'brown fox', 'jumps'], Style::wrapAnsi('the quick brown fox jumps', 10), 'Rows end between words, and the space a row ends at is not kept.');
        self::assertSame(['a', 'b'], Style::wrapAnsi('a      b', 3), 'No row starts with the spaces it was broken at.');
        self::assertSame(['• one two', '  three'], Style::wrapAnsi('• one two three', 10, indent: 2), 'Rows after the first hang under the text, past the bullet.');
        self::assertSame(["\e[48;5;236m › hi there\e[0m", "\e[48;5;236m   friend  \e[0m"], Style::wrapAnsi("\e[48;5;236m › hi there friend\e[K\e[0m", 11, indent: 3), 'A shaded line shades its indent too.');
        self::assertSame([''], Style::wrapAnsi('', 4));
        self::assertSame(["\e[36mabcd\e[0m", "\e[36mef\e[0m"], Style::wrapAnsi("\e[36mabcdef\e[0m", 4), 'A row ending inside a colour resets it; the next starts it again.');
        self::assertSame(['a', 'b'], Style::wrapAnsi("a\tb", 4), 'A tab at the end of a row is a break between words, like a space.');
        self::assertSame(['ab'], Style::wrapAnsi("a\e[2Kb\x07", 4), 'Codes that move the cursor are dropped.');
        self::assertSame(['日本', '語'], Style::wrapAnsi('日本語', 5));
        self::assertSame(
            ["\e[100mabcd\e[0m", "\e[100mef  \e[0m"],
            Style::wrapAnsi("\e[100mabcdef\e[K\e[0m", 4),
            'A shaded bar fills every row it wraps onto.',
        );
    }

    public function testTheMouseWheelIsReadAsKeys(): void
    {
        $buffer = "\e[<64;10;5M\e[<65;10;5M\e[<0;3;4M\e[<32;7;4M\e[<0;9;5m\e[<2;1;1M\e[<2;1;1m\e[M`!!";
        $keys = [];
        while (($key = Keys::take($buffer)) !== null) {
            $keys[] = $key->text === '' ? $key->name : "{$key->name}:{$key->text}";
        }
        self::assertSame(['wheel-up', 'wheel-down', 'mouse-down:3,4', 'mouse-drag:7,4', 'mouse-up:9,5', 'mouse', 'mouse', 'wheel-up'], $keys, 'The left button selects; another is only a mouse.');

        $buffer = "\e[<64;10";
        self::assertNull(Keys::take($buffer), 'Half a mouse report waits for the rest.');
        self::assertSame("\e[<64;10", $buffer);
    }

    public function testTheScreenScrollsTheOutputAndKeepsTheLiveLinesAtTheBottom(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [20, 6]));
        $screen->set(['input', 'status'], [0, 2]);
        for ($i = 1; $i <= 10; $i++) {
            $screen->print("line {$i}\n");
        }
        $screen->flush();
        self::assertSame([' line 7', ' line 8', ' line 9', ' line 10', 'input', 'status'], self::screenRows($output, 6), 'It follows the newest output, set in by its margin.');

        $screen->scroll(-3);
        $screen->flush();
        self::assertSame([' line 4', ' line 5', ' line 6', '↓', 'input', 'status'], self::screenRows($output, 6));

        $screen->print("line 11\n");
        $screen->flush();
        self::assertSame(' line 4', self::screenRows($output, 6)[0], 'New output does not move what is being read.');

        $screen->scroll(-100);
        $screen->flush();
        self::assertSame(' line 1', self::screenRows($output, 6)[0], 'Scrolling stops at the oldest line.');

        $screen->scroll(100);
        $screen->flush();
        self::assertSame([' line 8', ' line 9', ' line 10', ' line 11', 'input', 'status'], self::screenRows($output, 6), 'Scrolling to the end follows again.');
        self::assertSame(3, $screen->page());

        $screen->close();
        rewind($output);
        $all = (string) stream_get_contents($output);
        self::assertStringContainsString("\e[?1049h", $all, 'It draws on the alternate screen.');
        self::assertStringContainsString("\e[?1049lline 1\r\nline 2\r\n", $all, 'Closing prints the output on the main screen.');
    }

    public function testDraggingSelectsAndCopiesTheOutput(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [10, 8]));
        $screen->set(['input']);
        $screen->print("first line\nthis one wraps over\nlast\n");
        $screen->flush();
        // Eight columns inside the margins, broken between words:
        // " first", " line", " this one", " wraps", " over", " last".
        self::assertSame([' first', ' line', ' this one', ' wraps', ' over', ' last'], array_slice(self::screenRows($output, 8), 0, 6));

        self::assertNull($screen->pointer('down', 2, 2));
        self::assertNull($screen->pointer('drag', 6, 4));
        $screen->flush();
        self::assertSame("line\nthis one wraps", $screen->pointer('up', 6, 4), 'A wrapped line is copied whole: the space at its break given back, the margin left out.');
        rewind($output);
        $written = (string) stream_get_contents($output);
        self::assertStringContainsString("\e]52;c;" . base64_encode("line\nthis one wraps") . "\e\\", $written, 'It goes to the clipboard through the terminal.');
        self::assertStringContainsString("\e[7mline\e[0m", $written, 'The selection is drawn in inverse.');

        self::assertNull($screen->pointer('down', 2, 1));
        self::assertNull($screen->pointer('up', 2, 1), 'A click without a drag copies nothing.');
        self::assertNull($screen->pointer('down', 1, 8), 'The live lines below are not selected.');
    }

    public function testAToolCallOpensToItsOutputOnAClick(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [30, 8]));
        $screen->set(['input']);
        $screen->print("before\n");
        $screen->fold('✓ shell ls · 2 lines', "✓ shell ls\n  a.php\n  b.php");
        $screen->print("after\n");
        $screen->flush();
        self::assertSame([' before', ' ✓ shell ls · 2 lines', ' after'], array_slice(self::screenRows($output, 8), 0, 3));

        $screen->pointer('down', 3, 2);
        $screen->pointer('up', 3, 2);
        $screen->flush();
        self::assertSame([' before', ' ✓ shell ls', '   a.php', '   b.php', ' after'], array_slice(self::screenRows($output, 8), 0, 5), 'A click opens it in place.');

        $screen->pointer('down', 3, 3);
        $screen->pointer('up', 3, 3);
        $screen->flush();
        self::assertSame(' ✓ shell ls · 2 lines', self::screenRows($output, 8)[1], 'A click on any of its lines closes it.');

        $screen->folds(true);
        $screen->flush();
        self::assertSame('   b.php', self::screenRows($output, 8)[3], 'Ctrl-O opens them all.');
        $screen->pointer('down', 3, 1);
        $screen->pointer('up', 3, 1);
        $screen->flush();
        self::assertSame('   b.php', self::screenRows($output, 8)[3], 'A click elsewhere changes nothing.');
    }

    public function testAClosedToolCallTakesOneRowALineAndWrapsOnceOpened(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [20, 8]));
        $screen->set(['input']);
        $screen->fold("• Ran a very long command line\n  └ some long output here", "• Ran a very long command line\n  all");
        $screen->flush();
        self::assertSame([' • Ran a very long…', '   └ some long out…'], array_slice(self::screenRows($output, 8), 0, 2), 'Closed, cut at the width.');

        $screen->folds(true);
        $screen->flush();
        self::assertSame([' • Ran a very long', '   command line', '   all'], array_slice(self::screenRows($output, 8), 0, 3), 'Opened, wrapped in full.');

        self::assertSame("\e[2mab…\e[0m", Style::cut("\e[2mabcd\e[0m", 3), 'Cut text keeps its colour, then resets it.');
    }

    public function testShadedRowsSpanTheScreenWithTheirPaddingInside(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        Style::enable(true);
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [12, 5]));
        $screen->set(['input']);
        $screen->print("plain text\n" . "\e[48;5;235mcode()\e[K\e[0m\n");
        $screen->flush();
        $rows = self::screenRows($output, 5);
        self::assertSame(' plain text', $rows[0], 'Text is set in by the margin.');
        self::assertSame(' code()     ', $rows[1], 'A shaded row spans the screen, its padding inside its colour.');
        rewind($output);
        self::assertStringContainsString("\e[2;1H\e[2K\e[48;5;235m code()", (string) stream_get_contents($output), 'Its colour starts at the edge.');

        self::assertNull($screen->pointer('down', 1, 2));
        self::assertNull($screen->pointer('drag', 12, 2));
        self::assertSame('code()', $screen->pointer('up', 12, 2), 'Copied, it is its text alone.');
    }

    public function testStatsCountTheTurnAndTheSession(): void
    {
        $stats = new Stats();
        $drawing = ['fps' => 60, 'frame' => 0.42];
        self::assertSame(['60 fps · 0.4 ms', 'in 0 · out 0', 'mem 1 MB'], $stats->lines($drawing, memory: 1048576), 'Before any turn, no model figures.');

        $stats->turnStarted(now: 100.0);
        self::assertSame('first token … · – tok/s', $stats->lines($drawing, 0)[1], 'Waiting for the first token.');
        $stats->streamed(400, now: 100.8);
        $stats->streamed(400, now: 102.8);
        self::assertSame('first token 800 ms · 100 tok/s', $stats->lines($drawing, 0)[1], 'Estimated from the text: 800 bytes, about 200 tokens, over 2 s.');
        $stats->usage(['input' => 12_400, 'output' => 150]);
        self::assertSame('first token 800 ms · 75 tok/s', $stats->lines($drawing, 0)[1], 'Exact once the provider says.');
        self::assertSame('in 12.4k · out 150', $stats->lines($drawing, 0)[2]);
        $stats->turnStarted(now: 200.0);
        $stats->usage(['input' => 600, 'output' => 50]);
        self::assertSame('in 13.0k · out 200', $stats->lines($drawing, 0)[2], 'The session adds up across turns.');
    }

    public function testTheScreenDrawsStatsOverTheTopRight(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [30, 6]));
        $screen->set(['input']);
        $screen->print("a long line of conversation\n");
        $screen->overlay(['60 fps', 'mem 44 MB']);
        $screen->flush();
        $rows = self::screenRows($output, 6);
        self::assertStringEndsWith(' 60 fps ', $rows[0], 'Over the top right, after the row under it.');
        self::assertSame(1, $screen->metrics()['fps'], 'One frame drawn in the last second.');
        $screen->overlay([]);
        $screen->flush();
        self::assertSame(' a long line of conversation', self::screenRows($output, 6)[0], 'Hidden again, the row under it is whole.');
    }

    public function testMarkdownIsRenderedALineAtATime(): void
    {
        Style::enable(true);
        $markdown = new Markdown();
        self::assertSame(["\e[1mTitle\e[0m"], $markdown->line('## Title'));
        self::assertSame(["a \e[1mbold\e[22m and \e[36mco**de**\e[39m"], $markdown->line('a **bold** and `co**de**`'), 'Code is left as it is inside.');
        self::assertSame(["\e[1m\e[36mpermissions.mcp\e[39m is not checked\e[22m"], $markdown->line('**`permissions.mcp` is not checked**'), 'Bold pairs across code inside it, and keeps the code its colour.');
        self::assertSame(['  • item'], $markdown->line('  - item'));
        self::assertSame(["\e[2m│ said\e[0m"], $markdown->line('> said'));
        self::assertSame(['a __init__ b'], $markdown->line('a __init__ b'));
        self::assertSame([], $markdown->line('```text'), 'A fence and its language label are not shown.');
        self::assertSame(["  \e[36m# not a heading\e[39m"], $markdown->line('# not a heading'), 'Inside a code block, nothing is markup; it is set in, with no background.');
        self::assertSame([], $markdown->line('```'));
        $markdown->line('```php');
        self::assertSame(["  \e[36mreturn\e[39m \e[33m'x'\e[39m; \e[2m// done\e[22m"], $markdown->line("return 'x'; // done"), 'A block in a known language is highlighted.');
        $markdown->line('```');
        self::assertSame('half **open', $markdown->preview('half **open'), 'A marker not yet closed waits.');
    }

    public function testATableIsDrawnWithItsColumnsLinedUp(): void
    {
        $markdown = new Markdown();
        self::assertSame([], $markdown->line('| Name | Size |'), 'A table is held back until it is whole.');
        self::assertSame([], $markdown->line('|---|--:|'));
        self::assertSame([], $markdown->line('| bia | 3 MB |'));
        self::assertSame("Name  │ Size\n──────┼─────\nbia   │ 3 MB\ncodex │", $markdown->preview('| codex'), 'While it streams, it is drawn as far as it has come, the row arriving in its place.');
        self::assertSame([], $markdown->line('| codex | 516 MB |'));
        self::assertSame(
            ['Name  │   Size', '──────┼───────', 'bia   │   3 MB', 'codex │ 516 MB', 'after'],
            $markdown->line('after'),
            'A line after it ends the table: columns as wide as their widest cell, aligned as asked.',
        );
        $text = new Markdown();
        self::assertSame([], $text->line('| not a table |'));
        self::assertSame(['| not a table |', 'plain'], $text->line('plain'), 'A bar line without a separator under it is only text.');
        $open = new Markdown();
        $open->line('| a | b |');
        $open->line('|---|---|');
        self::assertSame(['a │ b', '──┼──'], $open->finish(), 'A table still open at the end of the answer is finished as it is.');
    }

    public function testCodeIsHighlightedInOnePassALineAtATime(): void
    {
        Style::enable(true);
        $php = Highlight::for('src/App.php');
        self::assertNotNull($php);
        self::assertSame("\e[36mfunction\e[39m f(\$class) { \e[36mreturn\e[39m \$this->list; }", $php->line('function f($class) { return $this->list; }'), 'A variable or member named like a keyword is a name.');
        self::assertSame("\e[2m/* open\e[22m", $php->line('/* open'));
        self::assertSame("\e[2mstill */\e[22m \e[36mif\e[39m", $php->line('still */ if'), 'A block comment carries over to the next line.');
        self::assertSame("\e[36mSELECT\e[39m 1 \e[2m-- why\e[22m", Highlight::for('sql')?->line('SELECT 1 -- why'));
        self::assertNull(Highlight::for('notes.txt'), 'A language not known is left plain.');
        Style::enable(false);
        self::assertNull(Highlight::for('php'), 'Without colour there is nothing to highlight.');
    }

    public function testTheRendererDrawsTheStreamingLineAgainAsItGrows(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [40, 6]));
        $renderer = new TuiRenderer($screen);
        $renderer->event(ModelEvent::text('# Ti'));
        $screen->flush();
        self::assertSame(' Ti', self::screenRows($output, 6)[0], 'A line still streaming is shown, rendered, as far as it goes.');
        $renderer->event(ModelEvent::text("tle\nnext **b"));
        $renderer->event(ModelEvent::text('old**'));
        $renderer->finish(new ModelResult(''));
        $screen->flush();
        self::assertSame([' Title', ' next bold'], array_slice(self::screenRows($output, 6), 0, 2));
    }

    public function testExploringIsOneBlockAndACommandIsARowOfItsOwn(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [70, 12]));
        $renderer = new TuiRenderer($screen);
        foreach ([['1', 'read_file', ['path' => 'src/a.php']], ['2', 'search', ['pattern' => 'gap', 'path' => 'src']], ['3', 'read_file', ['path' => 'src/b.php']]] as [$id, $name, $arguments]) {
            $call = new ToolCall($id, $name, $arguments);
            $renderer->running($call, '');
            $renderer->tool($call, new ToolResult('x'));
        }
        $shell = new ToolCall('4', 'shell', ['command' => 'composer test']);
        $renderer->running($shell, '');
        $renderer->running($shell, "one\ntwo\nthree\nfour\nfive\n");
        $screen->flush();
        self::assertSame([
            ' • Explored',
            '   └ Read a.php, b.php',
            '     Searched "gap" in src',
            ' • Running composer test',
            '   └ … +2 lines',
            '     three',
            '     four',
            '     five',
        ], array_slice(self::screenRows($output, 12), 0, 8), 'While a command runs, its end is shown live, under what was explored.');

        $renderer->tool($shell, new ToolResult("one\ntwo\nthree\nfour\nOK (5 tests)"));
        $gone = new ToolCall('5', 'shell', ['command' => 'ls nope']);
        $renderer->running($gone, '');
        $renderer->tool($gone, new ToolResult("ls: cannot access 'nope'\n[exit code 2]", false));
        $renderer->finish(new ModelResult(''));
        $screen->flush();
        self::assertSame([
            ' • Explored',
            '   └ Read a.php, b.php',
            '     Searched "gap" in src',
            ' • Ran composer test',
            '   └ … +3 lines (ctrl+o to expand)',
            '     four',
            '     OK (5 tests)',
            ' • Ran ls nope',
            "   └ ls: cannot access 'nope'",
            '     [exit code 2]',
        ], array_slice(self::screenRows($output, 12), 0, 10), 'Done: the block, then each command with the end of its output, a failure with why.');

        $screen->pointer('down', 4, 1);
        $screen->pointer('up', 4, 1);
        $screen->flush();
        self::assertContains(' • Searched "gap" in src', self::screenRows($output, 12), 'Opened, the block shows each call with what it gave.');
    }

    public function testAToolCallShowsTheChangeItMade(): void
    {
        $diff = Diff::between("a\nb\nc\nd\ne\nf\n", "a\nB\nB2\nc\nd\ne\nF\n");
        self::assertSame(['@1', ' a', '-b', '+B', '+B2', ' c', '@5', ' e', '-f', '+F'], $diff, 'Changes far apart are hunks of their own, with a line around each.');
        $lines = array_map([Style::class, 'strip'], TuiRenderer::toolLines(new ToolCall('1', 'edit', ['path' => 'x.txt']), true, 'Edited x.txt', ['diff' => $diff]));
        self::assertSame([
            '• Edited x.txt (+3 −2)',
            '  └    1   a',
            '       2 - b',
            '       2 + B',
            '       3 + B2',
            '       4   c',
            '       ⋮',
            '       6   e',
            '       7 - f',
            '       7 + F',
        ], $lines, 'Each line numbered in the new file, the lines added before a hunk moving it on.');
        self::assertSame(['@1', ' a', '-b', '+B', ' c', ' d', '+e'], Diff::between("a\nb\nc\nd\n", "a\nB\nc\nd\ne\n"), 'Changes close together share a hunk.');

        $long = Diff::between('', implode("\n", range(1, 100)));
        self::assertSame('…' . (100 - Diff::LIMIT), end($long), 'A big change is cut, and what is left is counted.');
        self::assertStringEndsWith('… +90 lines (ctrl+o to expand)', Style::strip(end(...[TuiRenderer::toolLines(new ToolCall('2', 'write_file', ['path' => 'n.txt']), true, '', ['diff' => $long])])));

        self::assertSame(['=src/a.php', '@3', ' keep', '-old', '+new'], Diff::ofPatch("diff --git a/src/a.php b/src/a.php\n--- a/src/a.php\n+++ b/src/a.php\n@@ -3,2 +3,2 @@\n keep\n-old\n+new\n"));
    }

    /**
     * What a terminal of the given rows shows after the bytes written so far,
     * for the few codes Screen writes: row moves, row clears and colours.
     *
     * @param resource $output
     * @return list<string>
     */
    private static function screenRows(mixed $output, int $rows): array
    {
        rewind($output);
        $bytes = (string) stream_get_contents($output);
        $screen = array_fill(0, $rows, '');
        $row = 0;
        foreach (preg_split('/(\e\[[0-9;?]*[A-Za-z])/', $bytes, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (preg_match('/^\e\[(\d+);\d+H$/', $part, $match) === 1) {
                $row = (int) $match[1] - 1;
            } elseif ($part === "\e[2J") {
                $screen = array_fill(0, $rows, '');
            } elseif ($part === "\e[2K") {
                $screen[$row] = '';
            } elseif ($part !== '' && $part[0] !== "\e" && isset($screen[$row])) {
                $screen[$row] .= str_replace(["\r", "\n"], '', $part);
            }
        }
        return $screen;
    }
}
