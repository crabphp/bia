<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolDefinition;
use Bia\Model\OpenAiCompatibleModel;
use PHPUnit\Framework\TestCase;

final class ContractsTest extends TestCase
{
    public function testNeutralMessagesBecomeChatCompletions(): void
    {
        $call = new ToolCall('c1', 'git_diff');
        $wire = OpenAiCompatibleModel::messages([
            Message::user('hello'),
            new Message('assistant', '', [$call], reasoning: 'private thoughts'),
            Message::toolResult($call, 'no changes', true),
        ]);

        self::assertSame(['role' => 'user', 'content' => 'hello'], $wire[0]);
        self::assertNull($wire[1]['content']);
        self::assertSame('{}', $wire[1]['tool_calls'][0]['function']['arguments'], 'Empty arguments must be a JSON object.');
        self::assertArrayNotHasKey('reasoning', $wire[1]);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'no changes'], $wire[2]);
    }

    public function testMessagesSurviveStorage(): void
    {
        $message = new Message('assistant', 'text', [new ToolCall('c', 'edit', ['path' => 'a'])], 'thinking', meta: ['usage' => ['input' => 3]]);

        self::assertEquals($message, Message::fromArray(json_decode(json_encode($message->toArray()), true)));
        self::assertSame('function', (new ToolDefinition('read_file', 'Read.', ['type' => 'object']))->openAi()['type']);
    }
}
