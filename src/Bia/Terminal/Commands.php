<?php

declare(strict_types=1);

namespace Bia\Terminal;

/**
 * The slash commands of a conversation: `/model`, `/exit`...
 *
 * A line is a command when it starts with a slash and a name; anything else,
 * a path like `/etc/hosts` included, once it has a space or a second slash,
 * goes to the model.
 */
final class Commands
{
    /** @var array<string, array{description: string, usage: string, handler: \Closure(string): void}> */
    private array $commands = [];
    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * @param \Closure(string): void $handler Given what follows the name, trimmed.
     * @param list<string> $aliases
     */
    public function add(string $name, string $description, \Closure $handler, string $usage = '', array $aliases = []): self
    {
        $this->commands[$name] = ['description' => $description, 'usage' => $usage, 'handler' => $handler];
        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $name;
        }
        return $this;
    }

    public static function looksLikeOne(string $line): bool
    {
        return preg_match('#^/[a-z][\w-]*(\s|$)#i', $line) === 1;
    }

    /**
     * Runs the command the line names.
     *
     * @return bool False when no command has that name.
     */
    public function run(string $line): bool
    {
        if (preg_match('#^/([a-z][\w-]*)\s*(.*)$#is', trim($line), $match) !== 1) {
            return false;
        }
        $name = strtolower($match[1]);
        $name = $this->aliases[$name] ?? $name;
        if (!isset($this->commands[$name])) {
            return false;
        }
        ($this->commands[$name]['handler'])(trim($match[2]));
        return true;
    }

    /**
     * Commands whose name starts with the prefix, as menu items.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    public function matching(string $prefix): array
    {
        $prefix = strtolower(ltrim($prefix, '/'));
        $items = [];
        foreach ($this->commands as $name => $command) {
            $aliases = array_keys($this->aliases, $name, true);
            $matches = str_starts_with($name, $prefix);
            foreach ($aliases as $alias) {
                $matches = $matches || str_starts_with($alias, $prefix);
            }
            if ($matches) {
                $items[] = [
                    'value' => '/' . $name,
                    'label' => '/' . $name . ($command['usage'] !== '' ? ' ' . $command['usage'] : ''),
                    'hint' => $command['description'],
                ];
            }
        }
        return $items;
    }

    /**
     * Whether a command, or an alias of one, has this name.
     */
    public function has(string $name): bool
    {
        $name = strtolower(ltrim($name, '/'));
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    public function takesArguments(string $name): bool
    {
        $name = ltrim(strtolower($name), '/');
        return ($this->commands[$this->aliases[$name] ?? $name]['usage'] ?? '') !== '';
    }

    public function help(): string
    {
        $width = 0;
        foreach ($this->commands as $name => $command) {
            $width = max($width, strlen($name . ' ' . $command['usage']));
        }
        $lines = [];
        foreach ($this->commands as $name => $command) {
            $aliases = array_keys($this->aliases, $name, true);
            $lines[] = str_pad('/' . trim($name . ' ' . $command['usage']), $width + 3) . $command['description']
                . ($aliases !== [] ? ' (also /' . implode(', /', $aliases) . ')' : '');
        }
        return implode("\n", $lines);
    }
}
