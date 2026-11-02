<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ToolContext;
use Bia\Skill\Catalog;
use Bia\Skill\SkillTool;
use PHPUnit\Framework\TestCase;

final class SkillTest extends TestCase
{
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/bia-skills-' . bin2hex(random_bytes(4));
        $this->home = "{$root}/home";
        $this->project = "{$root}/project";
        mkdir($this->home, 0777, true);
        mkdir($this->project, 0777, true);
    }

    public function testSkillsAreReadFromTheAgentsFolders(): void
    {
        $this->skill($this->home, 'release-notes', "---\nname: release-notes\ndescription: Writes release notes from the git log.\n---\n# Release notes\nRun `git log`.\n");
        $this->skill($this->home, 'deploy', "---\nname: deploy\ndescription: \"Deploys to staging: the user's way\"\n---\nGlobal steps.\n");
        $this->skill($this->project, 'deploy', "---\nname: deploy\ndescription: >\n  Deploys this project,\n  with its own script.\n---\nProject steps.\n");
        $this->skill($this->home, 'notes', "---\nname: notes\n---\nNo description: no skill.\n");
        $this->skill($this->home, 'plain', "Just markdown, no frontmatter.\n");

        $skills = Catalog::for($this->home, $this->project)->all();
        self::assertSame(['deploy', 'release-notes'], array_keys($skills), 'A skill needs a description; files without frontmatter are not skills.');
        self::assertSame('Deploys this project, with its own script.', $skills['deploy']->description, "The project's takes the place of the user's, its folded description read whole.");
        self::assertSame('Project steps.', $skills['deploy']->instructions());

        self::assertSame([], Catalog::for($this->home . '/none', $this->project . '/none')->all(), 'No folders, no skills, and nothing to read.');
    }

    public function testTheToolListsSkillsAndLoadsThemAndTheirFiles(): void
    {
        $this->skill($this->home, 'release-notes', "---\nname: release-notes\ndescription: Writes release notes.\n---\nRead template.md, then write.\n");
        file_put_contents("{$this->home}/.agents/skills/release-notes/template.md", "## What changed\n");
        file_put_contents("{$this->home}/.agents/secret.txt", 'not the skill\'s');
        $tool = new SkillTool(Catalog::for($this->home, $this->project)->all());

        $definition = $tool->definition();
        self::assertSame('skill', $definition->name);
        self::assertStringContainsString('- release-notes: Writes release notes.', $definition->description, 'Only names and descriptions sit in front of the model.');

        $context = new ToolContext($this->project, 'session');
        $loaded = $tool->execute(['name' => 'release-notes'], $context);
        self::assertTrue($loaded->ok);
        self::assertStringStartsWith('Read template.md, then write.', $loaded->text, 'Its instructions, without the frontmatter.');
        self::assertStringContainsString($this->home . '/.agents/skills/release-notes', $loaded->text, 'And where its files are.');

        self::assertSame("## What changed\n", $tool->execute(['name' => 'release-notes', 'file' => 'template.md'], $context)->text);
        self::assertFalse($tool->execute(['name' => 'release-notes', 'file' => '../../secret.txt'], $context)->ok, "Nothing outside the skill's folder.");
        self::assertFalse($tool->execute(['name' => 'nope'], $context)->ok);
    }

    private function skill(string $root, string $name, string $content): void
    {
        $dir = "{$root}/.agents/skills/{$name}";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("{$dir}/SKILL.md", $content);
    }
}
