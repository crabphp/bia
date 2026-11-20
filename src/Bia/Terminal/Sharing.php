<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Share\Discovery;
use Bia\Config\Config;
use Bia\Share\Invite;
use Bia\Share\Nickname;
use Bia\Tui\Editor;
use Bia\Tui\Key;
use Bia\Tui\SelectList;
use Bia\Tui\Style;
use Bia\Share\Records;
use Bia\Share\Session;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/** Collaboration controls for Chat. All display still goes through its Surface/renderer. */
trait Sharing
{
    private ?Session $sharing = null;
    private ?Cancellation $joining = null;
    private bool $guest = false;
    private array $guestQueue = [];
    private bool $remoteTurn = false;
    private ?string $shareNickname = null;
    private ?Editor $nicknameEditor = null;
    private ?\Closure $afterNickname = null;
    private ?string $shareApproval = null;
    private ?string $sharePromptTimer = null;

    private function shareStatusLine(int $width): ?string
    {
        if ($this->sharing === null && !$this->guest) { return null; }
        $status = $this->joining !== null ? ['state' => 'waiting', 'text' => 'Finding shared session… · Esc to cancel']
            : ($this->sharing?->status() ?? ['state' => 'disconnected', 'text' => 'Disconnected · /share stop to leave']);
        $line = Style::truncate('● ' . $status['text'], $width);
        return match ($status['state']) {
            'connected' => Style::ok($line),
            'disconnected' => Style::error($line),
            default => Style::accent($line),
        };
    }

    private function shareDetails(): void
    {
        if ($this->sharing === null) {
            $this->shareNotice($this->joining !== null ? 'Finding shared session…' : 'No connected share');
            return;
        }
        $session = $this->sharing;
        $lines = [$session->status()['text'], 'You: ' . $session->nickname . ' · ' . substr($session->identity->publicKey(), 0, 12)];
        if ($session->peerKey() !== null) { $lines[] = 'Peer: ' . $session->peerNickname() . ' · ' . substr($session->peerKey(), 0, 12); }
        foreach ($session->pending() as $key => $request) { $lines[] = 'Pending: ' . $request['nickname'] . ' · ' . substr($key, 0, 12); }
        foreach ($session->relayStatus() as $url => $state) { $lines[] = $url . ' · ' . $state; }
        $this->shareNotice("Sharing\n  " . implode("\n  ", $lines));
    }

    private function saveNickname(string $value): bool
    {
        $name = Nickname::valid($value);
        if ($name === null) { $this->say('Nickname: 1–32 characters, starting with a letter or number'); return false; }
        $this->shareNickname = $name;
        try { Config::remember('sharing.nickname', $name); }
        catch (\Throwable) { $this->note('Nickname kept for this session · could not save config'); }
        return true;
    }

    private function nickname(string $name): void
    {
        $saved = fn () => $this->shareNotice('Nickname: ' . $this->shareNickname
            . ($this->sharing !== null || $this->joining !== null ? ' · used on the next connection' : ''));
        if ($name !== '') {
            if ($this->saveNickname($name)) { $saved(); }
            return;
        }
        if ($this->turn !== null || $this->pluginRun !== null) { $this->say('Use /nickname <name> while working'); return; }
        $this->nicknameEditor = new Editor();
        $this->nicknameEditor->set($this->shareNickname ?? Nickname::valid($this->config->get('sharing.nickname')) ?? '');
        $this->afterNickname = $saved;
        $this->cancelRecap();
        $this->closeMenu();
    }

    private function needNickname(\Closure $next): bool
    {
        $this->shareNickname ??= Nickname::valid($this->config->get('sharing.nickname'));
        if ($this->shareNickname !== null) { return false; }
        if ($this->turn !== null || $this->pluginRun !== null) {
            $this->say('Choose a nickname after this turn finishes');
            return true;
        }
        $this->nicknameEditor = new Editor();
        $this->afterNickname = $next;
        $this->cancelRecap();
        $this->closeMenu();
        return true;
    }

