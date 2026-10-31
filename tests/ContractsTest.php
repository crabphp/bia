<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ContractsTest extends TestCase
{
    public function testMessagesAndToolDefinitionsProduceProviderData(): void
    {
        $request = new ModelRequest('test', [new Message('user', 'hello')]);
        self::assertSame([['role' => 'user', 'content' => 'hello']], $request->jsonMessages());

        $tool = new ToolDefinition('read_file', 'Read a file.', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ]);
        self::assertSame('function', $tool->openAi()['type']);
    }
}
