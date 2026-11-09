<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Context;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolResult;
use Bia\Memory\Memory;
use Bia\Memory\MemoryTool;
use Bia\Terminal\Summary;
use PHPUnit\Framework\TestCase;

final class MemoryTest extends TestCase
{
    private string $home;
    private string $project;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/bia-memory-' . bin2hex(random_bytes(4));
        mkdir($base . '/home', 0777, true);
        mkdir($base . '/shop/.git', 0777, true);
        mkdir($base . '/shop/src');
        $this->home = (string) realpath($base . '/home');
        $this->project = (string) realpath($base . '/shop');
        $this->now = new \DateTimeImmutable('2026-11-10 04:35');
    }

    private function memory(?string $workspace = null): Memory
    {
        return new Memory($this->home, $workspace ?? $this->project, fn (): \DateTimeImmutable => $this->now);
    }

    public function testTheDiaryIsAPageADayAndOnlyEverAddedTo(): void
    {
        $memory = $this->memory();
        self::assertSame('- 04:35 shop · Wants Crab from Packagist', $memory->note("Wants Crab\nfrom  Packagist"));
        $memory->note('Tests run with composer test');
        $this->now = new \DateTimeImmutable('2026-11-12 09:00');
        $memory->note('Released 0.1.4');

        self::assertSame(['2026-11-12', '2026-11-10'], $memory->days());
        self::assertSame("- 04:35 shop · Wants Crab from Packagist\n- 04:35 shop · Tests run with composer test", $memory->page('2026-11-10'));
        self::assertNull($memory->page('2026-11-11'));
        self::assertNull($memory->page('../config'));
    }

    public function testAFactAboutTheUserIsReplacedInPlaceAndForgotten(): void
    {
        $memory = $this->memory();
        $memory->saveAbout('Answers', 'Wants them terse');
        $memory->saveAbout('editor', 'Uses Neovim');
        $memory->saveAbout('answers', 'Wants them terse, no tables');

        self::assertSame(['answers' => 'Wants them terse, no tables', 'editor' => 'Uses Neovim'], $memory->about());
        self::assertTrue($memory->forgetAbout('editor'));
        self::assertFalse($memory->forgetAbout('editor'));
        self::assertSame("- answers: Wants them terse, no tables\n", file_get_contents($memory->aboutPath()));
    }

    public function testAProjectMemoryIsAFileAndALineOfTheIndexSharedAcrossTheRepository(): void
    {
        $memory = $this->memory($this->project . '/src');
        self::assertFalse($memory->save('crab-install', 'Crab comes from Packagist', "No sibling checkout.\n\nWhy: contributors."));
        $memory->save('crab-install', 'Crab comes from Packagist, locked', 'No sibling checkout.');
        $memory->save('release', 'How a release is cut', 'Tag, then CI builds.');

        // From the repository's root as from a folder of it.
        $root = $this->memory();
        self::assertSame($memory->projectDirectory(), $root->projectDirectory());
        self::assertStringStartsWith($this->home . '/projects/', $root->projectDirectory());
        self::assertSame(['crab-install' => 'Crab comes from Packagist, locked', 'release' => 'How a release is cut'], $root->memories());
        self::assertSame("[written 2026-11-10]\nNo sibling checkout.", $root->read('crab-install'));

        self::assertTrue($root->forget('crab-install'));
        self::assertFalse($root->forget('crab-install'));
        self::assertNull($root->read('crab-install'));
        self::assertSame(['release' => 'How a release is cut'], $root->memories());
        // Nothing of it is in the repository.
        self::assertSame(['.', '..', '.git', 'src'], scandir($this->project));
    }

    public function testAWorktreeSharesTheMemoryOfTheCheckoutItCameFrom(): void
    {
        $tree = dirname($this->project) . '/shop-fix';
        mkdir($tree);
        file_put_contents($tree . '/.git', "gitdir: {$this->project}/.git/worktrees/shop-fix\n");

        self::assertSame($this->project, Memory::root($tree));
        self::assertSame($this->memory()->projectDirectory(), $this->memory($tree)->projectDirectory());
    }

    public function testSearchFindsLinesOfTheDiaryAndOfTheProject(): void
    {
        $memory = $this->memory();
        $memory->note('Chose SQLite over files for sessions');
        $memory->save('storage', 'Where sessions are kept', 'One sqlite database, opened lazily.');

        self::assertSame(
            ['project storage: One sqlite database, opened lazily.', '2026-11-10: 04:35 shop · Chose SQLite over files for sessions'],
            $memory->search('SQLITE'),
        );
        self::assertSame([], $memory->search('postgres'));
    }

    public function testARuleIsWrittenIntoTheProjectsInstructionsOnce(): void
    {
        $memory = $this->memory($this->project . '/src');
        $first = $memory->rule('Always run composer test before a commit');
        self::assertSame($this->project . '/AGENTS.md', $first['path']);
        self::assertSame("# Rules\n\n- Always run composer test before a commit\n", file_get_contents($first['path']));
        self::assertNotSame([], $first['diff']);

        self::assertSame([], $memory->rule('Always run composer test before a commit')['diff']);

        file_put_contents($first['path'], "# Shop\n\nA shop.\n\n## Rules\n\n- Always run composer test before a commit\n\n## Checks\n\nRun the tests.\n");
        $memory->rule('Never push to main');
        self::assertSame(
            "# Shop\n\nA shop.\n\n## Rules\n\n- Always run composer test before a commit\n- Never push to main\n\n## Checks\n\nRun the tests.\n",
            file_get_contents($first['path']),
        );
    }

    public function testARuleGoesIntoClaudeMdWhereThatIsTheFileTheProjectHas(): void
    {
        file_put_contents($this->project . '/CLAUDE.md', "# Shop\n");
        $rule = $this->memory()->rule('Never push to main');

        self::assertSame($this->project . '/CLAUDE.md', $rule['path']);
        self::assertSame("# Shop\n\n## Rules\n\n- Never push to main\n", file_get_contents($rule['path']));
        self::assertFileDoesNotExist($this->project . '/AGENTS.md');
    }

    public function testThePromptCarriesTheFactsTheLastTwoPagesAndTheIndex(): void
    {
        $memory = $this->memory();
        self::assertStringNotContainsString('## ', $memory->prompt());

        $memory->saveAbout('answers', 'Wants them terse');
        $memory->note('First day');
        $this->now = new \DateTimeImmutable('2026-11-12 09:00');
        $memory->note('Second day');
        $this->now = new \DateTimeImmutable('2026-11-16 09:00');
        $memory->note('Third day');
        $memory->save('release', 'How a release is cut', 'Tag, then CI builds: the secret part.');
        $prompt = $memory->prompt();

        self::assertStringContainsString("## About the user\n\n- answers: Wants them terse", $prompt);
        self::assertStringContainsString("2026-11-12\n- 09:00 shop · Second day\n\n2026-11-16\n- 09:00 shop · Third day", $prompt);
        self::assertStringNotContainsString('First day', $prompt);
        self::assertStringContainsString("## This project's memories\n\n- [release](release.md) — How a release is cut", $prompt);
        self::assertStringNotContainsString('the secret part', $prompt);
    }

    public function testALongDiaryKeepsItsLatestEntriesInThePrompt(): void
    {
        $memory = $this->memory();
        for ($entry = 1; $entry <= 200; $entry++) {
            $memory->note("Entry {$entry} " . str_repeat('x', 40));
        }
        $prompt = $memory->prompt();

        self::assertStringContainsString('[earlier entries left out]', $prompt);
        self::assertStringContainsString('Entry 200 ', $prompt);
        self::assertStringNotContainsString('Entry 1 ', $prompt);
        self::assertLessThan(Memory::DIARY_BYTES + 4096, strlen($prompt));
    }

    public function testTheToolKeepsRecallsAndRefuses(): void
    {
        $tool = new MemoryTool($this->memory());
        $context = new ToolContext($this->project, 'session');
        $run = static fn (array $arguments): ToolResult => $tool->execute($arguments, $context);

        self::assertSame('04:35 shop · Chose SQLite', $run(['action' => 'note', 'text' => 'Chose SQLite'])->text);
        self::assertTrue($run(['action' => 'save', 'scope' => 'user', 'name' => 'answers', 'text' => 'Terse'])->ok);
        self::assertSame('Where sessions are kept', $run(['action' => 'save', 'name' => 'storage', 'description' => 'Where sessions are kept', 'text' => 'SQLite.'])->text);
        self::assertSame("[written 2026-11-10]\nSQLite.", $run(['action' => 'read', 'name' => 'storage'])->text);
        self::assertSame('- 04:35 shop · Chose SQLite', $run(['action' => 'read', 'name' => '2026-11-10'])->text);
        self::assertStringContainsString('project storage: SQLite.', $run(['action' => 'search', 'query' => 'sqlite'])->text);

        $rule = $run(['action' => 'rule', 'text' => 'Never push to main']);
        self::assertSame('AGENTS.md', $rule->data['path']);
        self::assertContains('+- Never push to main', $rule->data['diff']);
        self::assertSame('That rule is there already.', $run(['action' => 'rule', 'text' => 'Never push to main'])->text);

        self::assertTrue($run(['action' => 'forget', 'name' => 'storage'])->ok);
        self::assertFalse($run(['action' => 'forget', 'name' => 'storage'])->ok);
        self::assertFalse($run(['action' => 'read', 'name' => '2026-11-09'])->ok);
        self::assertFalse($run(['action' => 'save', 'name' => 'storage', 'text' => 'No description.'])->ok);
        self::assertFalse($run(['action' => 'save', 'name' => '../../etc', 'description' => 'x', 'text' => ''])->ok);
        self::assertFalse($run(['action' => 'save', 'name' => 'MEMORY', 'description' => 'x', 'text' => 'x'])->ok);
        self::assertFalse($run(['action' => 'burn'])->ok);
    }

    public function testAnOverlongIndexAsksToBeShortened(): void
    {
        $memory = $this->memory();
        $long = false;
        for ($entry = 0; $entry <= Memory::INDEX_LINES && !$long; $entry++) {
            $long = $memory->save("fact-{$entry}", "Fact {$entry}", 'Text.');
        }
        self::assertTrue($long);
        $tool = new MemoryTool($memory);
        $result = $tool->execute(['action' => 'save', 'name' => 'one-more', 'description' => 'One more', 'text' => 'Text.'], new ToolContext($this->project, 'session'));
        self::assertStringContainsString('the index is longer than what is read', $result->text);
    }

    public function testARuleLaidDownIsRemindedOfBesideTheMessageAndNeverInItsText(): void
    {
        self::assertNull(Memory::reminder('fix the login test'));
        self::assertNull(Memory::reminder('the mustard is in the alwayson folder'));
        foreach (['You MUST use tabs here', 'always run the tests first', "don't ever push to main", 'From now on, answer in Vietnamese', 'we never mock the database'] as $prompt) {
            self::assertStringContainsString('action rule', (string) Memory::reminder($prompt), $prompt);
        }

        $stored = new Message('user', 'always run the tests first', meta: ['reminder' => (string) Memory::reminder('always run the tests first')]);
        $context = Context::build([
            new Entry(1, null, $stored),
            new Entry(2, 1, new Message('assistant', 'Noted.')),
            new Entry(3, 2, Message::user('thanks')),
        ]);
        $sent = $context->messages();

        self::assertSame('always run the tests first', $stored->text);
        self::assertStringStartsWith("always run the tests first\n\n[Bia: the user said \"always\".", $sent[0]->text);
        self::assertSame('thanks', $sent[2]->text);
        // Kept with the message, so a resumed session sends the same.
        self::assertSame($sent[0]->text, Context::build([new Entry(1, null, Message::fromArray($stored->toArray()))])->messages()[0]->text);
    }

    public function testASummaryCountsWhatWasKept(): void
    {
        $call = static fn (array $arguments): array => [new ToolCall('c', 'memory', $arguments), new ToolResult('ok')];
        $line = (string) preg_replace('/\e\[[0-9;]*m/', '', Summary::line([
            $call(['action' => 'note', 'text' => 'a']),
            $call(['action' => 'save', 'name' => 'b', 'text' => 'b']),
            $call(['action' => 'rule', 'text' => 'c']),
            $call(['action' => 'forget', 'name' => 'd']),
            $call(['action' => 'search', 'query' => 'e']),
        ]));

        self::assertSame('• Added 1 rule, saved 2 memories, forgot 1 memory, recalled once', $line);
    }
}
