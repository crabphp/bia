<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;

/**
 * What is sent to the model, built from a session's path of entries.
 *
 * The latest compaction stands in for everything before the entry it kept
 * first. Every tool call is given a result, so a session cut off between a
 * call and its result (a crash, a kill) is never refused by the provider.
 */
final readonly class Context
{
    /**
     * @param list<array{int|null, Message}> $items Each message with the entry it came from; null for a summary or a filled-in result.
     * @param int $sinceCompaction The index of the first item stored after the latest compaction.
     */
    private function __construct(
        public array $items,
        public int $sinceCompaction,
        public ?Compaction $compaction,
    ) {}

    /**
     * @param list<Entry> $entries
     */
    public static function build(array $entries): self
    {
        $last = null;
        foreach ($entries as $index => $entry) {
            if ($entry->payload instanceof Compaction) {
                $last = $index;
            }
        }
        $items = [];
        $since = 0;
        $compaction = null;
        if ($last !== null) {
            $compaction = $entries[$last]->payload;
            $items[] = [null, Message::user("[The earlier part of this conversation was compacted. Summary:]\n\n" . $compaction->summary)];
            $kept = false;
            foreach (array_slice($entries, 0, $last) as $entry) {
                $kept = $kept || $entry->id === $compaction->firstKeptId;
                if ($kept && $entry->payload instanceof Message) {
                    $items[] = [$entry->id, $entry->payload];
                }
            }
            $since = count($items);
            $entries = array_slice($entries, $last + 1);
        }
        foreach ($entries as $entry) {
            if ($entry->payload instanceof Message) {
                $items[] = [$entry->id, $entry->payload];
            }
        }
        return new self(self::repair($items), $since, $compaction);
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return array_map(static fn (array $item): Message => $item[1], $this->items);
    }

    /**
     * @param list<array{int|null, Message}> $items
     * @return list<array{int|null, Message}>
     */
    private static function repair(array $items): array
    {
        $repaired = [];
        $pending = [];
        $flush = static function () use (&$repaired, &$pending): void {
            foreach ($pending as $call) {
                $repaired[] = [null, Message::toolResult($call, '[no result: the turn was interrupted]', false)];
            }
            $pending = [];
        };
        foreach ($items as $item) {
            $message = $item[1];
            if ($message->role === 'tool') {
                if (!isset($pending[(string) $message->toolCallId])) {
                    continue; // A result whose call is gone, cut away by a compaction.
                }
                unset($pending[(string) $message->toolCallId]);
                $repaired[] = $item;
                continue;
            }
            $flush();
            $repaired[] = $item;
            foreach ($message->toolCalls as $call) {
                $pending[$call->id] = $call;
            }
        }
        $flush();
        return $repaired;
    }
}
