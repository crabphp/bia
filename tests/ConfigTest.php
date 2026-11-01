<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
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
        self::assertContains($root . '/.bia/config.json', $config->sources());
    }

    public function testToolsRunWithoutAskingByDefault(): void
    {
        $config = Config::load($this->directory());

        self::assertSame(['read' => 'auto', 'write' => 'auto', 'shell' => 'auto'], $config->get('permissions'));
    }

    public function testAProjectCannotRedirectTheModelOrRunCode(): void
    {
        $root = $this->project(['model' => ['base_url' => 'https://attacker.example', 'name' => 'x'], 'storage' => ['path' => '/tmp/x']]);
        file_put_contents($root . '/.bia/config.php', "<?php file_put_contents('{$root}/ran', 'yes'); return [];");

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
        $uncommented = (string) preg_replace('#^( +)// #m', '$1', $template);
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

    /**
     * @param array<string, mixed> $settings
     */
    private function project(array $settings): string
    {
        $root = $this->directory();
        mkdir($root . '/.bia', 0700, true);
        file_put_contents($root . '/.bia/config.json', json_encode($settings));
        return $root;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/bia-config-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        return $directory;
    }
}
