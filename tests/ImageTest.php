<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Image;
use Bia\Contracts\Message;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Terminal\Images;
use Bia\Tool\ProcessRunner;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bia-image-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/shots with space', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnImageIsKnownByItsFirstBytes(): void
    {
        self::assertSame('image/png', Image::sniff(self::PNG));
        self::assertSame('image/jpeg', Image::sniff("\xff\xd8\xff\xe0"));
        self::assertSame('image/gif', Image::sniff('GIF89a....'));
        self::assertSame('image/webp', Image::sniff('RIFF0000WEBPVP8 '));
        self::assertNull(Image::sniff('not an image'));
    }

    public function testAMessageKeepsItsImagesAndAModelIsSentThem(): void
    {
        $file = $this->root . '/a.png';
        file_put_contents($file, self::PNG);
        $message = Message::user('what is this? [Image #1]', [new Image($file, 'image/png')]);
        $again = Message::fromArray(json_decode(json_encode($message->toArray()), true));
        self::assertEquals($message, $again, 'Stored as a path and a type, read back the same.');

        $wire = OpenAiCompatibleModel::messages([$again, new Message('user', 'gone', images: [new Image($this->root . '/gone.png', 'image/png')])]);
        self::assertSame([
            ['type' => 'text', 'text' => 'what is this? [Image #1]'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(self::PNG)]],
        ], $wire[0]['content']);
        self::assertSame(['type' => 'text', 'text' => '[an image no longer kept]'], $wire[1]['content'][1], 'A file gone is said so, not an error.');
        self::assertSame('plain', OpenAiCompatibleModel::messages([Message::user('plain')])[0]['content'], 'Without images, text as before.');
    }

    public function testAnImageCanProvideRawBase64ForAnUploadTool(): void
    {
        $file = $this->root . '/a.png';
        file_put_contents($file, self::PNG);
        $image = new Image($file, 'image/png');

        self::assertSame(base64_encode(self::PNG), $image->base64());
        self::assertSame(self::PNG, $image->bytes());
    }

    public function testAPastedPathToAnImageIsTheImage(): void
    {
        $images = new Images(new ProcessRunner(), $this->root . '/kept', $this->root);
        file_put_contents($this->root . '/shots with space/one.png', self::PNG);
        file_put_contents($this->root . '/two.jpg', "\xff\xd8\xff\xe0jpeg");
        file_put_contents($this->root . '/notes.txt', 'text');

        foreach ([$this->root . '/shots\\ with\\ space/one.png', "'" . $this->root . "/shots with space/one.png'", 'file://' . str_replace(' ', '%20', $this->root . '/shots with space/one.png'), 'shots\\ with\\ space/one.png'] as $pasted) {
            $found = $images->fromPaste($pasted);
            self::assertNotNull($found, "Taken as a path: {$pasted}");
            self::assertSame('image/png', $found[0]->mime);
            self::assertSame(self::PNG, file_get_contents($found[0]->path), 'Kept as a copy of its own.');
        }
        self::assertCount(2, $images->fromPaste($this->root . '/shots\\ with\\ space/one.png ' . $this->root . '/two.jpg') ?? [], 'Two files dropped at once.');
        self::assertNull($images->fromPaste($this->root . '/notes.txt'), 'A file that is not an image is pasted as text.');
        self::assertNull($images->fromPaste('just some words'), 'Text is text.');
        self::assertNull($images->fromPaste($this->root . '/two.jpg and more'), 'A path with words around it is text.');
        self::assertCount(2, glob($this->root . '/kept/*') ?: [], 'Kept once each, by content.');
    }

    public function testCtrlVTakesTheClipboardsImage(): void
    {
        // A wl-paste of our own, on the PATH, offering a PNG.
        $bin = $this->root . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/wl-paste', "#!/bin/sh\nif [ \"\$1\" = --list-types ]; then printf 'text/plain\\nimage/png\\n'; else printf '\\211PNG\\r\\n\\032\\n\\000\\000\\000\\rIHDR'; fi\n");
        chmod($bin . '/wl-paste', 0755);
        $environment = ['PATH' => $bin . ':' . getenv('PATH'), 'WAYLAND_DISPLAY' => 'wayland-test'];
        $saved = ['PATH' => getenv('PATH'), 'WAYLAND_DISPLAY' => getenv('WAYLAND_DISPLAY'), 'DISPLAY' => getenv('DISPLAY')];
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }
        try {
            EventLoop::install(new StreamSelectEventLoop());
            $images = new Images(new ProcessRunner(), $this->root . '/kept', $this->root);
            $found = Task::spawn(EventLoop::current(), static fn () => $images->fromClipboard())->await();
            self::assertInstanceOf(Image::class, $found, is_string($found) ? $found : '');
            self::assertSame(self::PNG, file_get_contents($found->path));

            file_put_contents($bin . '/wl-paste', "#!/bin/sh\nprintf 'text/plain\\n'\n");
            self::assertSame('No image on the clipboard', Task::spawn(EventLoop::current(), static fn () => $images->fromClipboard())->await());

            putenv('WAYLAND_DISPLAY');
            putenv('DISPLAY');
            self::assertStringStartsWith('No clipboard on this machine', (string) (new Images(new ProcessRunner(), $this->root . '/kept', $this->root))->fromClipboard(), 'Over SSH there is none: it says so.');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
