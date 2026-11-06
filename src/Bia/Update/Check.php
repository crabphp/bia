<?php

declare(strict_types=1);

namespace Bia\Update;

use Bia\Tool\AtomicFile;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;

/**
 * Whether a newer Bia is out: asked of GitHub at most once a day, the
 * answer kept in a small file between runs. A conversation runs it in the
 * background once its first screen is drawn, and nothing waits on it.
 */
final readonly class Check
{
    public const EVERY = 86_400;

    /**
     * @param string $file Where the last answer is kept: update.json in Bia's cache.
     * @param (\Closure(?Cancellation): void)|null $reachable Called before GitHub is asked; throws when it cannot be reached without holding the process, as a name only the system's blocking resolver could find.
     */
    public function __construct(
        private Releases $releases,
        private string $file,
        private string $current,
        private ?\Closure $reachable = null,
        private int $every = self::EVERY,
    ) {}

    /**
     * The newer version, from the last day's answer or asked for now;
     * null when there is none, or none could be found. Offline, the last
     * answer stands, and the next run asks again.
     */
    public function newer(?Cancellation $cancellation = null, ?int $now = null): ?string
    {
        $now ??= time();
        $kept = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;
        $latest = is_array($kept) && is_string($kept['latest'] ?? null) ? $kept['latest'] : null;
        $checked = is_array($kept) && is_int($kept['checked_at'] ?? null) ? $kept['checked_at'] : null;
        if ($latest === null || $checked === null || $now - $checked >= $this->every || $checked > $now) {
            try {
                if ($this->reachable !== null) {
                    ($this->reachable)($cancellation);
                }
                $latest = $this->releases->latest($cancellation);
                AtomicFile::write($this->file, json_encode(['checked_at' => $now, 'latest' => $latest]) . "\n");
            } catch (CancelledException $cancelled) {
                throw $cancelled;
            } catch (\Throwable) {
                // Offline, or GitHub not answering: asked again next time.
            }
        }
        return $latest !== null && Releases::newer($latest, $this->current) ? $latest : null;
    }
}
