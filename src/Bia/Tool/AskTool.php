<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Asks the user a question with a few answers to choose from, or their own
 * words. With no one to ask, or when they skip it, the model is told to
 * decide for itself: a run never waits on it.
 *
 * Its permission is `question`, not `read`, so it is asked on its own and
 * never alongside another question in the same step.
 */
final readonly class AskTool implements Tool
{
    /**
     * @param \Closure(string, list<array{label: string, detail: string}>): (string|false|null) $ask The answer:
     *        an option's label or the user's words; null when skipped or with no one to ask; false
     *        when no answer came in time.
     */
    public function __construct(private \Closure $ask) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('ask', <<<'TEXT'
            Ask the user a question with 2 to 4 answers to choose from; they may also answer in their own words. Ask only when you cannot decide well yourself and the choice matters: a design decision, an unclear requirement, something only they know. Do not ask for permission to go on, or what you can find out by reading the code. If they skip it, decide yourself.
            TEXT, [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'description' => 'The question, short and complete on its own.'],
                'options' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 4,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'The answer, in a few words.'],
                            'detail' => ['type' => 'string', 'description' => 'What it means, in one short line.'],
                        ],
                        'required' => ['label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['question', 'options'],
            'additionalProperties' => false,
        ], permission: 'question');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $question = trim((string) ($arguments['question'] ?? ''));
        $options = [];
        foreach (array_slice((array) ($arguments['options'] ?? []), 0, 4) as $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? '') : $option));
            if ($label !== '') {
                $options[] = ['label' => $label, 'detail' => trim((string) (is_array($option) ? ($option['detail'] ?? '') : ''))];
            }
        }
        if ($question === '' || count($options) < 2) {
            return new ToolResult('Give a question and 2 to 4 options.', false);
        }
        $cancellation?->throwIfCancelled();
        $answer = ($this->ask)($question, $options);
        if ($answer === false) {
            return new ToolResult('No answer in time: the user may be away. Decide yourself, say what you chose, and go on.', true, ['question' => $question]);
        }
        if ($answer === null || trim($answer) === '') {
            return new ToolResult('Skipped: no answer was given. Decide yourself, and say what you chose.', true, ['question' => $question]);
        }
        $answer = trim($answer);
        $chosen = in_array($answer, array_column($options, 'label'), true);
        return new ToolResult(($chosen ? 'Chose: ' : 'Answered: ') . $answer, true, ['question' => $question, 'answer' => $answer]);
    }
}
