<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Terminal\Loop;
use Bia\Tool\ProcessRunner;
use Bia\Update\Check;
use Bia\Update\Installed;
use Bia\Update\Releases;
use Crab\HttpClient\FakeClient;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    private const BASE = 'https://github.com/crabphp/bia/releases/download/v0.2.0/';

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    public function testEachSystemGetsItsOwnReleaseFile(): void
    {
        self::assertSame('bia-linux-x86_64.tar.gz', Installed::asset(true, 'Linux', 'x86_64'));
        self::assertSame('bia-linux-aarch64.tar.gz', Installed::asset(true, 'Linux', 'aarch64'));
        self::assertSame('bia-macos-aarch64.tar.gz', Installed::asset(true, 'Darwin', 'arm64'));
        self::assertSame('bia-macos-x86_64.tar.gz', Installed::asset(true, 'Darwin', 'x86_64'));
        self::assertNull(Installed::asset(true, 'Linux', 'riscv64'), 'No binary is built for it.');
        self::assertNull(Installed::asset(true, 'BSD', 'x86_64'));
        self::assertSame('bia.phar', Installed::asset(false, 'BSD', 'riscv64'), 'bia.phar runs wherever PHP does.');
        self::assertNull(Installed::file(), 'Run from source, there is nothing to replace.');
    }

    public function testVersionsCompareAsReleasesDo(): void
    {
        self::assertTrue(Releases::newer('0.1.1', '0.1.0'));
        self::assertTrue(Releases::newer('0.1.0', '0.1.0-beta.2'));
        self::assertTrue(Releases::newer('0.10.0', '0.9.9'));
        self::assertFalse(Releases::newer('0.1.0', '0.1.0'));
        self::assertFalse(Releases::newer('0.1.0', '0.2.0-beta.1'), 'Ahead of the latest is not behind it.');
    }

    public function testTheLatestIsWhereGithubRedirects(): void
    {
        $http = new FakeClient([
            'HEAD https://github.com/crabphp/bia/releases/latest' => FakeClient::response(302, null, ['Location' => 'https://github.com/crabphp/bia/releases/tag/v0.2.0']),
        ], redirects: 0);

        self::assertSame('0.2.0', (new Releases($http, new ProcessRunner(), '0.1.0'))->latest());
        self::assertSame('bia/0.1.0', $http->requests[0]->getHeaderLine('User-Agent'));

        $none = new FakeClient(['HEAD *' => FakeClient::response(302, null, ['Location' => 'https://github.com/crabphp/bia/releases'])], redirects: 0);
        $this->expectExceptionMessage('GitHub did not say which Bia release is the latest');
        (new Releases($none, new ProcessRunner(), '0.1.0'))->latest();
    }

    public function testChecksumsAreReadAsSha256sumWritesThem(): void
    {
        $sums = str_repeat('a', 64) . "  bia-linux-x86_64.tar.gz\n" . str_repeat('B', 64) . " *bia.phar\r\n";

        self::assertSame(str_repeat('a', 64), Releases::checksum($sums, 'bia-linux-x86_64.tar.gz'));
        self::assertSame(str_repeat('b', 64), Releases::checksum($sums, 'bia.phar'));
        self::assertNull(Releases::checksum($sums, 'bia-macos-x86_64.tar.gz'));
        self::assertNull(Releases::checksum($sums, 'bia'), 'Only the whole name counts.');
    }

    public function testTheBinaryIsTakenOutOfItsTarball(): void
    {
        $tarball = $this->tarball(['README' => 'not this', 'bia' => "#!/bin/sh\necho 0.2.0\n"]);

        self::assertSame("#!/bin/sh\necho 0.2.0\n", Releases::untar($tarball, 'bia'));

        $this->expectExceptionMessage('The download has no bia in it.');
        Releases::untar($this->tarball(['other' => 'x']), 'bia');
    }

    public function testADownloadIsCheckedRunAndLeftBesideTheInstalledFile(): void
    {
        $directory = $this->directory();
        $installed = $directory . '/bia';
        file_put_contents($installed, 'the old one');
        chmod($installed, 0750);
        $binary = "#!/bin/sh\necho 0.2.0\n";
        $tarball = $this->tarball(['bia' => $binary]);
        $http = new FakeClient([
            'GET ' . self::BASE . 'SHA256SUMS' => hash('sha256', $tarball) . "  bia-linux-x86_64.tar.gz\n",
            // Release files are kept elsewhere, and redirected to.
            'GET ' . self::BASE . 'bia-linux-x86_64.tar.gz' => FakeClient::response(302, null, ['Location' => 'https://objects.example/abc?sig=1']),
            'GET https://objects.example/abc?sig=1' => FakeClient::response(200, $tarball, ['Content-Type' => 'application/octet-stream']),
        ], redirects: 0);
        $releases = new Releases($http, new ProcessRunner(), '0.1.0');

        $new = Loop::run(static fn ($cancellation) => $releases->download('0.2.0', 'bia-linux-x86_64.tar.gz', $installed, null, $cancellation));

        self::assertSame($directory, dirname($new), 'Beside it, to be renamed over it in one step.');
        self::assertSame($binary, file_get_contents($new));
        self::assertSame(0750, fileperms($new) & 0777, 'With the installed file\'s mode.');
        self::assertSame('the old one', file_get_contents($installed), 'Replacing it is the caller\'s step.');
    }

    public function testAPharIsRunByThePhpGiven(): void
    {
        $directory = $this->directory();
        file_put_contents($directory . '/bia.phar', 'old');
        $phar = "<?php echo \"0.2.0\\n\";\n";
        $http = new FakeClient([
            'GET ' . self::BASE . 'SHA256SUMS' => hash('sha256', $phar) . "  bia.phar\n",
            'GET ' . self::BASE . 'bia.phar' => $phar,
        ], redirects: 0);

        $new = Loop::run(static fn ($cancellation) => (new Releases($http, new ProcessRunner(), '0.1.0'))->download('0.2.0', 'bia.phar', $directory . '/bia.phar', PHP_BINARY, $cancellation));

        self::assertSame($phar, file_get_contents($new));
    }

    public function testNothingIsLeftWhenTheDownloadIsNotTheRelease(): void
    {
        $directory = $this->directory();
        $phar = "<?php echo \"0.2.0\\n\";\n";
        $failures = [
            'does not match' => [hash('sha256', 'something else') . "  bia.phar\n", $phar],
            'does not list' => [hash('sha256', $phar) . "  bia-linux-x86_64.tar.gz\n", $phar],
            'does not run here' => [hash('sha256', '<?php echo "0.1.9\n";') . "  bia.phar\n", '<?php echo "0.1.9\n";'],
        ];
        foreach ($failures as $said => [$sums, $body]) {
            $http = new FakeClient([
                'GET ' . self::BASE . 'SHA256SUMS' => $sums,
                'GET ' . self::BASE . 'bia.phar' => $body,
            ], redirects: 0);
            try {
                Loop::run(static fn ($cancellation) => (new Releases($http, new ProcessRunner(), '0.1.0'))->download('0.2.0', 'bia.phar', $directory . '/bia.phar', PHP_BINARY, $cancellation));
                self::fail("Installed what {$said}.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($said, $error->getMessage());
            }
            self::assertSame([], array_values(array_diff((array) scandir($directory), ['.', '..'])), "Nothing left behind when it {$said}.");
        }
    }

    public function testARedirectIsFollowedToHttpsOnly(): void
    {
        $directory = $this->directory();
        $http = new FakeClient([
            'GET ' . self::BASE . 'SHA256SUMS' => FakeClient::response(302, null, ['Location' => 'http://objects.example/sums']),
        ], redirects: 0);

        $this->expectExceptionMessage('was sent somewhere not https: http://objects.example/sums');
        Loop::run(static fn ($cancellation) => (new Releases($http, new ProcessRunner(), '0.1.0'))->download('0.2.0', 'bia.phar', $directory . '/bia.phar', PHP_BINARY, $cancellation));
    }

    public function testGithubIsAskedAtMostOnceADay(): void
    {
        $file = $this->directory() . '/cache/update.json';
        $latest = static fn (string $version): FakeClient => new FakeClient([
            'HEAD https://github.com/crabphp/bia/releases/latest' => FakeClient::response(302, null, ['Location' => "https://github.com/crabphp/bia/releases/tag/v{$version}"]),
        ], redirects: 0);
        $check = static fn (FakeClient $http): Check => new Check(new Releases($http, new ProcessRunner(), '0.1.0'), $file, '0.1.0');

        $first = $latest('0.2.0');
        self::assertSame('0.2.0', $check($first)->newer(now: 1_000_000));
        self::assertCount(1, $first->requests);
        self::assertSame(['checked_at' => 1_000_000, 'latest' => '0.2.0'], json_decode((string) file_get_contents($file), true));

        $again = $latest('0.3.0');
        self::assertSame('0.2.0', $check($again)->newer(now: 1_000_000 + Check::EVERY - 1), 'The same day, the answer kept.');
        self::assertSame([], $again->requests);

        self::assertSame('0.3.0', $check($again)->newer(now: 1_000_000 + Check::EVERY), 'A day on, asked again.');
        self::assertCount(1, $again->requests);

        $current = $latest('0.1.0');
        self::assertNull($check($current)->newer(now: 1_000_000 + 3 * Check::EVERY), 'Nothing to say when this is the latest.');
    }

    public function testOfflineTheLastAnswerStandsAndNothingIsThrown(): void
    {
        $file = $this->directory() . '/update.json';
        file_put_contents($file, json_encode(['checked_at' => 1_000, 'latest' => '0.2.0']));
        $offline = new FakeClient(['HEAD *' => new \RuntimeException('github.com could not be reached')], redirects: 0);

        self::assertSame('0.2.0', (new Check(new Releases($offline, new ProcessRunner(), '0.1.0'), $file, '0.1.0'))->newer(now: 1_000_000));
        self::assertSame(1_000, json_decode((string) file_get_contents($file), true)['checked_at'], 'Asked again next time.');

        $unreachable = new FakeClient([], redirects: 0);
        $check = new Check(new Releases($unreachable, new ProcessRunner(), '0.1.0'), $this->directory() . '/update.json', '0.1.0', static function (): void {
            throw new \RuntimeException('no address was found for the name github.com');
        });
        self::assertNull($check->newer(now: 1_000_000));
        self::assertSame([], $unreachable->requests, 'Not asked when the name could not be found without holding the process.');
    }

    /**
     * A gzipped tar of the files given, as a release's is made.
     *
     * @param array<string, string> $files
     */
    private function tarball(array $files): string
    {
        $directory = $this->directory();
        foreach ($files as $name => $content) {
            file_put_contents($directory . '/' . $name, $content);
        }
        exec('tar czf ' . escapeshellarg($directory . '/out.tar.gz') . ' -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', array_keys($files))), $_, $status);
        self::assertSame(0, $status);
        return (string) file_get_contents($directory . '/out.tar.gz');
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/bia-update-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $this->directories[] = $directory;
        return $directory;
    }
}
