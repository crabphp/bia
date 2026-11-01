<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Terminal\Commands;
use Bia\Terminal\History;
use Bia\Tui\Editor;
use Bia\Tui\Fuzzy;
use Bia\Tui\Key;
use Bia\Tui\Keys;
use Bia\Tui\Region;
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
        self::assertSame('⠦ Running shell… 5s · esc to stop', $spinner->render(80, now: 105.3, hint: 'esc to stop'));
        self::assertSame('⠹ Running shell… 1m 5s', $spinner->render(80, now: 165.0));
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
}
