<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Config\Config;
use Bia\Share\Session;
use Bia\Terminal\Sharing;
use Bia\Terminal\TuiRenderer;
use Bia\Tui\{Editor, Key, Screen, SelectList, Style, Terminal};
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class ShareUiTest extends TestCase
{
    private string $home;
    private string|false $oldHome;
    private ShareUiFixture $ui;

    protected function setUp(): void
    {
        if (!extension_loaded('gmp') || !extension_loaded('sodium')) { self::markTestSkipped('Sharing requires crypto extensions'); }
        EventLoop::install(new StreamSelectEventLoop());
        Style::enable(false);
        $this->oldHome = getenv('BIA_HOME');
        $this->home = sys_get_temp_dir() . '/bia-nickname-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700);
        putenv('BIA_HOME=' . $this->home);
        $this->ui = new ShareUiFixture(Config::load($this->home));
    }

    protected function tearDown(): void
    {
        if (!isset($this->ui)) { return; }
        $this->ui->leave();
        EventLoop::install(new StreamSelectEventLoop());
        Style::enable(false);
        foreach (glob($this->home . '/*') as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($this->home);
        putenv($this->oldHome === false ? 'BIA_HOME' : 'BIA_HOME=' . $this->oldHome);
    }

    public function testFirstShareAsksAndSavesNicknameThenNicknameCommandUpdatesOnlyNextConnection(): void
    {
        $this->ui->start('');
        self::assertTrue($this->ui->askingName());
        self::assertNull($this->ui->session());
        $this->ui->keyboard(new Key('char', 'Billy'));
        $this->ui->keyboard(new Key('enter'));
        self::assertFalse($this->ui->askingName());
        self::assertSame('Billy', $this->ui->session()->nickname);
        self::assertSame('Billy', Config::load($this->home)->get('sharing.nickname'));
        self::assertSame([], $this->ui->asked);
        $this->ui->rename('William');
        self::assertSame('Billy', $this->ui->session()->nickname, 'Approved connection names are immutable');
        self::assertSame('William', Config::load($this->home)->get('sharing.nickname'));
        self::assertStringContainsString('next connection', end($this->ui->notes));
        $this->ui->leave();
        $this->ui->start('');
        self::assertFalse($this->ui->askingName());
        self::assertSame('William', $this->ui->session()->nickname);
        $other = new ShareUiFixture(Config::load($this->home));
        try {
            $other->start('');
            self::assertFalse($other->askingName(), 'Restart must not ask again');
            self::assertSame('William', $other->session()->nickname);
        } finally { $other->leave(); }
    }

    public function testFirstJoinNamePromptCanBeCanceledWithoutConnectingOrSaving(): void
    {
        $this->ui->join('a7K2m9Q4x8Lp');
        self::assertTrue($this->ui->askingName());
        $this->ui->keyboard(new Key('enter'));
        self::assertTrue($this->ui->askingName(), 'Empty nickname must not proceed');
        $this->ui->keyboard(new Key('paste', "Billy\nAllow\e[31m"));
        self::assertSame('', $this->ui->nameText());
        $this->ui->keyboard(new Key('escape'));
        self::assertFalse($this->ui->askingName());
        self::assertNull($this->ui->session());
        self::assertSame('', Config::load($this->home)->get('sharing.nickname'));
        self::assertSame([], $this->ui->asked);
    }

    public function testListUsesArrowsAndEnterAndNeverAcceptsTypingPasteOrTab(): void
    {
        [$host, $guest] = $this->request();
        $this->ui->offer();
        self::assertSame('Billy wants to join', $this->ui->title());
        self::assertInstanceOf(SelectList::class, $this->ui->picker());
        self::assertSame('deny', $this->ui->picker()->selected()['value']);
        foreach ([new Key('char', 'y'), new Key('paste', 'allow'), new Key('tab')] as $key) { $this->ui->keyboard($key); }
        self::assertFalse($host->ready());
        $this->ui->keyboard(new Key('up'));
        self::assertSame('allow', $this->ui->picker()->selected()['value']);
        $this->ui->keyboard(new Key('down'));
        self::assertSame('deny', $this->ui->picker()->selected()['value']);
        $this->ui->keyboard(new Key('up'));
        $this->ui->keyboard(new Key('enter'));
        self::assertTrue($host->ready());
        self::assertSame($guest->identity->publicKey(), $host->peerKey());
        self::assertStringContainsString('Billy joined', end($this->ui->notes));
        self::assertSame([], $this->ui->asked);
    }

    public function testEscAndDefaultEnterDenyWithoutSendingHistory(): void
    {
        foreach (['escape', 'enter'] as $key) {
            [$host, $guest] = $this->request();
            $this->ui->offer();
            $this->ui->keyboard(new Key($key));
            self::assertFalse($host->ready());
            self::assertSame([], $host->pending());
            self::assertNull($host->packet());
            $guest->receive($host->admissionPackets()[0]);
            self::assertFalse($guest->active());
            $this->ui->leave();
        }
    }

    public function testApprovalDoesNotStealDraftOrWorkingTurnAndStaleSelectionCannotApprove(): void
    {
        [$host, $guest] = $this->request();
        $this->ui->draft('half-typed message');
        $this->ui->offer();
        self::assertNull($this->ui->picker());
        $this->ui->draft('');
        $this->ui->busy(true);
        $this->ui->offer();
        self::assertNull($this->ui->picker());
        $this->ui->busy(false);
        $this->ui->offer();
        self::assertNotNull($this->ui->picker());
        $this->ui->busy(true); // A local plugin started working after the prompt opened.
        $this->ui->offer();
        self::assertNull($this->ui->picker());
        $this->ui->busy(false);
        $this->ui->offer();
        $this->ui->keyboard(new Key('up'));
        $host->stop(false);
        $this->ui->keyboard(new Key('enter'));
        self::assertFalse($host->ready());
        self::assertSame([], $this->ui->asked);
    }

    public function testFooterHasNoUrlsAndStatusDetailsStayLocal(): void
    {
        self::assertNull($this->ui->footer(80));
        [$host] = $this->request();
        Style::enable(true);
        $line = $this->ui->footer(28);
        self::assertStringContainsString("\e[", $line);
        self::assertStringNotContainsString('wss://', $line);
        self::assertLessThanOrEqual(28, Style::width($line));
        Style::enable(false);
        self::assertStringNotContainsString("\e", $this->ui->footer(80));
        $this->ui->start('status');
        self::assertStringContainsString('wss://nos.lol', end($this->ui->notes));
        self::assertStringContainsString('Billy', end($this->ui->notes));
        $host->stop(false);
        self::assertStringContainsString('Disconnected', $this->ui->footer(80));
        $this->ui->leave();
        self::assertNull($this->ui->footer(80));
    }

    private function request(): array
    {
        $host = new Session(true, $this->ui->notice(...), $this->ui->receive(...), nickname: 'Daud');
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite, nickname: 'Billy');
        $this->ui->attach($host);
        $host->receive($guest->packet());
        return [$host, $guest];
    }
}

