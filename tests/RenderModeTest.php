<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Terminal\TuiRenderer;
use Bia\Tui\Effects;
use Bia\Tui\Pacer;
use Bia\Tui\Screen;
use Bia\Tui\Style;
use Bia\Tui\Terminal;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class RenderModeTest extends TestCase
{
    protected function tearDown(): void
    {
        Style::enable(false);
    }

    public function testThePacerEvensTextOutAndCatchesUp(): void
    {
        $pacer = new Pacer();
        $pacer->push('héllo world');
        self::assertSame('', $pacer->take(0.001), 'Not a whole character due yet.');
        self::assertSame('hé', $pacer->take(1 / 60), 'A frame gives a few characters, whole ones.');

        $pacer->drain();
        $pacer->push(str_repeat('x', 600));
        self::assertSame(40, strlen($pacer->take(1 / 60)), 'Much waiting goes faster: all of it within a quarter second.');
        self::assertSame(560, strlen($pacer->drain()));
        self::assertTrue($pacer->empty());
    }

    public function testPlaceDrawsOverOneColumnAndKeepsTheStyleAround(): void
    {
        $row = " \e[36mhello\e[39m world";
        self::assertSame(' heXlo world', Style::strip(Style::place($row, 3, 'X')));
        self::assertStringEndsWith("\e[36mlo\e[39m world", Style::place($row, 3, 'X'), 'The style goes on after the sprite.');
        self::assertSame('ab   *', Style::strip(Style::place('ab', 5, '*')), 'Past the end, it is padded to.');
    }

    public function testACharacterDropsLandsWithAFlashAndTheEffectsEnd(): void
    {
        Style::enable(true);
        $effects = new Effects();
        $effects->burst('ab', 100.0);
        $rows = array_fill(0, 9, '');
        $rows[] = ' xab';
        $falling = $effects->apply($rows, 9, 4, 100.15);
        self::assertStringNotContainsString('a', Style::strip($falling[9]), 'In the air, its place is empty.');
        $above = implode('', array_map(static fn (string $row): string => Style::strip($row), array_slice($falling, 0, 9)));
        self::assertStringContainsString('a', $above, 'It is rows above, falling.');

        $shaking = $effects->apply($rows, 9, 4, 100.225);
        self::assertSame(' x ab', rtrim(Style::strip($shaking[9])), 'Just landed, what landed together shakes a column aside, together; the text before it stays.');

        $landed = $effects->apply($rows, 9, 4, 100.30);
        self::assertSame(' xab', Style::strip($landed[9]), 'Landed, it is in its place.');
        self::assertStringContainsString("\e[1;38;5;214ma", $landed[9], 'With a flash where it hit.');

        self::assertFalse($effects->active(101.0), 'A second on, everything has settled.');
        self::assertSame($rows, $effects->apply($rows, 9, 4, 101.0));
    }

    public function testSmoothTextArrivesOverFramesAndIsCaughtUpBeforeAnythingElse(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
        $output = fopen('php://memory', 'w+');
        $screen = new Screen(new Terminal(STDIN, $output, [60, 6]));
        $renderer = new TuiRenderer($screen);
        $renderer->mode('power');
        $renderer->event(ModelEvent::text(str_repeat('word ', 30) . "\n"));
        $screen->flush();
        self::assertStringNotContainsString('word', self::written($output), 'Paced: nothing yet before a frame.');
        $renderer->finish(new ModelResult(''));
        $screen->flush();
        self::assertStringContainsString('word word', self::written($output), 'Finishing shows what waited, at once.');
        $this->expectException(\InvalidArgumentException::class);
        $renderer->mode('loud');
    }

    private static function written(mixed $output): string
    {
        rewind($output);
        return (string) stream_get_contents($output);
    }
}
