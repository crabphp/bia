<?php

declare(strict_types=1);

namespace Bia\Skill;

/**
 * A skill: its name, the line that says when to use it, and the folder
 * that holds its SKILL.md and whatever files go with it.
 */
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $directory,
    ) {}

    /**
     * The instructions: SKILL.md without its frontmatter.
     */
    public function instructions(): string
    {
        $text = (string) @file_get_contents($this->directory . '/SKILL.md');
        return trim((string) preg_replace('/\A\xEF?\xBB?\xBF?---\r?\n.*?\r?\n---\s*(?:\r?\n|\z)/s', '', $text, 1));
    }
}
