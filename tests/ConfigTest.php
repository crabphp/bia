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
