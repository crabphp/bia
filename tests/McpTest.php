<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\ToolContext;
use Bia\Mcp\AuthStore;
use Bia\Mcp\Manager;
use Bia\Mcp\McpTool;
use Bia\Mcp\Server;
use Bia\Mcp\Trust;
use Bia\Tool\ProcessRunner;
use Bia\Tool\Registry;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Process\Process;
use Crab\HttpClient\Client;
use PHPUnit\Framework\TestCase;

final class McpTest extends TestCase
{
    protected function setUp(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
    }

    public function testServersAreReadFromTheSharedFormat(): void
    {
        $file = $this->file(json_encode(['mcpServers' => [
            'local' => ['command' => 'node', 'args' => ['server.js', '--token=${TOKEN}'], 'env' => ['MODE' => '${MODE:-dev}']],
            'remote' => ['type' => 'http', 'url' => 'https://example.test/mcp', 'headers' => ['Authorization' => 'Bearer ${TOKEN}']],
            'old' => ['type' => 'sse', 'url' => 'https://example.test/sse'],
            'off' => ['command' => 'x', 'disabled' => true],
            'bad name!' => ['command' => 'x'],
        ]]));
        $loaded = Server::load($file, project: true, environment: ['TOKEN' => 's3cret']);
        [$local, $remote] = $loaded['servers'];
        self::assertSame(['stdio', 'node', ['server.js', '--token=s3cret'], ['MODE' => 'dev'], true], [$local->type, $local->command, $local->args, $local->env, $local->project], '${VAR} and ${VAR:-default} are taken from the environment.');
        self::assertSame(['http', 'https://example.test/mcp', ['Authorization' => 'Bearer s3cret']], [$remote->type, $remote->url, $remote->headers]);
        self::assertCount(2, $loaded['servers'], 'A disabled server is left out.');
        self::assertSame(["old: the old SSE transport is not supported; use the server's HTTP endpoint", 'bad name!: not a server entry'], $loaded['errors']);
    }

