<?php

declare(strict_types=1);

namespace Bia\Skill;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Loads a skill when the model judges the task needs it. Only the skills'
 * names and one-line descriptions sit in its description, in front of the
 * model at every step; a skill's instructions are read when it is used,
 * and its other files when they are asked for, from its folder alone.
 */
final readonly class SkillTool implements Tool
{
    /** Bytes of a skill's file given at once. */
    private const MAX_BYTES = 65_536;

    /**
     * @param array<string, Skill> $skills
     */
    public function __construct(private array $skills) {}

    public function definition(): ToolDefinition
    {
        $list = implode("\n", array_map(
            static fn (Skill $skill): string => "- {$skill->name}: {$skill->description}",
            $this->skills,
        ));
        return new ToolDefinition(
            'skill',
            "Loads a skill: instructions for a kind of task, and files that go with them. When the task matches a skill below, load it first and follow it. Give file to read another file of the skill, as its instructions name it.\n\nSkills:\n{$list}",
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The skill, by its name in the list.'],
                    'file' => ['type' => 'string', 'description' => 'A file of the skill to read, relative to its folder; leave out for its instructions.'],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            'read',
        );
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        $skill = $this->skills[$name] ?? null;
        if ($skill === null) {
            return new ToolResult("There is no skill named {$name}. Skills: " . implode(', ', array_keys($this->skills)) . '.', false);
        }
        $file = trim((string) ($arguments['file'] ?? ''));
        if ($file === '') {
            return new ToolResult(
                $skill->instructions() . "\n\n[The skill's folder is {$skill->directory}: read its other files with this tool's file argument, and run its scripts from there.]",
                true,
                ['skill' => $skill->name],
            );
        }
        // Inside the skill's own folder, and nowhere else.
        $path = realpath($skill->directory . '/' . ltrim($file, '/'));
        $root = realpath($skill->directory);
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return new ToolResult("The {$name} skill has no file {$file}.", false);
        }
        $text = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (str_contains(substr($text, 0, 8192), "\0")) {
            return new ToolResult("{$file} is not a text file.", false);
        }
        if (strlen($text) > self::MAX_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_BYTES, 'UTF-8') . "\n[cut at " . self::MAX_BYTES . ' bytes]';
        }
        return new ToolResult($text, true, ['skill' => $skill->name, 'file' => $file]);
    }
}