    /** Nicknames and join approval use the live input area, never model input or history. */
    private function shareKey(Key $key): bool
    {
        if ($this->nicknameEditor !== null) {
            if ($key->is('escape', 'ctrl-c', 'ctrl-d')) {
                $this->nicknameEditor = $this->afterNickname = null;
                $this->drainGuests();
            } elseif ($key->is('enter')) {
                if (!$this->saveNickname($this->nicknameEditor->text())) { return true; }
                $next = $this->afterNickname;
                $this->nicknameEditor = $this->afterNickname = null;
                $next?->__invoke();
                $this->drainGuests();
            } elseif ($key->is('char', 'paste')) {
                if (strlen($this->nicknameEditor->text()) + strlen($key->text) > 128
                    || !preg_match("/^[\\p{L}\\p{M}\\p{N} ._'’-]*$/uD", $key->text)) {
                    $this->say('Use a short, single-line nickname');
                } else { $this->nicknameEditor->key($key); }
            } elseif (!$key->is('ctrl-j', 'alt-enter')) {
                $this->nicknameEditor->key($key);
            }
            return true;
        }
        if ($this->menuKind !== 'share-approve') { return false; }
        // Only the advertised keys confirm; Tab/typing/paste never grants access.
        $action = $key->is('escape', 'ctrl-c', 'ctrl-d') ? 'cancel'
            : ($key->is('up', 'down', 'enter') ? $this->menu?->key($key) : null);
        if ($action === 'choose' || $action === 'cancel') {
            $key = $this->shareApproval;
            $allow = $action === 'choose' && $this->menu?->selected()['value'] === 'allow';
            $this->shareApproval = null;
            $this->closeMenu();
            if ($key !== null) {
                if ($allow) { $this->approveShare($key); }
                elseif ($this->sharing?->deny($key)) { $this->note('Join request denied'); }
            }
        }
        return true;
    }

    /** Defer until idle and the editor is empty: never steal a typed answer or a tool permission. */
    private function offerShareApproval(): void
    {
        $pending = $this->sharing?->pending() ?? [];
        $busy = $this->turn !== null || $this->pluginRun !== null || $this->choosing !== null || $this->confirming !== null
            || $this->viewing !== null || $this->agentRow !== null || $this->usageBy !== null || !$this->editor->empty();
        if ($this->shareApproval !== null && (!isset($pending[$this->shareApproval]) || $busy || $this->menuKind !== 'share-approve')) {
            $this->shareApproval = null;
            if ($this->menuKind === 'share-approve') { $this->closeMenu(); }
        }
        if ($pending === [] || $this->guest || $this->shareApproval !== null || $this->turn !== null
            || $this->pluginRun !== null || $this->choosing !== null || $this->confirming !== null
            || $this->nicknameEditor !== null || $this->menu !== null || !$this->editor->empty()
            || $this->viewing !== null || $this->agentRow !== null || $this->usageBy !== null) { return; }
        $key = $this->shareApproval = array_key_first($pending);
        $this->cancelRecap();
        $this->openMenu('share-approve', $pending[$key]['nickname'] . ' wants to join', new SelectList([
            ['value' => 'allow', 'label' => 'Allow'], ['value' => 'deny', 'label' => 'Deny'],
        ], selected: 'deny', filters: false));
    }

    private function approveShare(string $key): void
    {
        if ($this->turn !== null || $this->pluginRun !== null) { $this->say('Approve after this turn finishes'); return; }
        if ($this->sharing?->approve($key) !== true) { $this->say('No matching pending guest'); return; }
        foreach (Records::history($this->sessionId === null ? [] : $this->sessions->path($this->sessionId)) as $record) {
            $this->sharing->publish($record);
        }
        $this->shareNotice($this->sharing->peerNickname() . ' joined · can see the conversation and direct your agent');
    }

