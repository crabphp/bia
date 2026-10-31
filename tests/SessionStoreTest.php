<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Session\SqliteStore;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    public function testMessagesAreDurableAndOrdered(): void
    {
        $path = sys_get_temp_dir() . '/bia-session-' . bin2hex(random_bytes(4)) . '.sqlite';
        $store = new SqliteStore($path);
        $id = $store->create('/workspace', 'test-model');
        $store->append($id, new Message('user', 'hello'));
        $store->append($id, new Message('assistant', 'hi', ['finish_reason' => 'stop']));
        $store->close();

        $messages = (new SqliteStore($path))->messages($id);
        self::assertCount(2, $messages);
        self::assertSame('hello', $messages[0]->content);
        self::assertSame('hi', $messages[1]->content);
        self::assertSame(['finish_reason' => 'stop'], $messages[1]->metadata);
    }
}
