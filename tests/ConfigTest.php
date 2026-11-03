<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Never read the real ~/.config/bia: it holds the developer's keys.
        putenv('XDG_CONFIG_HOME=' . $this->directory());
    }

    protected function tearDown(): void
    {
        putenv('XDG_CONFIG_HOME');
    }

    public function testProjectSettingsAndOverridesAreMerged(): void
    {
        $root = $this->project([
            'model' => ['name' => 'project-model'],
            'prompt' => ['system' => 'Project prompt.'],
            'permissions' => ['shell' => 'ask'],
        ]);

        $config = Config::load($root, ['model' => ['name' => 'override-model']]);

        self::assertSame('override-model', $config->get('model.name'));
        self::assertSame('Project prompt.', $config->get('prompt.system'));
        self::assertSame('ask', $config->get('permissions.shell'));
        self::assertContains($root . '/.agents/bia.json', $config->sources());
    }

    public function testToolsRunWithoutAskingByDefault(): void
    {
        $config = Config::load($this->directory());

        self::assertSame(['read' => 'auto', 'write' => 'auto', 'shell' => 'auto', 'mcp' => 'auto'], $config->get('permissions'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('permissions.mcp');
        Config::load($this->directory(), ['permissions' => ['mcp' => 'maybe']]);
    }

    public function testSecretsIncludeTheModelAndEveryProviderKey(): void
    {
        $this->global("<?php return ['providers' => ['tda' => ['base_url' => 'http://tda/v1', 'api_key' => 'tda-key']]];");
        putenv('OPENAI_API_KEY=openai-key');
        putenv('ANTHROPIC_API_KEY=anthropic-key');
        try {
            $secrets = Config::load($this->directory())->secrets();
            self::assertContains('openai-key', $secrets);
            self::assertContains('anthropic-key', $secrets);
            self::assertContains('tda-key', $secrets);
        } finally {
            putenv('OPENAI_API_KEY');
            putenv('ANTHROPIC_API_KEY');
        }
    }

    public function testAChoiceMadeInAConversationIsKeptInTheConfigFileAsWritten(): void
    {
        $config = $this->directory();
        putenv("XDG_CONFIG_HOME={$config}");
        try {
            mkdir($config . '/bia', 0777, true);
            $file = $config . '/bia/config.php';
            file_put_contents($file, "<?php\n\nreturn [\n    // my model\n    'model' => [\n        'name' => 'openai/gpt-5', // the usual\n    ],\n];\n");

            Config::remember('model.name', 'anthropic/claude-sonnet-4-5');
            Config::remember('tui.render', 'power');
            $loaded = Config::load($this->directory());
            self::assertSame('anthropic/claude-sonnet-4-5', $loaded->modelLabel());
            self::assertSame('power', $loaded->get('tui.render'));
            self::assertSame(
                "<?php\n\nreturn [\n    'tui' => ['render' => 'power'],\n    // my model\n    'model' => [\n        'name' => 'anthropic/claude-sonnet-4-5', // the usual\n    ],\n];\n",
                file_get_contents($file),
                'One value changed, one added; the comments and layout as they were.',
            );
            $this->expectException(\InvalidArgumentException::class);
            Config::remember('model.base_url', 'https://elsewhere.example');
        } finally {
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testTheReasoningEffortIsCheckedAndKeptAcrossAModelSwitch(): void
    {
        $config = Config::load($this->directory());
        self::assertNull($config->effort(), 'By default no effort is sent.');

        $high = $config->withEffort('high');
        self::assertSame('high', $high->effort());
        self::assertSame('high', $high->withModel('anthropic/claude-sonnet-4-5')->effort());
        self::assertSame('low', Config::load($this->directory(), ['model' => ['reasoning_effort' => 'low']])->effort());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('model.reasoning_effort');
        $config->withEffort('extreme');
    }

    public function testTheHomeDirectoryIsNeverTheProject(): void
    {
        $home = sys_get_temp_dir() . '/bia-home-' . bin2hex(random_bytes(4));
        mkdir("{$home}/.agents/skills", 0700, true);
        mkdir("{$home}/work/sub", 0700, true);
        file_put_contents("{$home}/.agents/bia.json", json_encode(['model' => ['name' => 'from-home']]));
        mkdir("{$home}/work/.agents", 0700, true);
        file_put_contents("{$home}/work/.agents/bia.json", json_encode(['model' => ['name' => 'from-project']]));
        [$oldHome, $oldCwd] = [getenv('HOME'), getcwd()];
        putenv("HOME={$home}");
        chdir("{$home}/work/sub");
        try {
            $config = Config::load();
            self::assertContains("{$home}/work/.agents/bia.json", $config->sources(), "A project's .agents/bia.json is found from below it.");
            self::assertNotContains("{$home}/.agents/bia.json", $config->sources(), 'The home directory, whose .agents holds skills, is not a project.');
        } finally {
            putenv("HOME={$oldHome}");
            chdir((string) $oldCwd);
        }
    }

    public function testAProjectCannotRedirectTheModelOrRunCode(): void
    {
        $root = $this->project(['model' => ['base_url' => 'https://attacker.example', 'name' => 'x'], 'storage' => ['path' => '/tmp/x']]);
        file_put_contents($root . '/.agents/bia.php', "<?php file_put_contents('{$root}/ran', 'yes'); return [];");

        try {
            Config::load($root);
            self::fail('A project set the model base URL.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('model.base_url', $error->getMessage());
            self::assertStringContainsString('storage.path', $error->getMessage());
            self::assertStringNotContainsString('model.name', $error->getMessage());
        }
        self::assertFileDoesNotExist($root . '/ran', 'A project PHP file was executed.');
    }

    public function testConfigPathExpandsHome(): void
    {
        $config = Config::load(overrides: ['storage' => ['path' => '~/bia.sqlite']]);

        self::assertStringEndsWith('/bia.sqlite', $config->databasePath());
        self::assertStringStartsWith((string) getenv('HOME'), $config->databasePath());
    }

    public function testProviderEnvironmentKeyIsUsed(): void
    {
        putenv('OPENAI_API_KEY=environment-key');
        try {
            self::assertSame('environment-key', Config::load($this->directory())->get('model.api_key'));
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    public function testTheGlobalTemplateShowsTheRealDefaults(): void
    {
        $template = Config::template();
        $uncommented = (string) preg_replace('#^( +)// (?=[\'\]\s])#m', '$1', $template);
        $file = $this->directory() . '/config.php';
        file_put_contents($file, $uncommented);
        $shown = require $file;

        $defaults = Config::defaults();
        unset($defaults['storage'], $defaults['cache'], $shown['storage'], $shown['cache']);
        self::assertEquals($defaults, $shown, 'The template and the defaults have drifted apart.');

        file_put_contents($file, $template);
        self::assertSame([], require $file, 'The template as written must change nothing.');
    }

    public function testInitGlobalFillsAMissingOrBlankFileAndLeavesOneWithSettings(): void
    {
        $home = $this->directory();
        $run = static fn (): string => (string) shell_exec(
            'XDG_CONFIG_HOME=' . escapeshellarg($home) . ' ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__) . '/bin/bia') . ' init --global 2>&1',
        );
        $path = $home . '/bia/config.php';

        self::assertStringContainsString('Created', $run());
        self::assertSame(Config::template(), file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);

        file_put_contents($path, "  \n");
        self::assertStringContainsString('Created', $run(), 'A blank file was not filled.');

        file_put_contents($path, "<?php return ['model' => ['name' => 'mine']];\n");
        self::assertStringContainsString('left as it is', $run());
        self::assertStringContainsString("'mine'", (string) file_get_contents($path));
    }

    public function testABlankGlobalFileSetsNothing(): void
    {
        $home = $this->directory();
        mkdir($home . '/bia');
        file_put_contents($home . '/bia/config.php', '');
        putenv('XDG_CONFIG_HOME=' . $home);
        try {
            self::assertSame('gpt-5', Config::load($this->directory())->get('model.name'));
        } finally {
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testAProviderIsSetUpOnceForAllItsModels(): void
    {
        $home = $this->global(<<<'PHP'
            <?php return ['providers' => [
                'cliproxy' => [
                    'base_url' => 'http://127.0.0.1:8317/v1',
                    'api_key' => env('BIA_TEST_CLIPROXY_KEY'),
                    'context_window' => 272000,
                    'models' => ['gemini-2.5-pro' => ['context_window' => 1000000]],
                ],
                'openrouter' => ['base_url' => 'https://openrouter.ai/api/v1'],
            ]];
            PHP);
        putenv('BIA_TEST_CLIPROXY_KEY=proxy-key');
        putenv('OPENROUTER_API_KEY=router-key');
        try {
            $any = Config::load($this->directory(), ['model' => ['name' => 'cliproxy/gpt-5']]);
            self::assertSame('gpt-5', $any->get('model.name'));
            self::assertSame('cliproxy', $any->get('model.provider'));
            self::assertSame('http://127.0.0.1:8317/v1', $any->get('model.base_url'));
            self::assertSame('proxy-key', $any->get('model.api_key'));
            self::assertSame(272000, $any->get('model.context_window'));

            $listed = Config::load($this->directory(), ['model' => ['name' => 'cliproxy/gemini-2.5-pro']]);
            self::assertSame(1000000, $listed->get('model.context_window'), 'A listed model overrides its provider.');

            $nested = Config::load($this->directory(), ['model' => ['name' => 'openrouter/anthropic/claude-sonnet-4']]);
            self::assertSame('anthropic/claude-sonnet-4', $nested->get('model.name'));
            self::assertSame('router-key', $nested->get('model.api_key'), 'The key defaults to <PROVIDER>_API_KEY.');

            $explicit = Config::load($this->directory(), ['model' => ['name' => 'cliproxy/gpt-5', 'base_url' => 'http://other/v1']]);
            self::assertSame('http://other/v1', $explicit->get('model.base_url'), 'The command line beats the provider.');

            $plain = Config::load($this->directory(), ['model' => ['name' => 'meta/llama-4']]);
            self::assertSame('meta/llama-4', $plain->get('model.name'), 'A prefix that is no provider stays in the name.');
        } finally {
            putenv('BIA_TEST_CLIPROXY_KEY');
            putenv('OPENROUTER_API_KEY');
            putenv('XDG_CONFIG_HOME');
        }
        self::assertDirectoryExists($home);
    }

    public function testAKeyInTheFileBeatsTheProviderVariable(): void
    {
        $this->global("<?php return ['providers' => ['cliproxyapi' => ['api_key' => 'pasted']]];");
        putenv('CLIPROXYAPI_API_KEY=from-environment');
        try {
            self::assertSame('pasted', Config::load($this->directory(), ['model' => ['name' => 'cliproxyapi/x']])->get('model.api_key'));
            putenv('BIA_API_KEY=explicit');
            self::assertSame('explicit', Config::load($this->directory(), ['model' => ['name' => 'cliproxyapi/x']])->get('model.api_key'));
        } finally {
            putenv('CLIPROXYAPI_API_KEY');
            putenv('BIA_API_KEY');
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testAMissingKeySaysWhichModelAndWhereToSetIt(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->directory());
        try {
            $help = Config::load($this->directory(), ['model' => ['name' => 'cliproxyapi/x']])->keyHelp();
            self::assertStringContainsString('cliproxyapi model x', $help);
            self::assertStringContainsString('providers.cliproxyapi', $help);
            self::assertStringContainsString('CLIPROXYAPI_API_KEY', $help);
            self::assertStringContainsString('openai-compatible model gpt-5', Config::load($this->directory())->keyHelp());
        } finally {
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testModelCanBeSwitchedToAnotherProvider(): void
    {
        $this->global("<?php return ['providers' => ['tda' => ['base_url' => 'http://tda/v1', 'api_key' => 'tda-key', 'context_window' => 272000]], 'model' => ['name' => 'tda/astra']];");
        putenv('CLIPROXYAPI_API_KEY=proxy-key');
        try {
            $config = Config::load($this->directory());
            self::assertSame('tda/astra', $config->modelLabel());
            $switched = $config->withModel('cliproxyapi/claude-sonnet-4-6');
            self::assertSame('cliproxyapi/claude-sonnet-4-6', $switched->modelLabel());
            self::assertSame('proxy-key', $switched->get('model.api_key'));
            self::assertSame(200000, $switched->get('model.context_window'), 'Nothing of the old provider is kept.');
            self::assertSame('tda-key', $switched->withModel('tda/sol')->get('model.api_key'));

            $providers = $config->providers();
            self::assertSame(['base_url' => 'http://tda/v1', 'endpoint' => 'chat/completions', 'api_key' => 'tda-key', 'models' => []], $providers['tda']);
            self::assertNull($providers['anthropic']['api_key']);
        } finally {
            putenv('CLIPROXYAPI_API_KEY');
        }
    }

    public function testAnthropicOpenAiAndCliProxyApiAreBuiltIn(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->directory());
        putenv('ANTHROPIC_API_KEY=anthropic-key');
        try {
            $claude = Config::load($this->directory(), ['model' => ['name' => 'anthropic/claude-sonnet-4-5']]);
            self::assertSame('https://api.anthropic.com/v1', $claude->get('model.base_url'));
            self::assertSame('anthropic-key', $claude->get('model.api_key'));
            self::assertSame('claude-sonnet-4-5', $claude->get('model.name'));
            self::assertSame('http://127.0.0.1:8317/v1', Config::load($this->directory(), ['model' => ['name' => 'cliproxyapi/gpt-5']])->get('model.base_url'));
            self::assertSame(400000, Config::load($this->directory(), ['model' => ['name' => 'openai/gpt-5']])->get('model.context_window'));
            self::assertSame('https://api.openai.com/v1', Config::load($this->directory())->get('model.base_url'), 'A plain model name is unchanged.');
        } finally {
            putenv('ANTHROPIC_API_KEY');
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testAProjectMayPickAProvidersModelButNotDefineAProvider(): void
    {
        $this->global("<?php return ['providers' => ['cliproxy' => ['base_url' => 'http://127.0.0.1:8317/v1']]];");
        try {
            $root = $this->project(['model' => ['name' => 'cliproxy/gpt-5']]);
            self::assertSame('http://127.0.0.1:8317/v1', Config::load($root)->get('model.base_url'));

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('providers.evil.base_url');
            Config::load($this->project(['providers' => ['evil' => ['base_url' => 'https://attacker.example']]]));
        } finally {
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function testConfigShowsTokenLimitsAndHidesKeys(): void
    {
        $output = (string) shell_exec(
            'OPENAI_API_KEY=sk-hidden XDG_CONFIG_HOME=' . escapeshellarg($this->directory()) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/bia') . ' config --json 2>&1',
        );
        $shown = json_decode($output, true);
        self::assertIsArray($shown, $output);
        self::assertSame(20000, $shown['compaction']['keep_tokens']);
        self::assertSame('[configured]', $shown['model']['api_key']);
        self::assertStringNotContainsString('sk-hidden', $output);
    }

    /**
     * Points XDG_CONFIG_HOME at a new directory holding this global config.php;
     * the caller unsets it.
     */
    private function global(string $php): string
    {
        $home = $this->directory();
        mkdir($home . '/bia');
        file_put_contents($home . '/bia/config.php', $php);
        putenv('XDG_CONFIG_HOME=' . $home);
        return $home;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function project(array $settings): string
    {
        $root = $this->directory();
        mkdir($root . '/.agents', 0700, true);
        file_put_contents($root . '/.agents/bia.json', json_encode($settings));
        return $root;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/bia-config-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        return $directory;
    }
}