    private function share(string $rest): void
    {
        if ($rest === 'status') { $this->shareDetails(); return; }
        if (!$this->guest && $this->sharing !== null && !$this->sharing->active()) {
            $this->endShare();
        }
        if ($rest === 'stop') {
            $this->endShare();
            $this->note('Sharing stopped');
            return;
        }
        if (str_starts_with($rest, 'approve ')) {
            $this->approveShare(trim(substr($rest, 8)));
            return;
        }
        if ($this->sharing !== null || $this->guest) {
            $this->note($this->guest ? 'Remote session · /share stop to leave' : 'Sharing · /share stop to revoke');
            return;
        }
        if ($rest !== '' && !str_starts_with($rest, 'wss://') && !str_starts_with($rest, 'ws://')) {
            $this->say('Usage: /share [relay-url ...] | status | stop');
            return;
        }
        if ((string) $this->config->get('model.api_key', '') === '') {
            $this->note('Cannot share without a model API key · ' . $this->config->keyHelp());
            return;
        }
        try {
            $relays = $rest === '' ? Invite::RELAYS : preg_split('/\s+/', $rest);
            foreach ($relays as $relay) { Invite::relay($relay); }
            if ($this->needNickname(fn () => $this->share($rest))) { return; }
            $this->sharing = new Session(true, $this->shareNotice(...), $this->shareReceive(...), relays: $relays, nickname: $this->shareNickname);
            $this->sharePromptTimer = EventLoop::current()->repeat(1.0, fn () => $this->draw());
            $this->observeShare();
            $this->sharing->start();
            $args = $this->sharing->code . ($relays !== Invite::RELAYS ? ' ' . implode(' ', $relays) : '');
            $wrap = max(8, $this->region->width() - 2);
            $this->note("Share invite · expires in 1h · approval required\n  "
                . wordwrap("bia join {$args}", $wrap, "\n  ", true) . "\n  "
                . wordwrap("/join {$args}", $wrap, "\n  ", true));
        } catch (\Throwable $error) {
            $this->endShare();
            $this->note('Share failed · ' . $error->getMessage());
        }
    }

    private function joinShare(string $input): void
    {
        if ($this->sharing !== null || $this->guest || $this->joining !== null || $this->turn !== null || $this->pluginRun !== null) {
            $this->say('Stop the current turn or share first');
            return;
        }
        try {
            ['invite' => $invite, 'code' => $code, 'relays' => $relays] = Discovery::parseJoin($input);
        } catch (\Throwable $error) {
            $this->note('Join failed · ' . $error->getMessage());
            return;
        }
        if ($this->needNickname(fn () => $this->joinShare($input))) { return; }
        $cancel = $this->joining = new Cancellation();
        // Gate local input before lookup starts, and keep it gated after a failed/canceled lookup.
        $this->guest = true;
        $this->view(null);
        $this->naming?->cancel();
        $this->cancelRecap();
        $this->sharePromptTimer ??= EventLoop::current()->repeat(1.0, fn () => $this->draw());
        $nickname = $this->shareNickname;
        Task::spawn(EventLoop::current(), function () use ($invite, $code, $relays, $cancel, $nickname): void {
            try {
                $invite ??= Discovery::resolve($code, $cancel, $relays);
                $cancel->throwIfCancelled();
                if ($this->joining !== $cancel || !$this->running) { return; }
                $this->sharing = new Session(false, $this->shareNotice(...), $this->shareReceive(...), $invite, nickname: $nickname);
                $this->sharing->start();
                // The colored footer shows waiting/connection state; details live behind /share status.
            } catch (\Throwable $error) {
                if ($this->joining === $cancel && $this->running) {
                    $this->sharing?->stop();
                    $this->sharing = null;
                    $this->note(($error instanceof CancelledException ? 'Join canceled' : 'Join failed · ' . $error->getMessage())
                        . ' · /share stop to leave');
                }
            } finally {
                if ($this->joining === $cancel) {
                    $this->joining = null;
                    if ($this->running) { $this->draw(); }
                }
            }
        });
    }

