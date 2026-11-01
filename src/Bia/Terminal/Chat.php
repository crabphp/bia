<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Agent\Agent;
use Bia\Config\Config;
use Bia\Contracts\Message;
use Bia\Contracts\SessionStore;
use Bia\Model\ModelCatalog;
use Bia\Tui\Editor;
use Bia\Tui\Fuzzy;
use Bia\Tui\Key;
use Bia\Tui\Markdown;
use Bia\Tui\Surface;
use Bia\Tui\SelectList;
use Bia\Tui\Spinner;
use Bia\Tui\Style;
use Bia\Tui\Terminal;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

/**
 * The interactive conversation: output scrolls, and below it the live
 * region holds the spinner, the input, a menu and the status line.
 *
 * Keys are read all the time, so the next message can be typed while a turn
 * runs; Enter then queues it. Esc or Ctrl-C stops the turn; Ctrl-C on an
 * empty input twice, or Ctrl-D, leaves.
 */
final class Chat
{
    private const PLACEHOLDER = 'Ask anything · / commands · @ files';

    /** Bia, the fat cat this is named for. */
    private const MASCOT = ['▙▄▄▄▄▄▟', '█▙███▟█'];

    private readonly Editor $editor;
    private readonly Commands $commands;
    private ?SelectList $menu = null;
    /** "command" and "file" complete the input; "model", "session" and "confirm" take every key. */
    private string $menuKind = '';
    private string $menuTitle = '';
    private int $replace = 0;
    private ?Spinner $spinner = null;
    private ?Cancellation $turn = null;
    /** @var list<string> */
    private array $queue = [];
    private ?Suspension $confirming = null;
    private string $notice = '';
    private float $noticeUntil = 0.0;
    private float $exitArmed = 0.0;
    private bool $running = true;
    private ?string $ticker = null;
    private ?int $contextTokens = null;
    private string $place;
    /** Whether the session has messages, so that leaving names it for `bia resume`. */
    private bool $used = false;

