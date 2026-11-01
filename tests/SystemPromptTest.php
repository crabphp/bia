<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\SystemPrompt;
use PHPUnit\Framework\TestCase;

final class SystemPromptTest extends TestCase
{
    public function testInstructionsAreReadFromTheRepositoryRootDown(): void
    {
        $root = sys_get_temp_dir() . '/bia-prompt-' . bin2hex(random_bytes(4));
        mkdir($root . '/.git', 0777, true);
        mkdir($root . '/packages/api', 0777, true);
        mkdir($root . '/global');
        $root = (string) realpath($root);
        file_put_contents($root . '/.git/HEAD', "ref: refs/heads/feature-x\n");
        file_put_contents($root . '/global/AGENTS.md', 'Global rule.');
        file_put_contents($root . '/AGENTS.md', 'Root rule.');
        file_put_contents($root . '/packages/CLAUDE.md', 'Packages rule from CLAUDE.md.');
        file_put_contents($root . '/packages/api/AGENTS.md', 'API rule.');
        file_put_contents($root . '/packages/api/CLAUDE.md', 'Ignored: AGENTS.md wins in the same directory.');

        $prompt = SystemPrompt::build('You are Bia.', $root . '/packages/api', $root . '/global');

        self::assertStringStartsWith('You are Bia.', $prompt);
        $order = array_map(static fn (string $rule): int|false => strpos($prompt, $rule), ['Global rule.', 'Root rule.', 'Packages rule', 'API rule.']);
        self::assertNotContains(false, $order);
        self::assertSame($order, array_values(array_unique($order)));
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'Instructions are not ordered from general to specific.');
        self::assertStringNotContainsString('Ignored', $prompt);
        self::assertStringContainsString('Git branch: feature-x', $prompt);
        self::assertStringContainsString('Working directory: ' . $root . '/packages/api', $prompt);
    }

    public function testInstructionsAreCapped(): void
    {
        $root = sys_get_temp_dir() . '/bia-prompt-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root . '/AGENTS.md', str_repeat('rule ', 10_000));

        $prompt = SystemPrompt::build('', (string) realpath($root), maxInstructionBytes: 1000);

        self::assertStringContainsString('[instructions cut at 1000 bytes]', $prompt);
        self::assertLessThan(4000, strlen($prompt));
    }
}
