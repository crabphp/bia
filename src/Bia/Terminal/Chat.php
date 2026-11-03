<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Agent\Agent;
use Bia\Agent\Inspection;
use Bia\Config\Config;
use Bia\Contracts\Image;
use Bia\Contracts\Message;
use Bia\Contracts\ToolResult;
use Bia\Contracts\SessionStore;
use Bia\Model\ModelCatalog;
use Bia\Tui\Editor;
use Bia\Tui\Fuzzy;
use Bia\Tui\Key;
use Bia\Tui\Markdown;
use Bia\Tui\Region;
use Bia\Tui\Surface;
use Bia\Tui\SelectList;
use Bia\Tui\Spinner;
use Bia\Tui\Screen;
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
 * Keys are read all the time, so a message can be typed while a turn runs;
 * Enter then steers it: the message joins the conversation at Bia's next
 * step, never waiting for the turn to end. Esc or Ctrl-C stops the turn;
 * Ctrl-C on an empty input twice, or Ctrl-D, leaves.
 */
final class Chat
{
    /** @var array<int, Image> The images pasted, by the number of their [Image #N] in the input. */
    private array $attached = [];
    /** The last image's number: never used again in a conversation, so a chip always means one image. */
    private int $attachments = 0;

    /** Whether Ctrl-O opened every tool call's output. */
    private bool $opened = false;

    /**
     * Bia, the fat cat this is named for, all amber: the working beer's
     * colour. Her ears are the thin raised corners, her eyes the two gaps
     * above her face.
     */
    private const MASCOT = ['▙▄▄▄▄▄▟', '█▄███▄█'];
    private const MASCOT_COLOUR = 214;
    /** Her face with her eyes shut: the gaps filled. */
    private const MASCOT_BLINK = '███████';
    /** Seconds after the banner of each blink: one. */
    private const BLINKS = [0.6];
    /** Seconds her eyes stay shut. */
    private const BLINK = 0.14;

    /** The banner's line with the mascot's eyes, and the timers blinking them. */
    private ?int $bannerLine = null;
    /** @var list<string> */
    private array $blinking = [];
    /** Whether the mascot has blinked: she does once, at the start. */
    private bool $blinked = false;
    private int $bannerWidth = 0;

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
    private array $steering = [];
    private ?Suspension $confirming = null;
    private string $notice = '';
    private float $noticeUntil = 0.0;
    private float $exitArmed = 0.0;
    private bool $reload = false;
    private bool $running = true;
    private ?string $ticker = null;
    private ?int $contextTokens = null;
    private string $place;
    /** Whether the session has messages, so that leaving names it for `bia resume`. */
    private bool $used = false;
    /** Counts as things happen, shown or not, so turning /stats on shows the whole session. */
    private readonly Stats $stats;
    private bool $showStats = false;
    private ?string $statsTicker = null;

