<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Terminal\Commands;
use Bia\Terminal\History;
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

    public function testTheSpinnerShowsWhatAndHowLong(): void
    {
        $spinner = new Spinner('Running shell', since: 100.0);
        self::assertSame('🍺 Running shell… 5s · esc to stop', $spinner->render(80, now: 105.3, hint: 'esc to stop'));
        self::assertSame('🍻 Running shell… 5s', $spinner->render(80, now: 105.6), 'Half a second on, the mugs clink.');
        self::assertSame('🍺 Running shell… 1m 5s', $spinner->render(80, now: 165.0));
        self::assertSame(20, Style::width($spinner->render(20, now: 105.3)), 'The two-column mug is counted in the width.');
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
        self::assertStringContainsString("hello world\r\npartial\r\ninput", $screen, 'The unfinished line stays above the region.');
        self::assertStringContainsString("\e[1A\e[J", $screen, 'Redrawing goes back to the top of the region and clears it.');
        self::assertStringEndsWith("partial\r\n\e[?25l\e[?2026l\e[?25h", $screen, 'Closing finishes the line and removes the region.');
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
        self::assertSame(['abcd', 'ef'], Style::wrapAnsi('abcdef', 4));
        self::assertSame([''], Style::wrapAnsi('', 4));
        self::assertSame(["\e[36mabcd\e[0m", "\e[36mef\e[0m"], Style::wrapAnsi("\e[36mabcdef\e[0m", 4), 'A row ending inside a colour resets it; the next starts it again.');
        self::assertSame(['a   ', 'b'], Style::wrapAnsi("a\tb", 4), 'A tab ends at the row it is on.');
        self::assertSame(['ab'], Style::wrapAnsi("a\e[2Kb\x07", 4), 'Codes that move the cursor are dropped.');
        self::assertSame(['日本', '語'], Style::wrapAnsi('日本語', 5));
        self::assertSame(
            ["\e[100mabcd\e[K\e[0m", "\e[100mef\e[K\e[0m"],
            Style::wrapAnsi("\e[100mabcdef\e[K\e[0m", 4),
            'A shaded bar fills every row it wraps onto.',
        );
    }

    public function testTheMouseWheelIsReadAsKeys(): void
    {
        $buffer = "\e[<64;10;5M\e[<65;10;5M\e[<0;3;4M\e[<0;3;4m\e[M`!!";
        $names = [];
        while (($key = Keys::take($buffer)) !== null) {
            $names[] = $key->name;
        }
        self::assertSame(['wheel-up', 'wheel-down', 'mouse', 'mouse', 'wheel-up'], $names);

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
        self::assertSame(['line 7', 'line 8', 'line 9', 'line 10', 'input', 'status'], self::screenRows($output, 6), 'It follows the newest output.');

        $screen->scroll(-3);
        $screen->flush();
        self::assertSame(['line 4', 'line 5', 'line 6', '↓', 'input', 'status'], self::screenRows($output, 6));

        $screen->print("line 11\n");
        $screen->flush();
        self::assertSame('line 4', self::screenRows($output, 6)[0], 'New output does not move what is being read.');

        $screen->scroll(-100);
        $screen->flush();
        self::assertSame('line 1', self::screenRows($output, 6)[0], 'Scrolling stops at the oldest line.');

        $screen->scroll(100);
        $screen->flush();
        self::assertSame(['line 8', 'line 9', 'line 10', 'line 11', 'input', 'status'], self::screenRows($output, 6), 'Scrolling to the end follows again.');
        self::assertSame(3, $screen->page());

        $screen->close();
        rewind($output);
        $all = (string) stream_get_contents($output);
        self::assertStringContainsString("\e[?1049h", $all, 'It draws on the alternate screen.');
        self::assertStringContainsString("\e[?1049lline 1\r\nline 2\r\n", $all, 'Closing prints the output on the main screen.');
    }

    public function testMarkdownIsRenderedALineAtATime(): void
    {
        Style::enable(true);
        $markdown = new Markdown();
        self::assertSame("\e[1mTitle\e[0m", $markdown->line('## Title'));
        self::assertSame("a \e[1mbold\e[0m and \e[36mco**de**\e[0m", $markdown->line('a **bold** and `co**de**`'), 'Code is left as it is inside.');
        self::assertSame('  • item', $markdown->line('  - item'));
        self::assertSame("\e[2m│ said\e[0m", $markdown->line('> said'));
        self::assertSame('a __init__ b', $markdown->line('a __init__ b'));
        self::assertSame("\e[2m```text\e[0m", $markdown->line('```text'));
        self::assertSame("\e[36m# not a heading\e[0m", $markdown->line('# not a heading'), 'Inside a code block, nothing is markup.');
        self::assertSame("\e[2m```\e[0m", $markdown->line('```'));
        $markdown->line('```php');
        self::assertSame("\e[36mreturn\e[39m \e[33m'x'\e[39m; \e[2m// done\e[22m", $markdown->line("return 'x'; // done"), 'A block in a known language is highlighted.');
        $markdown->line('```');
        self::assertSame('half **open', $markdown->preview('half **open'), 'A marker not yet closed waits.');
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
        self::assertSame('Ti', self::screenRows($output, 6)[0], 'A line still streaming is shown, rendered, as far as it goes.');
        $renderer->event(ModelEvent::text("tle\nnext **b"));
        $renderer->event(ModelEvent::text('old**'));
        $renderer->finish(new ModelResult(''));
        $screen->flush();
        self::assertSame(['Title', 'next bold'], array_slice(self::screenRows($output, 6), 0, 2));
    }

    public function testAToolCallShowsTheChangeItMade(): void
    {
        $diff = Diff::between("a\nb\nc\nd\ne\nf\n", "a\nB\nc\nd\ne\nF\n");
        self::assertSame(['@1', ' a', '-b', '+B', ' c', '@5', ' e', '-f', '+F'], $diff, 'Changes far apart are hunks of their own, with a line around each.');
        $lines = TuiRenderer::toolLines(new ToolCall('1', 'edit', ['path' => 'x.php']), true, 'Edited x.php', ['diff' => $diff]);
        self::assertSame(['✓ edit x.php', '    a', '  - b', '  + B', '    c', '  ⋯', '    e', '  - f', '  + F'], $lines);
        self::assertSame(['@1', ' a', '-b', '+B', ' c', ' d', '+e'], Diff::between("a\nb\nc\nd\n", "a\nB\nc\nd\ne\n"), 'Changes close together share a hunk.');

        $long = Diff::between('', implode("\n", range(1, 100)));
        self::assertSame('…' . (100 - Diff::LIMIT), end($long), 'A big change is cut, and what is left is counted.');
        self::assertStringEndsWith('… 84 more', end(...[TuiRenderer::toolLines(new ToolCall('2', 'write_file', []), true, '', ['diff' => $long])]));

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