    /**
     * @param \Closure(Config): Agent $makeAgent Builds the agent for a model, as /model switches.
     */
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Surface $region,
        TuiRenderer $renderer,
        private Config $config,
        private readonly \Closure $makeAgent,
        private readonly SessionStore $sessions,
        private ?string $sessionId,
        private readonly string $system,
        private readonly string $workspace,
        private readonly Files $files,
        private readonly ModelCatalog $catalog,
        private readonly History $history,
        private readonly string $version,
        private ?Agent $agent = null,
        private readonly bool $pickSession = false,
    ) {
        $this->agent ??= ($this->makeAgent)($this->config);
        $this->editor = new Editor($this->history->load());
        $this->place = self::place($this->workspace);
        $renderer->listen(
            function (string $label): void {
                $this->spinner?->label($label);
            },
            function (array $usage): void {
                $total = $usage['total'] ?? null;
                $this->contextTokens = is_int($total) && $total > 0 ? $total : (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0);
            },
        );
        $this->commands = (new Commands())
            ->add('model', 'Switch model', $this->model(...), '[provider/model]')
            ->add('resume', 'Resume a session', $this->resume(...), '[session-id]')
            ->add('new', 'Start a new session', $this->newSession(...))
            ->add('compact', 'Summarize the conversation', function (string $_): void {
                if ($this->sessionId === null) {
                    $this->say('Nothing to compact');
                    return;
                }
                $this->work('Compacting', fn (Cancellation $c) => $this->agent?->compact((string) $this->sessionId, $c));
            })
            ->add('clear', 'Clear the screen', function (string $_): void {
                $this->region->clear(scrollback: true);
            })
            ->add('help', 'Commands and keys', $this->help(...))
            ->add('exit', 'Leave Bia', function (string $_): void {
                $this->running = false;
            }, aliases: ['quit']);
    }

    /**
     * Asks a yes/no question in the live region, from inside a turn, for a
     * tool whose permission is "ask".
     */
    public function confirm(string $question): bool
    {
        $suspension = EventLoop::current()->getSuspension();
        $this->confirming = $suspension;
        $this->openMenu('confirm', $question, new SelectList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ], filters: false));
        $this->draw();
        try {
            return (bool) $suspension->suspend();
        } finally {
            $this->confirming = null;
            $this->closeMenu();
            $this->draw();
        }
    }

    public function run(Cancellation $cancellation): void
    {
        Style::enable(getenv('NO_COLOR') === false);
        $this->terminal->enter();
        $loop = EventLoop::current();
        $resize = defined('SIGWINCH') && function_exists('pcntl_signal')
            ? $loop->onSignal(SIGWINCH, function (): void {
                $this->terminal->resized();
                $this->region->redraw();
                $this->draw();
            })
            : null;
        $this->region->print($this->banner(max(20, $this->terminal->columns())));
        if ($this->sessionId !== null) {
            $this->replay($this->sessionId);
        } elseif ($this->pickSession) {
            $this->resume('');
        }
        $this->draw();
        try {
            while ($this->running) {
                $key = $this->terminal->key($cancellation);
                if ($key === null) {
                    break;
                }
                $this->handle($key);
                if ($this->running) {
                    $this->draw();
                }
            }
        } finally {
            $this->turn?->cancel();
            $this->confirming?->resume(false);
            if ($resize !== null) {
                $loop->cancel($resize);
            }
            $this->stopTicker();
            $this->region->close();
            $this->terminal->leave();
            if ($this->used && $this->sessionId !== null) {
                $this->terminal->write("\n" . Style::accent("bia resume {$this->sessionId}") . "\n");
            }
        }
    }

    private function handle(Key $key): void
    {
        if ($key->is('wheel-up', 'wheel-down')) {
            $this->region->scroll($key->is('wheel-up') ? -3 : 3);
            return;
        }
        if ($key->is('mouse')) {
            return;
        }
        if ($key->is('pageup', 'pagedown') && $this->menu === null && $this->region->page() > 0) {
            $this->region->scroll(($key->is('pageup') ? -1 : 1) * $this->region->page());
            return;
        }
        if ($this->menuKind === 'confirm') {
            $answer = null;
            if ($key->is('char')) {
                $answer = ['y' => 'yes', 'n' => 'no'][strtolower($key->text)] ?? null;
            } else {
                $action = $this->menu?->key($key);
                $answer = $action === 'choose' ? ($this->menu?->selected()['value'] ?? 'no') : ($action === 'cancel' ? 'no' : null);
            }
            if ($answer !== null) {
                $this->confirming?->resume($answer === 'yes');
            }
            return;
        }
        if ($key->is('ctrl-c')) {
            $this->interrupt();
            return;
        }
        $this->exitArmed = 0.0;
        if ($key->is('ctrl-d') && $this->editor->empty() && $this->turn === null) {
            $this->running = false;
            return;
        }
        if ($key->is('escape')) {
            if ($this->menu !== null) {
                $this->closeMenu();
            } elseif ($this->turn !== null) {
                $this->turn->cancel();
            }
            return;
        }
        if ($key->is('ctrl-l')) {
            $this->region->clear();
            return;
        }
        if ($this->menuKind === 'model' || $this->menuKind === 'session') {
            $kind = $this->menuKind;
            $action = $this->menu?->key($key);
            if ($action === 'choose' && ($chosen = $this->menu?->selected()) !== null) {
                $this->closeMenu();
                $kind === 'model' ? $this->switchModel($chosen['value']) : $this->switchSession($chosen['value']);
            } elseif ($action === 'cancel') {
                $this->closeMenu();
            }
            return;
        }
        if ($this->menu !== null && in_array($this->menuKind, ['command', 'file'], true)) {
            if ($key->is('up', 'down', 'pageup', 'pagedown', 'shift-tab')) {
                $this->menu->key($key);
                return;
            }
            if ($key->is('tab', 'enter') && ($chosen = $this->menu->selected()) !== null) {
                $this->complete($chosen['value'], $key->is('enter'));
                return;
            }
        }
        if ($this->editor->key($key) === true) {
            $this->submit($this->editor->text());
            return;
        }
        $this->suggest();
    }

    private function interrupt(): void
    {
        if ($this->turn !== null) {
            $this->turn->cancel();
            return;
        }
        if ($this->menu !== null) {
            $this->closeMenu();
            return;
        }
        if (!$this->editor->empty()) {
            $this->editor->clear();
            return;
        }
        if (microtime(true) - $this->exitArmed < 2.0) {
            $this->running = false;
            return;
        }
        $this->exitArmed = microtime(true);
        $this->say('Ctrl-C again to leave', 2.0);
    }

    private function submit(string $text): void
    {
        $text = trim($text);
        $this->closeMenu();
        if ($text === '') {
            return;
        }
        $this->editor->remember($text);
        $this->history->add($text);
        $this->editor->clear();
        $this->region->follow();
        if (Commands::looksLikeOne($text)) {
            $name = strtolower((string) strtok(substr($text, 1), " \n"));
            if ($this->turn !== null && !in_array($name, ['exit', 'quit', 'help', 'clear'], true)) {
                $this->say("Busy · Esc to stop");
                return;
            }
            if (!$this->commands->run($text)) {
                $this->say("Unknown command /{$name}");
            }
            return;
        }
        if ($this->turn !== null) {
            $this->queue[] = $text;
            return;
        }
        $this->ask($text);
    }

    private function ask(string $text): void
    {
        // A session is made by its first message, so starting Bia leaves no empty ones.
        $sessionId = $this->sessionId ??= $this->sessions->create($this->workspace, $this->config->modelLabel());
        $this->used = true;
        $this->echo($text);
        $this->work('Thinking', fn (Cancellation $cancellation) => $this->agent?->run($sessionId, $text, $this->system, $cancellation));
    }

    /**
     * Runs a piece of agent work in the background with the spinner on, then
     * the next queued message.
     *
     * @param \Closure(Cancellation): mixed $work
     */
    private function work(string $label, \Closure $work): void
    {
        $cancellation = new Cancellation();
        $this->turn = $cancellation;
        $this->spinner = new Spinner($label);
        $this->startTicker();
        Task::spawn(EventLoop::current(), function () use ($work, $cancellation): void {
            try {
                $work($cancellation);
            } catch (CancelledException) {
                $this->lineStart();
                $this->region->print(Style::dim('· stopped') . "\n");
            } catch (\Throwable $error) {
                // One failed turn (a 429, a dropped connection) does not end the conversation.
                $this->lineStart();
                $this->region->print(Style::error('bia: ' . $error->getMessage()) . "\n");
            } finally {
                $this->turn = null;
                $this->spinner = null;
                $this->stopTicker();
                $this->lineStart();
                if ($this->running && $this->queue !== []) {
                    $this->ask(array_shift($this->queue));
                }
                $this->draw();
            }
        });
    }

    private function echo(string $text): void
    {
        $this->lineStart();
        // The user's words on a shaded background, a line at a time, set apart from the answer.
        $lines = explode("\n", $text);
        $shown = Style::bar(' › ' . array_shift($lines));
        foreach ($lines as $line) {
            $shown .= "\n" . Style::bar('   ' . $line);
        }
        $this->region->print("\n" . $shown . "\n\n");
    }

    private function model(string $name): void
    {
        if ($name !== '') {
            $this->switchModel($name);
            return;
        }
        $this->openMenu('model', 'Model', new SelectList([], 10, empty: 'Loading models…'));
        $current = $this->config->modelLabel();
        Task::spawn(EventLoop::current(), function () use ($current): void {
            $found = $this->catalog->list($this->config->providers());
            if ($this->menuKind !== 'model') {
                return;
            }
            $models = $found['models'];
            if (!in_array($current, $models, true)) {
                array_unshift($models, $current);
            }
            $items = array_map(static fn (string $model): array => [
                'value' => $model,
                'label' => $model,
                'hint' => $model === $current ? 'current' : '',
            ], $models);
            $title = 'Model';
            foreach ($found['errors'] as $provider => $error) {
                $title .= "  · {$provider}: {$error}";
            }
            $this->openMenu('model', $title, new SelectList($items, 10, $current, empty: 'No models'));
            $this->draw();
        });
    }

    private function switchModel(string $name): void
    {
        try {
            $config = $this->config->withModel($name);
            if ((string) ($config->get('model.api_key') ?? '') === '') {
                throw new \RuntimeException($config->keyHelp());
            }
            $this->agent = ($this->makeAgent)($config);
            $this->config = $config;
            $this->contextTokens = null;
            $this->lineStart();
            $this->region->print(Style::dim('· model: ' . $config->modelLabel()) . "\n");
        } catch (\Throwable $error) {
            $this->lineStart();
            $this->region->print(Style::error('bia: ' . $error->getMessage()) . "\n");
        }
    }

    private function newSession(string $_): void
    {
        $this->leaving();
        $this->sessionId = null;
        $this->used = false;
        $this->contextTokens = null;
        $this->lineStart();
        $this->region->print(Style::dim('· new session') . "\n");
    }

    private function resume(string $id): void
    {
        if ($id !== '') {
            $found = $this->sessions->resolve($id);
            if ($found === null) {
                $this->say("No session {$id}");
                return;
            }
            $this->switchSession($found);
            return;
        }
        $items = [];
        $now = time();
        foreach ($this->sessions->list(200, $this->workspace) as $session) {
            if ($session['entry_count'] === 0 || $session['id'] === $this->sessionId) {
                continue;
            }
            $title = trim((string) preg_replace('/\s+/', ' ', (string) ($session['title'] ?? '')));
            $items[] = [
                'value' => (string) $session['id'],
                'label' => $title !== '' ? $title : '(forked, ' . substr((string) $session['id'], 0, 8) . ')',
                'hint' => self::ago($now - (int) strtotime((string) $session['updated_at'])) . ' · ' . $session['model'],
            ];
        }
        $this->openMenu('session', 'Resume', new SelectList($items, 10, empty: 'No other sessions'));
    }

    private function switchSession(string $id): void
    {
        $this->leaving();
        $this->sessionId = $id;
        $this->used = true;
        $this->contextTokens = null;
        $this->lineStart();
        $this->region->print("\n" . Style::dim("· resumed {$id}") . "\n");
        $this->replay($id);
    }

    /**
     * Before the conversation moves to another session, says how to come back to this one.
     */
    private function leaving(): void
    {
        if ($this->used && $this->sessionId !== null) {
            $this->lineStart();
            $this->region->print(Style::dim("· left {$this->sessionId}") . "\n");
        }
    }

    /**
     * Shows the end of a session's conversation, as it was seen.
     */
    private function replay(string $id, int $last = 40): void
    {
        $entries = $this->sessions->path($id);
        if ($entries === []) {
            return;
        }
        $this->used = true;
        $results = [];
        foreach ($entries as $entry) {
            if ($entry->payload instanceof Message && $entry->payload->role === 'tool') {
                $results[$entry->payload->toolCallId] = $entry->payload;
            }
        }
        $shown = array_slice($entries, -$last);
        if (count($entries) > count($shown)) {
            $this->region->print(Style::dim('· ' . (count($entries) - count($shown)) . ' earlier entries') . "\n");
        }
        foreach ($shown as $entry) {
            $payload = $entry->payload;
            if (!$payload instanceof Message) {
                $this->lineStart();
                $this->region->print(Style::dim('· the conversation before here was summarized') . "\n");
                continue;
            }
            if ($payload->role === 'user') {
                $this->echo($payload->text);
            } elseif ($payload->role === 'assistant') {
                if ($payload->text !== '') {
                    $markdown = new Markdown();
                    foreach (explode("\n", rtrim($payload->text, "\n")) as $line) {
                        $this->region->print($markdown->line($line) . "\n");
                    }
                }
                foreach ($payload->toolCalls as $call) {
                    $result = $results[$call->id] ?? null;
                    if ($result === null) {
                        $subject = AnsiRenderer::subject($call->arguments);
                        $this->region->print(Style::dim('·') . " {$call->name}" . ($subject !== '' ? ' ' . Style::dim($subject) : '') . "\n");
                        continue;
                    }
                    $data = is_array($result->meta['data'] ?? null) ? $result->meta['data'] : [];
                    foreach (TuiRenderer::toolLines($call, $result->ok, $result->text, $data) as $line) {
                        $this->region->print($line . "\n");
                    }
                }
                $usage = $payload->meta['usage'] ?? null;
                if (is_array($usage)) {
                    $total = (int) ($usage['total'] ?? 0);
                    $this->contextTokens = $total > 0 ? $total : (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0);
                }
            }
        }
        $this->lineStart();
    }

    private function help(string $_): void
    {
        $keys = <<<'TEXT'
            Enter send · Ctrl-J or Alt-Enter or \ then Enter: new line · Up/Down history
            Tab complete · Esc stop the turn or close a menu · Ctrl-C stop, clear, or twice to leave
            Ctrl-W delete word · Ctrl-U delete to line start · Ctrl-L clear screen · Ctrl-D leave
            Type while Bia works: Enter queues the message for after the turn.
            TEXT;
        if ($this->region->page() > 0) {
            $keys .= "\nWheel or PageUp/PageDown scroll · Shift-drag selects text";
        }
        $this->lineStart();
        $this->region->print(Style::dim($this->commands->help() . "\n\n" . $keys) . "\n");
    }

    /**
     * Opens the completion menu that fits what is before the cursor: commands
     * after a leading slash, files after an @.
     */
    private function suggest(): void
    {
        if (!in_array($this->menuKind, ['', 'command', 'file'], true)) {
            return;
        }
        $text = $this->editor->text();
        $before = $this->editor->beforeCursor();
        if (!str_contains($text, "\n") && preg_match('#^/[\w-]*$#', $text) === 1 && $before === $text) {
            $items = $this->commands->matching($text);
            if ($items === [] || ($items[0]['value'] === $text && count($items) === 1 && !$this->commands->takesArguments($text))) {
                $this->closeMenu();
                return;
            }
            $this->replace = mb_strlen($before, 'UTF-8');
            $this->openMenu('command', '', new SelectList($items, 8, filters: false));
            return;
        }
        if (preg_match('/(?:^|\s)@(\S*)$/u', $before, $match) === 1) {
            $this->replace = mb_strlen($match[1], 'UTF-8') + 1;
            $files = $this->files->get(function (): void {
                $this->suggest();
                $this->draw();
            });
            if ($files === null) {
                $this->openMenu('file', '', new SelectList([], empty: 'Listing files…', filters: false));
                return;
            }
            $found = $match[1] === '' ? array_slice($files, 0, 50) : Fuzzy::sort($match[1], $files, static fn (string $file): string => $file, 50);
            $this->openMenu('file', '', new SelectList(
                array_map(static fn (string $file): array => ['value' => $file], $found),
                8,
                filters: false,
                empty: 'No file matches',
            ));
            return;
        }
        $this->closeMenu();
    }

    private function complete(string $value, bool $enter): void
    {
        if ($this->menuKind === 'command') {
            $this->closeMenu();
            if ($enter) {
                $this->editor->set($value);
                $this->submit($value);
                return;
            }
            $this->editor->replaceBefore($this->replace, $value . ' ');
            return;
        }
        $this->editor->replaceBefore($this->replace, '@' . $value . ' ');
        $this->closeMenu();
    }

    private function openMenu(string $kind, string $title, SelectList $menu): void
    {
        $this->menuKind = $kind;
        $this->menuTitle = $title;
        $this->menu = $menu;
    }

    private function closeMenu(): void
    {
        $this->menu = null;
        $this->menuKind = '';
        $this->menuTitle = '';
    }

    private function say(string $notice, float $seconds = 4.0): void
    {
        $this->notice = $notice;
        $this->noticeUntil = microtime(true) + $seconds;
        EventLoop::current()->delay($seconds + 0.05, fn () => $this->draw());
    }

    private function draw(): void
    {
        $width = max(20, $this->terminal->columns());
        $lines = [];
        if ($this->spinner !== null) {
            $lines[] = $this->spinner->render($width, hint: 'esc to stop');
        }
        foreach ($this->queue as $queued) {
            $lines[] = Style::dim(Style::truncate('  queued: ' . strtok($queued, "\n"), $width));
        }
        $rule = Style::dim(str_repeat('─', $width));
        $cursor = null;
        if (in_array($this->menuKind, ['confirm', 'model', 'session'], true)) {
            $lines[] = $rule;
            if ($this->menuKind === 'confirm') {
                $lines[] = Style::warn(Style::truncate($this->menuTitle, $width));
                array_push($lines, ...($this->menu?->render($width) ?? []));
            } else {
                array_push($lines, ...($this->menu?->render($width, $this->menuTitle) ?? []));
            }
        } else {
            [$rows, [$row, $column]] = $this->editor->render($width, self::PLACEHOLDER);
            // Suggestions open above the input, so the input does not move as they come and go.
            if ($this->menu !== null) {
                array_push($lines, ...$this->menu->render($width));
            }
            $lines[] = $rule;
            $cursor = [count($lines) + $row, $column];
            array_push($lines, ...$rows);
            $lines[] = $rule;
        }
        $lines[] = $this->status($width);
        $this->region->set($lines, $cursor);
    }

    /**
     * The greeting: Bia the cat beside the version, model and place.
     */
    private function banner(int $width): string
    {
        $room = max(0, $width - 10);
        $top = Style::bold('bia') . ' ' . Style::dim(Style::truncate("{$this->version} · {$this->config->modelLabel()}", max(0, $room - 4)));
        $bottom = Style::dim(Style::truncate($this->place, $room));
        return Style::accent(self::MASCOT[0]) . '   ' . $top . "\n"
            . Style::accent(self::MASCOT[1]) . '   ' . $bottom . "\n\n";
    }

    private function status(int $width): string
    {
        $right = $this->config->modelLabel();
        $window = (int) $this->config->get('model.context_window');
        if ($this->contextTokens !== null && $window > 0) {
            $right .= ' · ' . self::thousands($this->contextTokens) . '/' . self::thousands($window)
                . ' (' . (int) round($this->contextTokens * 100 / $window) . '%)';
        }
        if ($this->notice !== '' && microtime(true) < $this->noticeUntil) {
            return Style::warn(Style::truncate($this->notice, $width));
        }
        $left = Style::truncate($this->place, max(0, $width - mb_strwidth($right, 'UTF-8') - 2));
        $gap = max(1, $width - mb_strwidth($left, 'UTF-8') - mb_strwidth($right, 'UTF-8'));
        return Style::dim(Style::truncate($left . str_repeat(' ', $gap) . $right, $width));
    }

    private function startTicker(): void
    {
        $this->ticker ??= EventLoop::current()->repeat(0.08, fn () => $this->draw());
    }

    private function stopTicker(): void
    {
        if ($this->ticker !== null) {
            EventLoop::current()->cancel($this->ticker);
            $this->ticker = null;
        }
    }

    private function lineStart(): void
    {
        if (!$this->region->atLineStart()) {
            $this->region->print("\n");
        }
    }

    /**
     * The workspace as the status line shows it: ~ for home, and its git branch.
     */
    private static function place(string $workspace): string
    {
        $home = (string) getenv('HOME');
        $place = $home !== '' && str_starts_with($workspace, $home) ? '~' . substr($workspace, strlen($home)) : $workspace;
        for ($directory = $workspace; ; $directory = dirname($directory)) {
            $head = @file_get_contents($directory . '/.git/HEAD');
            if ($head !== false) {
                if (preg_match('#^ref: refs/heads/(.+)$#m', $head, $match) === 1) {
                    $place .= ' (' . trim($match[1]) . ')';
                }
                break;
            }
            if ($directory === dirname($directory)) {
                break;
            }
        }
        return $place;
    }

    private static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ago',
            default => intdiv($seconds, 86400) . 'd ago',
        };
    }

    private static function thousands(int $tokens): string
    {
        return $tokens >= 1000 ? round($tokens / 1000) . 'k' : (string) $tokens;
    }
}
