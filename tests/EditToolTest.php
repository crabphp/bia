<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ToolContext;
use Bia\Tool\EditTool;
use Bia\Tool\Workspace;
use PHPUnit\Framework\TestCase;

final class EditToolTest extends TestCase
{
    private string $root;
    private EditTool $edit;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bia-edit-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->root = (string) realpath($this->root);
        $this->edit = new EditTool(new Workspace($this->root));
        $this->context = new ToolContext($this->root, 'test');
    }

    public function testSeveralEditsApplyAgainstTheOriginal(): void
    {
        $this->file('a.php', "<?php\nfunction one() { return 1; }\nfunction two() { return 2; }\n");

        $result = $this->apply('a.php', [
            ['old_text' => 'return 1;', 'new_text' => 'return 10;'],
            ['old_text' => "function two() { return 2; }\n", 'new_text' => "function two() { return 20; }\nfunction three() {}\n"],
        ]);

        self::assertTrue($result->ok, $result->text);
        self::assertSame("<?php\nfunction one() { return 10; }\nfunction two() { return 20; }\nfunction three() {}\n", $this->read('a.php'));
        self::assertStringContainsString('line 2, 3', $result->text);
    }

    public function testAnAmbiguousMatchIsRefusedUnlessAllAreAsked(): void
    {
        $this->file('b.txt', "x = 1\nx = 1\n");

        self::assertFalse($this->apply('b.txt', [['old_text' => 'x = 1', 'new_text' => 'x = 2']])->ok);
        self::assertSame("x = 1\nx = 1\n", $this->read('b.txt'));

        self::assertTrue($this->apply('b.txt', [['old_text' => 'x = 1', 'new_text' => 'x = 2', 'replace_all' => true]])->ok);
        self::assertSame("x = 2\nx = 2\n", $this->read('b.txt'));
    }

    public function testTypographicCharactersAndTrailingSpacesStillMatch(): void
    {
        $this->file('c.md', "He said \u{201C}hi\u{201D} \u{2014} then left.   \nNext line\n");

        $result = $this->apply('c.md', [['old_text' => "He said \"hi\" - then left.\nNext", 'new_text' => "He said hello.\nNext"]]);

        self::assertTrue($result->ok, $result->text);
        self::assertSame("He said hello.\nNext line\n", $this->read('c.md'));
    }

    public function testLineEndingsAndModeAreKept(): void
    {
        $this->file('d.sh', "#!/bin/sh\r\necho one\r\n");
        chmod($this->root . '/d.sh', 0755);

        self::assertTrue($this->apply('d.sh', [['old_text' => "echo one\n", 'new_text' => "echo two\necho three\n"]])->ok);
        clearstatcache();

        self::assertSame("#!/bin/sh\r\necho two\r\necho three\r\n", $this->read('d.sh'));
        self::assertSame(0755, fileperms($this->root . '/d.sh') & 0777);

        $this->file('mixed.txt', "one\r\ntwo\nthree\n");
        self::assertTrue($this->apply('mixed.txt', [['old_text' => "two\n", 'new_text' => "2\n"]])->ok);
        self::assertSame("one\n2\nthree\n", $this->read('mixed.txt'), 'A single CRLF line does not convert an LF file to CRLF.');
    }

    public function testMistakesAreExplainedAndChangeNothing(): void
    {
        $this->file('e.txt', "alpha beta\n");

        self::assertStringContainsString('not found', $this->apply('e.txt', [['old_text' => 'gamma', 'new_text' => 'x']])->text);
        self::assertStringContainsString('overlap', $this->apply('e.txt', [
            ['old_text' => 'alpha beta', 'new_text' => 'x'],
            ['old_text' => 'beta', 'new_text' => 'y'],
        ])->text);
        self::assertStringContainsString('write_file', $this->apply('missing.txt', [['old_text' => 'a', 'new_text' => 'b']])->text);
        self::assertSame("alpha beta\n", $this->read('e.txt'));
    }

    /**
     * @param list<array<string, mixed>> $edits
     */
    private function apply(string $path, array $edits): \Bia\Contracts\ToolResult
    {
        return $this->edit->execute(['path' => $path, 'edits' => $edits], $this->context);
    }

    private function file(string $name, string $content): void
    {
        file_put_contents($this->root . '/' . $name, $content);
    }

    private function read(string $name): string
    {
        return (string) file_get_contents($this->root . '/' . $name);
    }
}
