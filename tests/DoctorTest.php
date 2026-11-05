<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Cli\Doctor;
use Bia\Config\Config;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Model\ModelException;
use Bia\Tui\Style;
use Crab\Engine\Async\Cancellation;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        // Never the real ~/.config/bia, which holds the developer's keys, nor their database.
        $this->directory = sys_get_temp_dir() . '/bia-doctor-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/bia', 0777, true);
        putenv('XDG_CONFIG_HOME=' . $this->directory);
        putenv('XDG_STATE_HOME=' . $this->directory . '/state');
        Style::enable(false);
    }

    protected function tearDown(): void
    {
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_STATE_HOME');
        putenv('BIA_API_KEY');
        putenv('BIA_MODEL');
        putenv('BIA_PROVIDER');
        putenv('BIA_BASE_URL');
    }

    public function testEachModelIsAskedForRealAndWhatIsWrongSaysWhatToDo(): void
    {
        putenv('BIA_PROVIDER=openai');
        putenv('BIA_MODEL=good');
        putenv('BIA_BASE_URL=https://api.example.test/v1');
        putenv('BIA_API_KEY=k');
        file_put_contents($this->directory . '/bia/config.php', '<?php return ["fallback" => ["models" => ["bad"]]];');
        $asked = [];
        $model = static function (Config $config) use (&$asked): Model {
            return new class ((string) $config->get('model.name'), $asked) implements Model {
                public function __construct(private string $name, private array &$asked) {}

                public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
                {
                    $this->asked[] = $this->name;
                    if ($this->name === 'bad') {
                        throw new ModelException('POST https://api.example.test/v1/chat/completions failed: The model provider answered 401: Invalid API key', 401);
                    }
                    return new ModelResult('OK');
                }
            };
        };
        [$code, $out] = $this->doctor($model);
        self::assertSame(['good', 'bad'], $asked, 'The model and each fallback are asked.');
        self::assertMatchesRegularExpression('/✓ model\s+openai\/good · answered in \d+ ms/', $out);
        self::assertStringContainsString('✗ fallback     openai/bad · The model provider answered 401: Invalid API key · check the API key', $out);
        self::assertStringContainsString("\n1 problem\n", $out);
        self::assertSame(1, $code);
    }

    public function testWithoutAKeyNothingIsAskedAndItSaysWhatToSet(): void
    {
        putenv('BIA_PROVIDER=openai');
        putenv('BIA_MODEL=good');
        putenv('BIA_BASE_URL=https://api.example.test/v1');
        $model = static fn (Config $config): Model => throw new \LogicException('Not asked without a key.');
        [$code, $out] = $this->doctor($model);
        self::assertMatchesRegularExpression('/✗ model\s+openai\/good · no API key · set .*BIA_API_KEY/', $out);
        self::assertSame(1, $code);
    }

    public function testABrokenConfigIsSaidAndStopsThere(): void
    {
        file_put_contents($this->directory . '/bia/config.php', '<?php return ["tui" => ["render" => "wild"]];');
        [$code, $out] = $this->doctor(static fn (Config $config): Model => throw new \LogicException('Not reached.'));
        self::assertStringContainsString('✗ config       tui.render must be normal, smooth, or power.', $out);
        self::assertStringNotContainsString('model', $out);
        self::assertSame(1, $code);
    }

    /**
     * @param \Closure(Config): Model $model
     * @return array{int, string}
     */
    private function doctor(\Closure $model): array
    {
        $out = fopen('php://memory', 'w+');
        $code = (new Doctor($model, static fn (Config $config): array => ['manager' => null, 'untrusted' => null, 'errors' => []], $out))->run();
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }
}
