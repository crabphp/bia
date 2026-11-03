<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\Image;
use Bia\Tool\ProcessRunner;

/**
 * Where a message's images come from: the system clipboard, read as Claude
 * Code and Codex read it, with the desktop's own tool (wl-paste, xclip,
 * macOS's osascript); or an image file whose path was pasted or dropped on
 * the terminal. Either is kept as a file of Bia's, named by its content, for
 * the session to point to.
 *
 * A terminal passes only text, so the clipboard is the one of the machine
 * Bia runs on: over SSH there is none to read, and a path is the way.
 */
final readonly class Images
{
    /** The largest image taken, in bytes. */
    public const MOST = 20 * 1024 * 1024;

    /** The types asked of the clipboard, the most widely read first. */
    private const PREFERRED = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(
        private ProcessRunner $runner,
        private string $directory,
        private string $workspace,
    ) {}

    /**
     * The image on the clipboard, kept; or why there is none.
     */
    public function fromClipboard(): Image|string
    {
        [$bytes, $why] = match (true) {
            PHP_OS_FAMILY === 'Darwin' => $this->mac(),
            (string) getenv('WAYLAND_DISPLAY') !== '' => $this->wayland(),
            (string) getenv('DISPLAY') !== '' => $this->x11(),
            default => [null, 'No clipboard on this machine: paste an image file\'s path instead'],
        };
        if ($bytes === null || Image::sniff($bytes) === null) {
            return $why;
        }
        try {
            return $this->keep($bytes);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            return $error->getMessage();
        }
    }

    /**
     * The images a paste names, when it is nothing but paths to image files,
     * as a file dropped on the terminal is pasted; null when it is anything else.
     *
     * @return list<Image>|null
     */
    public function fromPaste(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 8192) {
            return null;
        }
        $candidates = [$text];
        if ($this->path($text) !== null) {
            // One file, its path whole, spaces and all.
        } elseif (str_contains($text, "\n")) {
            $candidates = array_map('trim', explode("\n", $text));
        } elseif (preg_match_all('/(?:\\\\ |[^ ])+/', $text, $parts) > 1) {
            // Several files dropped at once: paths apart by spaces, a space in one escaped.
            $candidates = $parts[0];
        }
        $images = [];
        foreach ($candidates as $candidate) {
            $path = $this->path($candidate);
            $bytes = $path !== null && filesize($path) <= self::MOST ? file_get_contents($path, length: self::MOST + 1) : false;
            if ($bytes === false || Image::sniff($bytes) === null) {
                return null; // not only images: it is text, pasted as text
            }
            $images[] = $this->keep($bytes);
        }
        return $images === [] ? null : $images;
    }

    /**
     * Image bytes kept as a file named by their content, readable by the user alone.
     */
    public function keep(string $bytes): Image
    {
        $mime = Image::sniff($bytes) ?? throw new \InvalidArgumentException('Not an image Bia can send.');
        if (strlen($bytes) > self::MOST) {
            throw new \InvalidArgumentException('The image is larger than ' . intdiv(self::MOST, 1024 * 1024) . ' MB.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create {$this->directory}.");
        }
        $path = $this->directory . '/' . hash('sha256', $bytes) . '.' . substr($mime, strlen('image/'));
        if (!is_file($path)) {
            file_put_contents($path, $bytes);
            @chmod($path, 0600);
        }
        return new Image($path, $mime);
    }

    /**
     * A pasted path as a file here: quoted, escaped, a file:// address, ~ or
     * relative to the workspace; null when no such file is.
     */
    private function path(string $text): ?string
    {
        $text = trim($text);
        if (strlen($text) >= 2 && ($text[0] === "'" || $text[0] === '"') && $text[-1] === $text[0]) {
            $text = substr($text, 1, -1);
        }
        if (str_starts_with($text, 'file://')) {
            $text = rawurldecode((string) parse_url($text, PHP_URL_PATH));
        }
        $text = (string) preg_replace('/\\\\(.)/', '$1', $text);
        if (str_starts_with($text, '~/')) {
            $text = rtrim((string) getenv('HOME'), '/') . substr($text, 1);
        } elseif (!str_starts_with($text, '/')) {
            $text = $this->workspace . '/' . $text;
        }
        return $text !== '' && is_file($text) && is_readable($text) ? $text : null;
    }

    /**
     * @return array{0: ?string, 1: string} The bytes, or null and why not.
     */
    private function mac(): array
    {
        // AppleScript gives the clipboard's PNG as «data PNGf…» in hex.
        $result = $this->run(['osascript', '-e', 'the clipboard as «class PNGf»'], self::MOST * 3);
        if ($result === null || preg_match('/«data PNGf([0-9A-Fa-f]+)»/', $result, $hex) !== 1) {
            return [null, 'No image on the clipboard'];
        }
        return [(string) hex2bin($hex[1]), ''];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function wayland(): array
    {
        return $this->offered(['wl-paste', '--list-types'], static fn (string $type): array => ['wl-paste', '--no-newline', '--type', $type], 'wl-clipboard');
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function x11(): array
    {
        return $this->offered(['xclip', '-selection', 'clipboard', '-t', 'TARGETS', '-o'], static fn (string $type): array => ['xclip', '-selection', 'clipboard', '-t', $type, '-o'], 'xclip');
    }

    /**
     * The clipboard's image in the first type it offers that a model reads.
     *
     * @param list<string> $list
     * @param \Closure(string): list<string> $get
     * @return array{0: ?string, 1: string}
     */
    private function offered(array $list, \Closure $get, string $tool): array
    {
        $types = $this->run($list, 1024 * 1024);
        if ($types === null) {
            return [null, "Could not read the clipboard: is {$tool} installed?"];
        }
        $type = self::choose(preg_split('/\R/', trim($types)) ?: []);
        if ($type === null) {
            return [null, 'No image on the clipboard'];
        }
        return [$this->run($get($type), self::MOST + 1), 'No image on the clipboard'];
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, int $maxBytes): ?string
    {
        try {
            $result = $this->runner->run($command, $this->workspace, 10.0, $maxBytes);
        } catch (\RuntimeException) {
            return null; // not installed
        }
        return $result->ok() ? $result->stdout : null; // the image alone: a warning on stderr would spoil it
    }

    /**
     * @param list<string> $offered
     */
    private static function choose(array $offered): ?string
    {
        foreach (self::PREFERRED as $type) {
            if (in_array($type, array_map('trim', $offered), true)) {
                return $type;
            }
        }
        return null;
    }
}