    public function testAServersToolsAreUsedAsBiasOwn(): void
    {
        $manager = new Manager(new ProcessRunner(), new Client(), sys_get_temp_dir(), 'test', timeout: 2.0, startup: 5.0);
        $manager->add(new Server('fixture', 'stdio', PHP_BINARY, [__DIR__ . '/Fixtures/mcp-server.php']));
        $changes = [];
        $manager->start(static function (string $name, string $status) use (&$changes): void {
            $changes[] = "{$name}:{$status}";
        });
        $manager->ready(5.0);

        self::assertSame(['fixture:connecting', 'fixture:connected'], $changes, 'Connected in the background, past its noise and its ping.');
        self::assertSame([['name' => 'fixture', 'status' => 'connected', 'error' => '', 'tools' => 5, 'project' => false]], $manager->status(), 'Both pages of its tools.');

        $registry = (new Registry())->from($manager->tools(...));
        $names = array_map(static fn ($d): string => $d->name, $registry->definitions());
        self::assertSame(['mcp__fixture__echo', 'mcp__fixture__fail', 'mcp__fixture__slow', 'mcp__fixture__grow', 'mcp__fixture__crash'], $names);
        self::assertSame('mcp', $registry->permission('mcp__fixture__echo'));

        $context = new ToolContext(sys_get_temp_dir(), 'session');
        $result = $registry->execute('mcp__fixture__echo', ['text' => 'hi'], $context);
        self::assertTrue($result->ok);
        self::assertSame("echo: hi\n[an image, image/png]", $result->text);

        $failed = $registry->execute('mcp__fixture__fail', [], $context);
        self::assertFalse($failed->ok, "The server's own failure is the tool's.");
        self::assertSame('it went wrong', $failed->text);

        $slow = $registry->execute('mcp__fixture__slow', [], $context);
        self::assertFalse($slow->ok);
        self::assertStringContainsString('no answer to tools/call within 2 s', $slow->text, 'A call that takes too long fails, not hangs.');

        $cancellation = new Cancellation();
        EventLoop::current()->delay(0.1, static fn () => $cancellation->cancel());
        try {
            $registry->execute('mcp__fixture__slow', [], $context, $cancellation);
            self::fail('A cancelled call is cancelled.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        $manager->close();
        self::assertSame([], $manager->tools(), 'Closed, its tools are gone.');
        usleep(100_000);
        self::assertSame('', trim((string) shell_exec('pgrep -f ' . escapeshellarg('Fixtures/mcp-server.ph[p]'))), 'No server outlives it, even one busy with a slow call.');
    }

    public function testAServersNewToolsAreOfferedAsItSaysTheyChanged(): void
    {
        $manager = $this->fixture();
        $context = new ToolContext(sys_get_temp_dir(), 'session');
        $registry = (new Registry())->from($manager->tools(...));
        self::assertTrue($registry->execute('mcp__fixture__grow', [], $context)->ok);
        $this->wait(static fn (): bool => count($manager->tools()) === 6);
        self::assertContains('mcp__fixture__extra', array_map(static fn ($d): string => $d->name, $registry->definitions()), 'Listed again as it said its tools changed.');
        $manager->close();
    }

    public function testAServerLostIsConnectedAgainAndRestartedOnRequest(): void
    {
        $manager = $this->fixture($changes);
        $context = new ToolContext(sys_get_temp_dir(), 'session');
        $registry = (new Registry())->from($manager->tools(...));
        $crashed = $registry->execute('mcp__fixture__crash', [], $context);
        self::assertFalse($crashed->ok);
        self::assertStringContainsString('fixture server crashed', $crashed->text, 'The call in flight fails, saying why.');
        self::assertSame('connecting', $manager->status()[0]['status']);
        self::assertSame([], $manager->tools(), 'Its tools are gone while it is.');

        $this->wait(static fn (): bool => $manager->status()[0]['status'] === 'connected');
        self::assertSame(['fixture:connecting', 'fixture:connected', 'fixture:connecting', 'fixture:connected'], $changes, 'Connected again on its own.');
        self::assertTrue($registry->execute('mcp__fixture__echo', ['text' => 'back'], $context)->ok, 'Its tools work again.');

        $manager->restart('fixture');
        $this->wait(static fn (): bool => $manager->status()[0]['status'] === 'connected');
        self::assertSame(['fixture:off', 'fixture:connecting', 'fixture:connected'], array_slice($changes, 4), 'Restarted on request, and not taken for one lost.');
        self::assertSame(1, (int) trim((string) shell_exec('pgrep -fc ' . escapeshellarg('Fixtures/mcp-server.ph[p]'))), 'The one restarted is stopped.');
        $manager->close();
    }

    public function testAServerThatKeepsFallingOverFails(): void
    {
        $manager = new Manager(new ProcessRunner(), new Client(), sys_get_temp_dir(), 'test', timeout: 2.0, startup: 2.0, retries: [0.1, 0.1, 0.1]);
        // Up long enough to connect, then gone, every time.
        $manager->add(new Server('flaky', 'stdio', PHP_BINARY, ['-r', '$l = fgets(STDIN); $m = json_decode($l, true); echo json_encode(["jsonrpc" => "2.0", "id" => $m["id"], "result" => []]), "\n"; fgets(STDIN); $l = fgets(STDIN); $m = json_decode($l, true); echo json_encode(["jsonrpc" => "2.0", "id" => $m["id"], "result" => ["tools" => []]]), "\n"; usleep(50000); fwrite(STDERR, "down again\n"); exit(1);']));
        $changes = [];
        $manager->start(static function (string $name, string $status) use (&$changes): void {
            $changes[] = $status;
        });
        $this->wait(static fn (): bool => $manager->status()[0]['status'] === 'failed');
        self::assertSame([...array_merge(...array_fill(0, 1 + 3, ['connecting', 'connected'])), 'failed'], $changes, 'Tried again ' . 3 . ' times, then failed.');
        self::assertStringContainsString('down again', $manager->status()[0]['error']);
    }

    public function testAServerThatCannotStartFailsAloneAndSaysWhy(): void
    {
        $manager = new Manager(new ProcessRunner(), new Client(), sys_get_temp_dir(), 'test', timeout: 2.0, startup: 2.0);
        $manager->add(new Server('broken', 'stdio', '/bin/sh', ['-c', 'echo "missing API key" >&2; exit 1']));
        $manager->start();
        $manager->ready(3.0);
        [$status] = $manager->status();
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('missing API key', $status['error'], 'What it said on standard error says why.');
    }

    public function testARemoteServerIsLoggedInToWithOAuth(): void
    {
        $dir = sys_get_temp_dir() . '/bia-oauth-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $server = Process::start([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/Fixtures/oauth-server.php'], environment: [...getenv(), 'OAUTH_STATE' => $dir]);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(50_000);
            }
            $store = new AuthStore("{$dir}/auth.json");
            $manager = new Manager(new ProcessRunner(), new Client(), sys_get_temp_dir(), 'test', timeout: 5.0, startup: 5.0, authStore: $store);
            $manager->add(new Server('secure', 'http', url: "http://127.0.0.1:{$port}/mcp"));
            $manager->start();
            $manager->ready(5.0);
            self::assertSame('login', $manager->status()[0]['status'], 'Refused without a token, it needs a login: not a failure.');