/** Exercise the Sharing trait without a TTY, model, or local tool dispatch. */
final class ShareUiFixture
{
    use Sharing {
        share as public start; joinShare as public join; nickname as public rename;
        shareKey as public keyboard; offerShareApproval as public offer; endShare as public leave;
        shareStatusLine as public footer; shareNotice as public notice; shareReceive as public receive;
    }
    public array $notes = [];
    public array $asked = [];
    private ?Cancellation $turn = null;
    private $pluginRun = null;
    private $naming = null;
    private $choosing = null;
    private $confirming = null;
    private $viewing = null;
    private $agentRow = null;
    private $usageBy = null;
    private $sessionId = null;
    private bool $running = true;
    private ?SelectList $menu = null;
    private string $menuKind = '';
    private string $menuTitle = '';
    private Editor $editor;
    private Screen $region;
    private TuiRenderer $renderer;
    private $output;

    public function __construct(private Config $config)
    {
        $this->output = fopen('php://memory', 'w+');
        $this->region = new Screen(new Terminal(STDIN, $this->output, [80, 24]));
        $this->renderer = new TuiRenderer($this->region);
        $this->editor = new Editor();
    }
    public function __destruct() { fclose($this->output); }
    public function attach(Session $session): void { $this->sharing = $session; }
    public function session(): ?Session { return $this->sharing; }
    public function askingName(): bool { return $this->nicknameEditor !== null; }
    public function nameText(): string { return $this->nicknameEditor?->text() ?? ''; }
    public function picker(): ?SelectList { return $this->menu; }
    public function title(): string { return $this->menuTitle; }
    public function draft(string $text): void { $this->editor->set($text); }
    public function busy(bool $busy): void { $this->turn = $busy ? new Cancellation() : null; }
    private function note(string $text): void { $this->notes[] = $text; }
    private function say(string $text): void { $this->notes[] = $text; }
    private function view(mixed $view): void {}
    private function cancelRecap(): void {}
    private function draw(): void { $this->offerShareApproval(); }
    private function ask(string $text, bool $remote = false): void { $this->asked[] = [$text, $remote]; }
    private function openMenu(string $kind, string $title, SelectList $menu): void { $this->menuKind = $kind; $this->menuTitle = $title; $this->menu = $menu; }
    private function closeMenu(): void { $this->menuKind = $this->menuTitle = ''; $this->menu = null; }
}