    /**
     * @param \Closure(Config): Agent $makeAgent Builds the agent for a model, as /model switches.
     */
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Surface $region,
        private readonly TuiRenderer $renderer,
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
        /** @var array{manager: ?\Bia\Mcp\Manager, untrusted: ?string, project: list<\Bia\Mcp\Server>, errors: list<string>, trust: \Bia\Mcp\Trust, open: \Closure(string): void}|null */
        private readonly ?array $mcp = null,
        private readonly ?\Bia\Skill\Catalog $skills = null,
        private readonly ?Images $images = null,
        private readonly bool $reloaded = false,
        private readonly ?Inspection $inspection = null,
    ) {
        $this->agent ??= ($this->makeAgent)($this->config);
        $this->stats = new Stats();
        $this->editor = new Editor($this->history->load());
        $this->place = self::place($this->workspace);
        $renderer->listen(
            function (string $label): void {
                $this->spinner?->label($label);
            },
            function (array $usage): void {
                $total = $usage['total'] ?? null;
                $this->contextTokens = is_int($total) && $total > 0 ? $total : (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0);
                $this->stats->usage($usage);
            },
            function (int $bytes): void {
                $this->stats->streamed($bytes);
            },
        );
        $this->commands = (new Commands())
            ->add('model', 'Switch model', $this->model(...), '[provider/model]')
            ->add('effort', 'Reasoning effort', $this->effort(...), '[' . implode('|', Config::EFFORTS) . ']')
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
            ->add('stats', 'Show or hide live stats', function (string $_): void {
                $this->showStats = !$this->showStats;
                if ($this->showStats) {
                    // Kept current while idle too, once a second.
                    $this->statsTicker ??= EventLoop::current()->repeat(1.0, fn () => $this->draw());
                } else {
                    if ($this->statsTicker !== null) {
                        EventLoop::current()->cancel($this->statsTicker);
                        $this->statsTicker = null;
                    }
                    $this->region->overlay([]);
                }
            })
            ->add('inspect', 'What the last request sent and got', $this->inspect(...))
            ->add('mcp', 'MCP servers and their tools', $this->showMcp(...), '[login|logout <server>]')
            ->add('render', 'How answers appear', $this->render(...), '[normal|smooth|power]')
            ->add('help', 'Commands and keys', $this->help(...))
            ->add('reload', 'Restart Bia, keeping the session', function (string $_): void {
                $this->reload = true;
                $this->running = false;
            })
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
        $this->report('blocked', 'permission');
        try {
            return (bool) $suspension->suspend();
        } finally {
            $this->confirming = null;
            $this->closeMenu();
            $this->draw();
            $this->report('working');
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
        if ($this->reloaded && $this->region instanceof Region) {
            // Inline, the banner and the conversation are still on the terminal, as the old Bia left them.
            $this->note('Reloaded');
        } else {
            $this->greet();
            if ($this->sessionId !== null) {
                $this->replay($this->sessionId);
            } elseif ($this->pickSession) {
                $this->resume('');
            }
            if ($this->reloaded) {
                $this->note('Reloaded');
            }
        }
        $this->draw();
        $this->report('idle');
        // After the first screen: no server keeps Bia from being ready.
        Task::spawn(EventLoop::current(), $this->startMcp(...));
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
            $this->stopBlinking();
            if ($resize !== null) {
                $loop->cancel($resize);
            }
            $this->stopTicker();
            if ($this->statsTicker !== null) {
                $loop->cancel($this->statsTicker);
            }
            $this->mcp['manager']?->close(); // the servers Bia started end with it
            $this->region->close();
            $this->terminal->leave();
            if ($this->used && $this->sessionId !== null && !$this->reload) {
                $this->terminal->write("\n" . Style::accent("bia resume {$this->sessionId}") . "\n");
            }
        }
    }

    /**
     * What to start again with when the conversation ended for /reload: its
     * session, model and effort; null when it ended for good.
     *
     * @return array{session: ?string, model: string, effort: string}|null
     */
    public function reloading(): ?array
    {
        return $this->reload ? [
            'session' => $this->sessionId,
            'model' => $this->config->modelLabel(),
            'effort' => (string) $this->config->get('model.reasoning_effort', 'default'),
        ] : null;
    }

    private function handle(Key $key): void
    {
        if ($key->is('wheel-up', 'wheel-down')) {
            $this->region->scroll($key->is('wheel-up') ? -3 : 3);
            return;
        }
        if ($key->is('mouse-down', 'mouse-drag', 'mouse-up')) {
            [$column, $row] = array_map('intval', explode(',', $key->text) + [1 => '0']);
            $copied = $this->region->pointer(substr($key->name, 6), $column, $row);
            if ($copied !== null && $copied !== '') {
                // The terminal is asked to copy; whether it did, nothing says. Shift+drag is its own selection.
                $this->say('Copied (OSC 52) · Shift+drag if not', 3.0);
            }
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
        if ($key->is('ctrl-o')) {
            // Every tool call's output, opened or closed again.
            $this->opened = !$this->opened;
            $this->region->folds($this->opened);
            return;
        }
        if (in_array($this->menuKind, ['model', 'session', 'effort'], true)) {
            $kind = $this->menuKind;
            $action = $this->menu?->key($key);
            if ($action === 'choose' && ($chosen = $this->menu?->selected()) !== null) {
                $this->closeMenu();
                match ($kind) {
                    'model' => $this->switchModel($chosen['value']),
                    'effort' => $this->switchEffort($chosen['value']),
                    default => $this->switchSession($chosen['value']),
                };
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
        if ($this->images !== null && $key->is('ctrl-v')) {
            $this->pasteImage();
            return;
        }
        if ($this->images !== null && $key->is('paste') && ($found = $this->images->fromPaste($key->text)) !== null) {
            // An image file dropped on the terminal, or its path pasted: the image, not the path.
            foreach ($found as $image) {
                $this->attach($image);
            }
            return;
        }
        if ($this->editor->key($key) === true) {
            $this->submit($this->editor->text());
            return;
        }
        $this->suggest();
    }

    /**
     * Ctrl-V: the clipboard's image, as Claude Code and Codex take it.
     */
    private function pasteImage(): void
    {
        $images = $this->images;
        Task::spawn(EventLoop::current(), function () use ($images): void {
            $found = $images?->fromClipboard();
            if ($found instanceof Image) {
                $this->attach($found);
            } elseif (is_string($found)) {
                $this->say($found, 4.0);
                $this->draw(); // read in the background, after the key's own drawing
            }
        });
    }

    /**
     * An image in the input, as a chip that names it.
     */
    private function attach(Image $image): void
    {
        $number = ++$this->attachments;
        $this->attached[$number] = $image;
        $this->editor->key(new Key('paste', "[Image #{$number}] "));
        $this->draw();
    }

    /**
     * The images a message's chips name, in their order; a chip deleted from it, its image gone with it.
     *
     * @return list<Image>
     */
    private function imagesIn(string $text): array
    {
        preg_match_all('/\[Image #(\d+)\]/', $text, $chips);
        $images = [];
        foreach (array_unique($chips[1]) as $number) {
            if (isset($this->attached[(int) $number])) {
                $images[] = $this->attached[(int) $number];
            }
        }
        return $images;
    }

    /**
     * Ctrl-C undoes the nearest thing: what is typed first, even while Bia
     * works, then a menu, then the turn, and twice on nothing, Bia itself.
     */
    private function interrupt(): void
    {
        if (!$this->editor->empty()) {
            $this->editor->clear();
            $this->closeMenu();
            return;
        }
        if ($this->menu !== null) {
            $this->closeMenu();
            return;
        }
        if ($this->turn !== null) {
            $this->turn->cancel();
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
        // The address a login was sent back to, pasted: it carries a code, so it goes
        // to the login alone, never to the model or the history.
        $login = $this->mcp['manager'] ?? null;
        if ($login !== null && $login->waitingLogin() !== null && str_starts_with($text, $login->waitingLogin())) {
            $this->editor->clear();
            $login->deliver($text);
            return;
        }
        $this->editor->remember($text);
        $this->history->add($text);
        $this->editor->clear();
        $this->region->follow();
        if (Commands::looksLikeOne($text)) {
            $name = strtolower((string) strtok(substr($text, 1), " \n"));
            $skill = $this->commands->has($name) ? null : ($this->skills?->all()[(string) strtok(substr($text, 1), " \n")] ?? null);
            if ($skill !== null) {
                // /name runs a skill: a message asking for it, with what was written after it.
                $rest = trim((string) substr($text, strlen($skill->name) + 1));
                $text = "Use the {$skill->name} skill." . ($rest !== '' ? "\n\n{$rest}" : '');
                $this->turn !== null ? $this->steering[] = $text : $this->ask($text);
                return;
            }
            if ($this->turn !== null && !in_array($name, ['exit', 'quit', 'help', 'clear', 'stats', 'mcp'], true)) {
                $this->say("Busy · Esc to stop");
                return;
            }
            if (!$this->commands->run($text)) {
                $this->say("Unknown command /{$name}");
            }
            return;
        }
        if ($this->turn !== null) {
            // Bia is working: the message steers it, at its next step.
            $this->steering[] = $text;
            return;
        }
        $this->ask($text);
    }

    private function ask(string $text): void
    {
        // A session is made by its first message, so starting Bia leaves no empty ones.
        $sessionId = $this->sessionId ??= $this->sessions->create($this->workspace, $this->config->modelLabel());
        $this->used = true;
        // A turn stopped or failed is left as it was until the next one starts: then it collapses too.
        $this->renderer->collapse();
        $this->echo($text);
        $images = $this->imagesIn($text);
        $this->work('Thinking', fn (Cancellation $cancellation) => $this->agent?->run($sessionId, $text, $this->system, $cancellation, $this->steer(...), $images));
    }

    /**
     * What the user wrote while Bia worked, handed to the agent at a step:
     * shown where it joins the conversation.
     *
     * @return list<string|Message>
     */
    private function steer(): array
    {
        $messages = $this->steering;
        $this->steering = [];
        if ($messages !== []) {
            // A message starts the turn afresh: what was done before it collapses, and it is not hidden with it.
            $this->renderer->collapse();
        }
        foreach ($messages as $message) {
            $this->echo($message);
        }
        if ($messages !== []) {
            $this->draw();
        }
        return array_map(fn (string $text): string|Message => ($images = $this->imagesIn($text)) !== [] ? Message::user($text, $images) : $text, $messages);
    }

    /**
     * Runs a piece of agent work in the background with the spinner on.
     * What was written for it and not handed over by its end is sent next;
     * after a stop or a failure, it goes back to the input instead.
     *
     * @param \Closure(Cancellation): mixed $work
     */
    private function work(string $label, \Closure $work): void
    {
        $cancellation = new Cancellation();
        $this->turn = $cancellation;
        $this->spinner = new Spinner($label);
        $this->stats->turnStarted();
        $this->startTicker();
        $this->report('working');
        Task::spawn(EventLoop::current(), function () use ($work, $cancellation): void {
            $finished = false;
            $state = 'idle';
            try {
                $work($cancellation);
                $finished = true;
                $state = 'done';
            } catch (CancelledException) {
                $this->renderer->catchUp();
                $this->region->gap();
                $this->region->print(Style::dim('· stopped') . "\n");
            } catch (\Throwable $error) {
                // One failed turn (a 429, a dropped connection) does not end the conversation.
                $this->renderer->catchUp();
                $this->region->gap();
                $this->region->print(Style::error('bia: ' . $error->getMessage()) . "\n");
                $state = 'error';
            } finally {
                $this->turn = null;
                $this->spinner = null;
                $this->stopTicker();
                $this->lineStart();
                $left = implode("\n\n", $this->steering);
                $this->steering = [];
                if ($left !== '' && $this->running) {
                    if ($finished) {
                        $this->ask($left); // Bia had no step left to take it at: it goes on with it
                    } else {
                        $draft = $this->editor->text();
                        $this->editor->set($draft === '' ? $left : $left . "\n\n" . $draft);
                    }
                }
                $this->draw();
                if ($this->turn === null) {
                    $this->report($state); // unless what was left started another turn
                }
            }
        });
    }

    /**
     * What Bia is doing, for a terminal or multiplexer that shows it (OSC 7501).
     */
    private function report(string $state, ?string $kind = null): void
    {
        $this->terminal->status($state, ['app' => 'bia'] + ($kind === null ? [] : ['kind' => $kind]));
    }

    private function echo(string $text): void
    {
        $this->region->gap();
        // The user's words on a shaded background, a line at a time, set apart from the answer.
        $lines = explode("\n", $text);
        $shown = Style::bar('› ' . array_shift($lines));
        foreach ($lines as $line) {
            $shown .= "\n" . Style::bar('  ' . $line);
        }
        if (Style::color()) {
            // A shaded row above and below, to give the words room; without colour they would only be blank.
            $shown = Style::bar('') . "\n" . $shown . "\n" . Style::bar('');
        }
        $this->region->print($shown . "\n");
        $this->region->gap();
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
            // The next conversation starts with it too.
            Config::remember('model.name', $config->modelLabel());
            $this->region->gap();
            $this->region->print(Style::dim('· model: ' . $config->modelLabel()) . "\n");
        } catch (\Throwable $error) {
            $this->region->gap();
            $this->region->print(Style::error('bia: ' . $error->getMessage()) . "\n");
        }
    }

    private function effort(string $effort): void
    {
        $effort = strtolower(trim($effort));
        if ($effort !== '') {
            $this->switchEffort($effort);
            return;
        }
        $current = (string) $this->config->get('model.reasoning_effort', 'default');
        $items = array_map(static fn (string $effort): array => [
            'value' => $effort,
            'label' => $effort,
            'hint' => $effort === $current ? 'current' : ($effort === 'default' ? "the provider's" : ''),
        ], Config::EFFORTS);
        $this->openMenu('effort', 'Reasoning effort', new SelectList($items, 10, $current));
    }

    private function switchEffort(string $effort): void
    {
        if (!in_array($effort, Config::EFFORTS, true)) {
            $this->note('effort: one of ' . implode(', ', Config::EFFORTS));
            return;
        }
        $config = $this->config->withEffort($effort);
        $this->agent = ($this->makeAgent)($config);
        $this->config = $config;
        try {
            Config::remember('model.reasoning_effort', $effort);
            $this->note("effort: {$effort}");
        } catch (\RuntimeException $error) {
            $this->note("effort: {$effort}, for now · " . $error->getMessage());
        }
    }

    /**
     * The model as shown: its label, and the reasoning effort when one is set.
     */
    private function modelShown(): string
    {
        $effort = $this->config->effort();
        return $this->config->modelLabel() . ($effort !== null ? " · {$effort}" : '');
    }

    /**
     * Starts afresh: the screen and its scrollback cleared, the banner, and
     * how to come back to the session left.
     */
    private function newSession(string $_): void
    {
        $left = $this->used ? $this->sessionId : null;
        $this->renderer->collapse();
        $this->sessionId = null;
        $this->used = false;
        $this->contextTokens = null;
        $this->region->clear(scrollback: true);
        $this->greet();
        $this->region->print(Style::dim('· new session' . ($left !== null ? " · /resume {$left} to go back" : '')) . "\n");
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
        $this->region->gap();
        $this->region->print(Style::dim("· resumed {$id}") . "\n");
        $this->replay($id);
    }

    /**
     * Before the conversation moves to another session, says how to come back to this one.
     */
    private function leaving(): void
    {
        if ($this->used && $this->sessionId !== null) {
            $this->region->gap();
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
        // Calls that worked in a row are one line, as they were shown.
        $gathered = new Gathered();
        $settle = function () use (&$gathered): void {
            if (!$gathered->empty()) {
                $this->region->fold($gathered->closed(), $gathered->lines());
                $gathered = new Gathered();
            }
        };
        // Each run of tool calls, collapsed into its one line, as a finished turn's is.
        $run = null;
        $closeRun = function () use (&$run, $settle): void {
            $settle();
            if ($run !== null && $run['calls'] !== []) {
                $this->region->collapse($run['from'], $this->region->here(), Summary::line($run['calls']));
            }
            $run = null;
        };
        // As it was shown live: a blank line between the answer's text and a run of tool calls.
        $kind = null;
        $begin = function (string $next) use (&$kind, &$run, $closeRun): void {
            if ($kind !== $next) {
                if ($kind === 'work') {
                    $closeRun();
                }
                $this->region->gap();
                $kind = $next;
                if ($next === 'work') {
                    $run = ['from' => $this->region->here(), 'calls' => []];
                }
            }
        };
        foreach ($shown as $entry) {
            $payload = $entry->payload;
            if ($payload instanceof Message && $payload->role === 'tool') {
                continue;
            }
            if (!$payload instanceof Message || $payload->role === 'user' || $payload->text !== '') {
                $settle();
            }
            if (!$payload instanceof Message) {
                $begin('note');
                $this->region->print(Style::dim('· the conversation before here was summarized') . "\n");
                continue;
            }
            if ($payload->role === 'user') {
                if ($kind === 'work') {
                    $closeRun();
                }
                $this->echo($payload->text);
                $kind = 'user';
            } elseif ($payload->role === 'assistant') {
                if (trim($payload->text) !== '') {
                    $begin('text');
                    $markdown = new Markdown($this->region->width());
                    foreach (explode("\n", trim($payload->text, "\n")) as $line) {
                        foreach ($markdown->line($line) as $done) {
                            $this->region->print($done . "\n");
                        }
                    }
                    foreach ($markdown->finish() as $done) {
                        $this->region->print($done . "\n");
                    }
                }
                foreach ($payload->toolCalls as $call) {
                    $begin('work');
                    $result = $results[$call->id] ?? null;
                    if ($result === null) {
                        $settle();
                        $subject = AnsiRenderer::subject($call->arguments);
                        $this->region->print(Style::dim('·') . " {$call->name}" . ($subject !== '' ? ' ' . Style::dim($subject) : '') . "\n");
                        continue;
                    }
                    $data = is_array($result->meta['data'] ?? null) ? $result->meta['data'] : [];
                    $toolResult = new ToolResult($result->text, $result->ok, $data);
                    $run['calls'][] = [$call, $toolResult];
                    if (Gathered::takes($call, $toolResult)) {
                        $gathered->add($call, $toolResult);
                        continue;
                    }
                    $settle();
                    $this->region->fold(
                        implode("\n", TuiRenderer::toolLines($call, $result->ok, $result->text, $data)),
                        implode("\n", TuiRenderer::toolLines($call, $result->ok, $result->text, $data, open: true)),
                    );
                }
                $usage = $payload->meta['usage'] ?? null;
                if (is_array($usage)) {
                    $total = (int) ($usage['total'] ?? 0);
                    $this->contextTokens = $total > 0 ? $total : (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0);
                }
            }
        }
        if ($kind === 'work') {
            $closeRun();
        }
        $settle();
        $this->lineStart();
    }

    /**
     * Connects the MCP servers, in the background: a project's, from a
     * .mcp.json not trusted as it is, only once the user says so.
     */
    private function startMcp(): void
    {
        $manager = $this->mcp['manager'] ?? null;
        if ($manager === null) {
            return;
        }
        foreach ($this->mcp['errors'] as $error) {
            $this->note("mcp.json: {$error}");
        }
        $file = $this->mcp['untrusted'];
        if ($file !== null) {
            $names = implode(', ', array_map(static fn (\Bia\Mcp\Server $server): string => $server->name, $this->mcp['project']));
            if ($this->confirm("Start this project's MCP servers ({$names})? They run programs from .mcp.json.")) {
                $this->mcp['trust']->trust($file);
                foreach ($this->mcp['project'] as $server) {
                    $manager->add($server);
                }
            }
        }
        $manager->start(function (string $name, string $status) use ($manager): void {
            if ($status === 'failed') {
                $error = array_column($manager->status(), 'error', 'name')[$name] ?? '';
                $this->note("mcp {$name} failed: {$error}");
            } elseif ($status === 'login') {
                $this->note("mcp {$name} needs login · /mcp login {$name}");
            }
            $this->draw();
        });
    }

    /**
     * `/mcp` shows each server; `/mcp login <server>` and `/mcp logout <server>`
     * log in to a remote one, or forget its login.
     */
    /**
     * The last request to the model, in full: what it carried, what came
     * back, how long it took, and what happened on the way.
     */
    private function inspect(string $_): void
    {
        $report = $this->inspection?->report();
        if ($report === null) {
            $this->note('No request yet');
            return;
        }
        $label = max(array_map(static fn (array $row): int => mb_strwidth($row[0]), $report['rows'])) + 2;
        $room = max(10, $this->region->width() - 2 - $label);
        $lines = [Style::dim('· ' . $report['title'])];
        foreach ($report['rows'] as [$name, $text]) {
            foreach (Style::wrap($text, $room) as $index => $part) {
                $lines[] = '  ' . ($index === 0 ? Style::dim(str_pad($name, $label)) : str_repeat(' ', $label)) . $part;
            }
        }
        $this->region->gap();
        $this->region->print(implode("\n", $lines) . "\n");
    }

    private function showMcp(string $arguments): void
    {
        $manager = $this->mcp['manager'] ?? null;
        [$action, $name] = array_pad(preg_split('/\s+/', trim($arguments), 2) ?: [], 2, '');
        if ($action === 'login' || $action === 'logout') {
            if ($manager === null || $name === '') {
                $this->say("Usage: /mcp {$action} <server>");
                return;
            }
            if ($action === 'logout') {
                try {
                    $manager->logout($name);
                    $this->note("logged out of {$name}");
                } catch (\Throwable $error) {
                    $this->note($error->getMessage());
                }
                return;
            }
            Task::spawn(EventLoop::current(), function () use ($manager, $name): void {
                try {
                    $manager->login($name, function (string $url) use ($name): void {
                        $this->note("log in to {$name} in your browser: {$url}");
                        $this->note('opened it here; elsewhere, open it there and paste back the address it sends you to');
                        ($this->mcp['open'])($url);
                    });
                    $this->note("logged in to {$name}");
                } catch (\Throwable $error) {
                    $this->note("login to {$name} failed: {$error->getMessage()}");
                }
                $this->draw();
            });
            return;
        }
        $status = $manager?->status() ?? [];
        $this->region->gap();
        if ($status === []) {
            $this->region->print(Style::dim('No MCP servers · add them to ' . $this->config->configDirectory() . '/mcp.json') . "\n");
            return;
        }
        $width = max(array_map(static fn (array $s): int => mb_strwidth($s['name']), $status));
        foreach ($status as $server) {
            $name = str_pad($server['name'], $width + strlen($server['name']) - mb_strwidth($server['name']));
            $this->region->print(match ($server['status']) {
                'connected' => Style::ok('●') . " {$name}  " . Style::dim($server['tools'] . ($server['tools'] === 1 ? ' tool' : ' tools')),
                'failed' => Style::error('✗') . " {$name}  " . Style::dim(Style::truncate($server['error'], 100)),
                'connecting' => Style::dim("… {$name}  connecting"),
                'login' => Style::warn('○') . " {$name}  " . Style::dim("needs login · /mcp login {$server['name']}"),
                default => Style::dim("· {$name}  off"),
            } . ($server['project'] ? Style::dim('  · project') : '') . "\n");
        }
    }

    /**
     * A dim line in the output, on a line of its own.
     */
    private function note(string $text): void
    {
        $this->region->gap();
        $this->region->print(Style::dim("· {$text}") . "\n");
    }

    /**
     * Sets how answers appear, and remembers it for the next conversations.
     */
    private function render(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            $this->note('render: ' . $this->renderer->current() . ' · ' . implode(', ', TuiRenderer::MODES));
            return;
        }
        if (!in_array($mode, TuiRenderer::MODES, true)) {
            $this->note('render: one of ' . implode(', ', TuiRenderer::MODES));
            return;
        }
        $this->renderer->mode($mode);
        try {
            Config::remember('tui.render', $mode);
            $this->note("render: {$mode}");
        } catch (\RuntimeException $error) {
            $this->note("render: {$mode}, for now · " . $error->getMessage());
        }
    }

    private function help(string $_): void
    {
        $keys = <<<'TEXT'
            Enter send · Ctrl-J or Alt-Enter or \ then Enter: new line · Up/Down history
            Tab complete · Esc stop the turn or close a menu · Ctrl-C clear the input, else stop, or twice to leave
            Ctrl-W delete word · Ctrl-U delete to line start · Ctrl-L clear screen · Ctrl-D leave
            Ctrl-V paste an image from the clipboard · paste or drop an image file to attach it
            Type while Bia works: Enter steers it, at its next step.
            TEXT;
        if ($this->region->page() > 0) {
            $keys .= "\nWheel or PageUp/PageDown scroll · drag to select and copy · click a tool call to open it, Ctrl-O all";
        }
        $this->region->gap();
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
            // Skills follow the commands, each run by its name.
            foreach ($this->skills?->all() ?? [] as $skill) {
                if (!$this->commands->has($skill->name) && str_starts_with(strtolower($skill->name), strtolower(ltrim($text, '/')))) {
                    $items[] = ['value' => '/' . $skill->name, 'label' => '/' . $skill->name, 'hint' => Style::truncate($skill->description, 60)];
                }
            }
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
        // The live lines keep the output's margin; their rules span the screen, as shaded rows do.
        $margin = Screen::MARGIN;
        $columns = max(20, $this->terminal->columns());
        $width = max(20, $columns - 2 * $margin);
        $lines = [];
        if ($this->spinner !== null) {
            $lines[] = $this->spinner->render($width, hint: 'esc to stop');
        }
        foreach ($this->steering as $waiting) {
            $lines[] = Style::dim(Style::truncate('  ↳ ' . strtok($waiting, "\n"), $width));
        }
        $rule = Style::dim(str_repeat('─', $columns));
        $cursor = null;
        if (in_array($this->menuKind, ['confirm', 'model', 'session', 'effort'], true)) {
            $lines[] = $rule;
            if ($this->menuKind === 'confirm') {
                $lines[] = Style::warn(Style::truncate($this->menuTitle, $width));
                array_push($lines, ...($this->menu?->render($width) ?? []));
            } else {
                array_push($lines, ...($this->menu?->render($width, $this->menuTitle) ?? []));
            }
        } else {
            [$rows, [$row, $column]] = $this->editor->render(
                $width,
                $this->turn === null ? 'Cook anything · / commands · @ files' : 'Steer the turn',
            );
            // Suggestions open above the input, so the input does not move as they come and go.
            if ($this->menu !== null) {
                array_push($lines, ...$this->menu->render($width));
            }
            $lines[] = $rule;
            $cursor = [count($lines) + $row, $column];
            array_push($lines, ...$rows);
            $lines[] = $rule;
        }
        if ($this->showStats) {
            $stats = $this->stats->lines($this->region->metrics());
            if ($this->region->page() > 0) {
                $this->region->overlay($stats); // fullscreen: over the top right
            } else {
                $lines[] = Style::dim(Style::truncate(implode(' · ', $stats), $width)); // inline: no corner stays
            }
        }
        $lines[] = $this->status($width);
        $pad = str_repeat(' ', $margin);
        $lines = array_map(static fn (string $line): string => $line === $rule || $line === '' ? $line : $pad . $line, $lines);
        // A blank line sets the live lines apart from the output, as blocks are.
        array_unshift($lines, '');
        if ($cursor !== null) {
            $cursor = [$cursor[0] + 1, $cursor[1] + $margin];
        }
        $this->region->set($lines, $cursor);
    }

    /**
     * Prints the banner, and blinks the mascot's eyes once, the first time
     * only, as a cat looking up when someone comes in. Fullscreen only, and in colour:
     * inline, its rows are the terminal's once printed.
     */
    private function greet(): void
    {
        $this->stopBlinking();
        $this->bannerWidth = max(20, $this->terminal->columns());
        $this->bannerLine = $this->region->here() + 1;
        $this->region->print($this->banner($this->bannerWidth));
        if ($this->blinked || !$this->region instanceof Screen || !Style::color()) {
            return;
        }
        $this->blinked = true;
        $loop = EventLoop::current();
        foreach (self::BLINKS as $at) {
            $this->blinking[] = $loop->delay($at, fn () => $this->eyes(true));
            $this->blinking[] = $loop->delay($at + self::BLINK, fn () => $this->eyes(false));
        }
    }

    /**
     * The banner's eye line with her eyes shut, or open again.
     */
    private function eyes(bool $shut): void
    {
        if ($this->bannerLine === null) {
            return;
        }
        $line = explode("\n", $this->banner($this->bannerWidth, $shut))[1];
        $this->region->change($this->bannerLine, $line);
    }

    private function stopBlinking(): void
    {
        foreach ($this->blinking as $timer) {
            EventLoop::current()->cancel($timer);
        }
        $this->blinking = [];
    }

    /**
     * The greeting: Bia the cat beside its version and the project's folder.
     * Each line is cut to the room beside the cat, so none wraps under it on
     * a narrow screen.
     */
    private function banner(int $width, bool $blink = false): string
    {
        // The screen's width, less its margins, the cat and the space after it.
        $indent = Style::width(self::MASCOT[0]) + 3;
        $room = max(0, $width - 2 * Screen::MARGIN - $indent);
        $version = Style::bold('bia') . ' ' . Style::dim(Style::truncate($this->version, max(0, $room - 4)));
        $place = Style::dim(Style::truncate($this->place, $room));
        [$first, $second] = self::mascot($blink);
        return $first . '   ' . $version . "\n" . $second . '   ' . $place . "\n\n";
    }

    /**
     * The mascot's two lines, in amber; without colour, the shape alone.
     *
     * @return array{string, string}
     */
    private static function mascot(bool $blink = false): array
    {
        $paint = static fn (string $line): string => Style::color() ? "\e[38;5;" . self::MASCOT_COLOUR . "m{$line}\e[0m" : $line;
        return [$paint(self::MASCOT[0]), $paint($blink ? self::MASCOT_BLINK : self::MASCOT[1])];
    }

    private function status(int $width): string
    {
        $right = $this->modelShown();
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