            // The browser: follows the server's redirect back to Bia's listener on 127.0.0.1.
            $browser = new Client();
            $manager->login('secure', static function (string $url) use ($browser): void {
                Task::spawn(EventLoop::current(), static fn () => $browser->request('GET', $url));
            });
            $manager->ready(5.0);
            self::assertSame('connected', $manager->status()[0]['status'], 'Logged in, it connects.');
            $context = new ToolContext(sys_get_temp_dir(), 'session');
            $result = (new Registry())->from($manager->tools(...))->execute('mcp__secure__whoami', [], $context);
            self::assertTrue($result->ok);
            self::assertMatchesRegularExpression('/^logged in, [1-9]\d* refreshes so far$/', $result->text, 'A token about to run out is refreshed before it is used.');
            self::assertSame(0600, fileperms("{$dir}/auth.json") & 0777, 'Tokens are kept where only the user can read them.');

            $manager->logout('secure');
            self::assertSame('login', $manager->status()[0]['status']);
            self::assertSame([], $store->get(AuthStore::key(new Server('secure', 'http', url: "http://127.0.0.1:{$port}/mcp"))), 'Logged out, the tokens are forgotten.');

            // A browser elsewhere, as over SSH: the address it is sent back to is pasted into Bia.
            $elsewhere = new Client(redirects: 0);
            $manager->login('secure', function (string $url) use ($elsewhere, $manager): void {
                Task::spawn(EventLoop::current(), function () use ($elsewhere, $manager, $url): void {
                    $back = $elsewhere->request('GET', $url)->getHeaderLine('location');
                    self::assertFalse($manager->deliver('http://127.0.0.1:1/callback?code=x'), 'An address not this login\'s is not taken.');
                    self::assertTrue($manager->deliver($back));
                });
            });
            $manager->ready(5.0);
            self::assertSame('connected', $manager->status()[0]['status'], 'A pasted address logs in too.');
            $manager->close();
        } finally {
            $server->kill();
        }
    }

    public function testToolNamesSuitEveryProvider(): void
    {
        self::assertSame('mcp__git_hub__search_issues', McpTool::name('git.hub', 'search issues'));
        $long = McpTool::name('server', str_repeat('a', 80));
        self::assertSame(64, strlen($long), 'Cut to the 64 characters providers allow, and kept apart by a hash.');
        self::assertNotSame($long, McpTool::name('server', str_repeat('a', 79) . 'b'));
    }

    public function testAProjectsServersRunOnlyAsTheyWereTrusted(): void
    {
        $file = $this->file('{"mcpServers": {}}');
        $trust = new Trust(sys_get_temp_dir() . '/bia-trust-' . bin2hex(random_bytes(4)) . '/trust.json');
        self::assertFalse($trust->trusted($file));
        $trust->trust($file);
        self::assertTrue($trust->trusted($file));
        file_put_contents($file, '{"mcpServers": {"evil": {"command": "rm"}}}');
        self::assertFalse($trust->trusted($file), 'A changed file is asked about again.');
    }

    /**
     * The fixture server, connected.
     *
     * @param list<string>|null $changes
     */
    private function fixture(?array &$changes = null): Manager
    {
        $changes = [];
        $manager = new Manager(new ProcessRunner(), new Client(), sys_get_temp_dir(), 'test', timeout: 2.0, startup: 5.0);
        $manager->add(new Server('fixture', 'stdio', PHP_BINARY, [__DIR__ . '/Fixtures/mcp-server.php']));
        $manager->start(static function (string $name, string $status) use (&$changes): void {
            $changes[] = "{$name}:{$status}";
        });
        $manager->ready(5.0);
        return $manager;
    }

    /**
     * Runs the loop until the condition holds, or the time is up.
     *
     * @param \Closure(): bool $condition
     */
    private function wait(\Closure $condition, float $seconds = 5.0): void
    {
        $loop = EventLoop::current();
        $until = microtime(true) + $seconds;
        while (!$condition() && microtime(true) < $until) {
            $suspension = $loop->getSuspension();
            $loop->delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
        }
        self::assertTrue($condition(), 'In time.');
    }

    private function file(string $content): string
    {
        $path = sys_get_temp_dir() . '/bia-mcp-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $content);
        return $path;
    }
}
