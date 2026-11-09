<?php

declare(strict_types=1);

namespace Bia\Memory;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * The model's hand on Bia's memory. The file tools stay inside the
 * workspace and memory is outside it, so this is the one way in: it names
 * no path, and writes each kind of memory in its own format.
 */
final readonly class MemoryTool implements Tool
{
    public function __construct(private Memory $memory) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'memory',
            'Keeps and recalls what is worth knowing in a later session. note: a line in today\'s diary. save: a fact about the user (scope user, one line) or a memory of this project (scope project, with a description). rule: a rule the user laid down for this project, written into its AGENTS.md. forget: a memory no longer true or needed. read: a project memory by name, or a day of the diary by its date. search: lines of the diary and the project\'s memories that hold a word.',
            [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['note', 'save', 'rule', 'forget', 'read', 'search']],
                    'scope' => ['type' => 'string', 'enum' => ['user', 'project'], 'description' => 'For save and forget; project when left out.'],
                    'name' => ['type' => 'string', 'description' => 'A memory\'s name, short words and dashes, as crab-install; for read, that or a date as 2026-11-10.'],
                    'description' => ['type' => 'string', 'description' => 'For a project memory: one line saying what it holds, as its index shows it.'],
                    'text' => ['type' => 'string', 'description' => 'What to keep: the entry, the fact, the rule, or the memory in full.'],
                    'query' => ['type' => 'string', 'description' => 'For search: the words to find.'],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            'write',
        );
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $text = (string) ($arguments['text'] ?? '');
        $name = (string) ($arguments['name'] ?? '');
        $user = ($arguments['scope'] ?? 'project') === 'user';
        try {
            switch ((string) ($arguments['action'] ?? '')) {
                case 'note':
                    return new ToolResult(ltrim($this->memory->note($text), '- '));
                case 'save':
                    if ($user) {
                        $this->memory->saveAbout($name, $text);
                        return new ToolResult(trim($text));
                    }
                    $long = $this->memory->save($name, (string) ($arguments['description'] ?? ''), $text);
                    return new ToolResult((string) $arguments['description'] . ($long
                        ? "\n[Bia: the index is longer than what is read of it each session. Forget memories no longer needed, and merge those on one matter.]"
                        : ''));
                case 'rule':
                    $rule = $this->memory->rule($text);
                    return $rule['diff'] === []
                        ? new ToolResult('That rule is there already.')
                        : new ToolResult('Written into ' . $rule['path'] . ', and followed from now on.', true, ['path' => basename($rule['path']), 'diff' => $rule['diff']]);
                case 'forget':
                    $gone = $user ? $this->memory->forgetAbout($name) : $this->memory->forget($name);
                    return new ToolResult($gone ? 'Forgotten.' : "There is no memory named {$name}.", $gone);
                case 'read':
                    $found = $this->memory->page($name) ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1 ? null : $this->memory->read($name));
                    return $found !== null
                        ? new ToolResult($found)
                        : new ToolResult("There is no memory or diary page named {$name}.", false);
                case 'search':
                    $found = $this->memory->search((string) ($arguments['query'] ?? ''));
                    return new ToolResult($found === [] ? 'Nothing found.' : implode("\n", $found));
                default:
                    return new ToolResult('Give an action: note, save, rule, forget, read or search.', false);
            }
        } catch (\InvalidArgumentException $error) {
            return new ToolResult($error->getMessage(), false);
        }
    }
}
