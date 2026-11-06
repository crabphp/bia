<?php

declare(strict_types=1);

namespace Bia\Update;

use Bia\Tool\ProcessRunner;
use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;

/**
 * Bia's releases on GitHub: which is the latest, and a release's file for
 * this install, downloaded, checked and ready to take this one's place.
 *
 * The latest is read from where github.com/…/releases/latest redirects,
 * one HEAD request with no body and none of the API's rate limit.
 * Pre-releases are never the latest. The client must not follow
 * redirects itself: they are followed here, to https only.
 */
final readonly class Releases
{
    public const REPOSITORY = 'https://github.com/crabphp/bia';
    public const HOST = 'github.com';

    public function __construct(
        private Client $http,
        private ProcessRunner $runner,
        private string $current,
        private float $timeout = 10.0,
        private float $downloadTimeout = 300.0,
    ) {}

    /**
     * The latest release's version, without its v.
     */
    public function latest(?Cancellation $cancellation = null): string
    {
        $response = $this->http->timeout($this->timeout)->request('HEAD', self::REPOSITORY . '/releases/latest', headers: $this->headers(), cancellation: $cancellation);
        if (preg_match('#/releases/tag/v?(\d[0-9A-Za-z.+-]*)$#', $response->getHeaderLine('Location'), $match) !== 1) {
            throw new \RuntimeException('GitHub did not say which Bia release is the latest (HTTP ' . $response->getStatusCode() . ').');
        }
        return $match[1];
    }

    public static function newer(string $version, string $than): bool
    {
        return version_compare($version, $than, '>');
    }

    /**
     * Downloads a release's file, checks it against the release's
     * SHA256SUMS, writes it beside the installed file, with its mode, and
     * runs it once to see that it is that version. Renamed over the
     * installed file, it takes its place in one step.
     *
     * @param string $asset bia-linux-x86_64.tar.gz, whose bia is taken out, or bia.phar.
     * @param string|null $php The PHP that runs it, for bia.phar; null runs it itself.
     * @return string The new file, beside the installed one.
     */
    public function download(string $version, string $asset, string $installed, ?string $php = null, ?Cancellation $cancellation = null): string
    {
        $directory = dirname($installed);
        if (!is_writable($directory)) {
            throw new \RuntimeException("Bia cannot write in {$directory}: run sudo bia update, or install Bia where you can write.");
        }
        $base = self::REPOSITORY . '/releases/download/v' . rawurlencode($version) . '/';
        $sums = $this->get($base . 'SHA256SUMS', 65_536, $cancellation, "Bia {$version}");
        $expected = self::checksum($sums, $asset) ?? throw new \RuntimeException("Bia {$version}'s SHA256SUMS does not list {$asset}.");
        $data = $this->get($base . $asset, 134_217_728, $cancellation, "{$asset} of Bia {$version}");
        if (!hash_equals($expected, hash('sha256', $data))) {
            throw new \RuntimeException("{$asset} does not match Bia {$version}'s SHA256SUMS; nothing was changed.");
        }
        $content = str_ends_with($asset, '.tar.gz') ? self::untar($data, 'bia') : $data;
        $temporary = $directory . '/.bia-update-' . bin2hex(random_bytes(4));
        try {
            if (file_put_contents($temporary, $content) !== strlen($content)) {
                throw new \RuntimeException("Bia could not write {$temporary}.");
            }
            chmod($temporary, is_file($installed) ? fileperms($installed) & 0o777 : 0o755);
            $command = $php === null ? [$temporary, '--version'] : [$php, $temporary, '--version'];
            $ran = $this->runner->run($command, $directory, 30.0, 4096, cancellation: $cancellation);
            $said = trim($ran->stdout);
            if (!$ran->ok() || $said !== $version) {
                $why = trim($said . ' ' . $ran->stderr);
                throw new \RuntimeException("The new Bia does not run here, so it was not installed" . ($why !== '' ? ': ' . mb_strimwidth((string) preg_replace('/\s+/', ' ', $why), 0, 200, '…') : '.'));
            }
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        }
        return $temporary;
    }

    /**
     * The checksum a SHA256SUMS file gives a file, as sha256sum writes it.
     */
    public static function checksum(string $sums, string $file): ?string
    {
        foreach (preg_split('/\R/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64}) [ *](.+)$/', trim($line), $match) === 1 && $match[2] === $file) {
                return strtolower($match[1]);
            }
        }
        return null;
    }

    /**
     * One file out of a gzipped tar, as a release's is: a ustar archive,
     * read here since PharData would want it on disk under a .tar.gz name.
     */
    public static function untar(string $gzipped, string $name): string
    {
        $tar = @gzdecode($gzipped);
        if ($tar === false) {
            throw new \RuntimeException('The download is not a gzipped tar.');
        }
        $length = strlen($tar);
        for ($at = 0; $at + 512 <= $length;) {
            $header = substr($tar, $at, 512);
            if (trim($header, "\0") === '') {
                break; // the archive's end
            }
            $file = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '' && str_starts_with(substr($header, 257, 6), 'ustar')) {
                $file = $prefix . '/' . $file;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];
            if (($type === '0' || $type === "\0") && preg_replace('#^\./#', '', $file) === $name) {
                $content = substr($tar, $at + 512, $size);
                if (strlen($content) !== $size) {
                    throw new \RuntimeException("The download's {$name} is cut short.");
                }
                return $content;
            }
            $at += 512 + intdiv($size + 511, 512) * 512;
        }
        throw new \RuntimeException("The download has no {$name} in it.");
    }

    /**
     * A file's body, following redirects to https only, as GitHub's go to
     * where release files are kept.
     */
    private function get(string $url, int $maxBytes, ?Cancellation $cancellation, string $what): string
    {
        for ($hops = 0; $hops <= 5; $hops++) {
            $response = $this->http->timeout($this->downloadTimeout)->request('GET', $url, headers: $this->headers(), cancellation: $cancellation, maxBytes: $maxBytes);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                if (!str_starts_with($location, 'https://')) {
                    throw new \RuntimeException("{$what} was sent somewhere not https: {$location}");
                }
                $url = $location;
                continue;
            }
            if ($status === 404) {
                throw new \RuntimeException("{$what} is not on GitHub: " . self::REPOSITORY . '/releases');
            }
            if (!$response->ok()) {
                throw new \RuntimeException("{$what} could not be downloaded: HTTP {$status}.");
            }
            return (string) $response->getBody();
        }
        throw new \RuntimeException("{$what} was redirected too many times.");
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['User-Agent' => "bia/{$this->current}"];
    }
}