    private function shareNotice(string $text, bool $transient = false): void
    {
        if ($transient) { return; } // Requests and waiting states are drawn in the picker/footer.
        // Transport notices are local-only: never echo them back through the observer.
        $this->renderer->observe(null);
        if ($this->sharing !== null && !$this->sharing->active()) {
            $this->guestQueue = [];
            if ($this->remoteTurn) { $this->turn?->cancel(); }
            // Stay in guest mode until explicitly left: never send a disconnected guest's next input to a local agent.
            if ($this->guest) { $text .= ' · /share stop to leave'; }
        }
        $this->note($text);
        $this->observeShare();
        if ($this->running) { $this->draw(); }
    }

    private function observeShare(): void
    {
        $this->renderer->observe($this->sharing !== null && !$this->guest ? function (string $type, array $args): void {
            if (!$this->sharing?->ready()) { return; }
            if ($type === 'event' && $args[0]->type === 'text_delta') {
                foreach (mb_str_split($args[0]->text, 1500, 'UTF-8') as $chunk) {
                    $this->sharing->publish(['type' => 'text', 'text' => Records::clean($chunk)]);
                }
            } elseif ($type === 'tool') {
                $this->sharing->publish(Records::tool($args[0], $args[1]));
            } elseif ($type === 'running') {
                $this->sharing->publish(Records::running($args[0], $args[1]));
            } elseif ($type === 'notice') {
                $this->sharing->publish(['type' => 'note', 'text' => Records::clean($args[0])]);
            } elseif ($type === 'finish') {
                $this->sharing->publish(['type' => 'finish']);
            }
        } : null);
    }

    private function shareReceive(array $record): void
    {
        if ($this->sharing === null) { return; }
        if ($this->guest) {
            Records::play($record, $this->renderer, function (string $text): void {
                $this->renderer->collapse();
                self::echoOn($this->region, $text);
            });
            $this->draw();
            return;
        }
        // Never dispatch remote slash commands, plugin commands, image references, or answers to local permission prompts.
        if (($record['type'] ?? null) !== 'input' || !is_string($record['text'] ?? null)
            || strlen($record['text']) > 8000 || trim($record['text']) === '') { return; }
        if (count($this->guestQueue) >= 8) {
            $this->sharing->publish(['type' => 'note', 'text' => 'Guest message rejected · host queue full']);
            return;
        }
        $this->guestQueue[] = Records::clean($record['text']);
        $this->sharing->publish(['type' => 'note', 'text' => 'Guest message queued on host']);
        $this->drainGuests();
    }

    private function drainGuests(): void
    {
        if ($this->sharing?->active() !== true) { $this->guestQueue = []; return; }
        if ($this->nicknameEditor !== null || $this->shareApproval !== null) { return; }
        if ($this->turn === null && $this->pluginRun === null && $this->choosing === null && $this->confirming === null
            && !$this->guest && $this->guestQueue !== [] && $this->running) {
            $this->ask('[' . $this->sharing->peerNickname() . " · remote]\n" . array_shift($this->guestQueue), remote: true);
        }
    }

    private function endShare(): void
    {
        $this->nicknameEditor = $this->afterNickname = null;
        $this->shareApproval = null;
        if ($this->sharePromptTimer !== null) { EventLoop::current()->cancel($this->sharePromptTimer); $this->sharePromptTimer = null; }
        if ($this->menuKind === 'share-approve') { $this->closeMenu(); }
        $joining = $this->joining;
        $this->joining = null; // Invalidate callbacks before canceling a lookup.
        $joining?->cancel();
        $this->renderer->observe(null);
        if ($this->remoteTurn) { $this->turn?->cancel(); }
        $this->sharing?->stop();
        $this->sharing = null;
        $this->guest = false;
        $this->guestQueue = [];
    }
}
