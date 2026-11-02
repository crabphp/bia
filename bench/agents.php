<?php

declare(strict_types=1);

/**
 * Compares Bia with other coding-agent CLIs on what can be measured without
 * a model: cold start, time until the interactive screen is ready, memory
 * once ready, and install size.
 *
 *   php bench/agents.php [--runs=5] [--only=bia,claude]
 *
 * This is a development tool, not part of Bia: it starts processes directly.
 * No prompt is sent to any agent, so nothing is run and no model is called;
 * an agent may still check for updates or sign-in as it starts, as it does
 * for you.
 */

const COLUMNS = 120;
const ROWS = 40;
const QUIET = 1.5;     // seconds without output that count as "ready"
const LIMIT = 20.0;    // seconds an agent gets to become ready

$root = dirname(__DIR__);
$home = (string) getenv('HOME');
$which = static function (string $name): ?string {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_executable("{$dir}/{$name}")) {
            return realpath("{$dir}/{$name}") ?: "{$dir}/{$name}";
        }
    }
    return null;
};

/** @var array<string, array{run: list<string>, version: list<string>, size: list<string>, runtime?: string}> */
$agents = [];
// Bia ships bin, src and its runtime packages; Crab, a path package here, only its src.
$lock = json_decode((string) file_get_contents("{$root}/composer.lock"), true);
$shipped = ["{$root}/bin", "{$root}/src", "{$root}/vendor/composer", "{$root}/vendor/autoload.php"];
foreach ($lock['packages'] ?? [] as $package) {
    $shipped[] = $package['name'] === 'crabphp/framework' ? "{$root}/vendor/crabphp/framework/src" : "{$root}/vendor/{$package['name']}";
}
$agents['bia'] = [
    'run' => [PHP_BINARY, "{$root}/bin/bia"],
    'version' => [PHP_BINARY, "{$root}/bin/bia", '--version'],
    'size' => $shipped,
    'runtime' => 'php',
];
foreach (['claude', 'codex', 'opencode', 'amp'] as $name) {
    if (($path = $which($name)) !== null) {
        $agents[$name] = ['run' => [$path], 'version' => [$path, '--version'], 'size' => [$path]];
    }
}
if (($pi = $which('pi')) !== null) {
    // A Node script: its package is what is installed, and Node runs it.
    $package = dirname($pi, 3);
    $agents['pi'] = ['run' => [$pi], 'version' => [$pi, '--version'], 'size' => [$package], 'runtime' => 'node'];
}
if (isset($agents['codex'])) {
    $agents['codex']['size'] = [dirname($agents['codex']['run'][0], 2)];
}

$runs = 5;
$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--runs=(\d+)$/', $arg, $m) === 1) {
        $runs = max(1, (int) $m[1]);
    } elseif (preg_match('/^--only=(.+)$/', $arg, $m) === 1) {
        $only = explode(',', $m[1]);
    }
}
if ($only !== null) {
    $agents = array_intersect_key($agents, array_flip($only));
}

/**
 * Runs a command to the end; its wall time and peak memory (KB), by GNU time.
 *
 * @param list<string> $command
 * @return array{float, int}
 */
function timed(array $command): array
{
    $report = tempnam(sys_get_temp_dir(), 'bench');
    $start = hrtime(true);
    $process = proc_open(['/usr/bin/time', '-f', '%M', '-o', $report, ...$command], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    proc_close($process);
    $seconds = (hrtime(true) - $start) / 1e9;
    $kb = (int) trim((string) file_get_contents($report));
    unlink($report);
    return [$seconds, $kb];
}

/**
 * The process and every process under it.
 *
 * @return list<int>
 */
function tree(int $pid): array
{
    $children = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
        $line = @file_get_contents($stat);
        if ($line === false || preg_match('/^(\d+) \(.*\) \S (\d+)/s', $line, $m) !== 1) {
            continue;
        }
        $children[(int) $m[2]][] = (int) $m[1];
    }
    $all = [$pid];
    for ($i = 0; $i < count($all); $i++) {
        foreach ($children[$all[$i]] ?? [] as $child) {
            $all[] = $child;
        }
    }
    return $all;
}

function rss(int $pid): int
{
    $kb = 0;
    foreach (tree($pid) as $each) {
        $status = @file_get_contents("/proc/{$each}/status");
        if ($status !== false && preg_match('/^VmRSS:\s+(\d+)/m', $status, $m) === 1) {
            $kb += (int) $m[1];
        }
    }
    return $kb;
}

/**
 * Answers the questions a TUI asks the terminal as it starts, as a real
 * terminal would, so that none waits for an answer that never comes.
 */
function answer(string $output): string
{
    $replies = '';
    $replies .= str_repeat("\e[1;1R", substr_count($output, "\e[6n"));                       // cursor position
    $replies .= str_repeat("\e[?62;22c", preg_match_all('/\e\[0?c/', $output));               // primary device attributes
    $replies .= str_repeat("\e[>1;10;0c", substr_count($output, "\e[>c") + substr_count($output, "\e[>0c"));
    $replies .= str_repeat("\e]11;rgb:1c1c/1c1c/1c1c\e\\", preg_match_all('/\e\]11;\?/', $output)); // background
    $replies .= str_repeat("\e]10;rgb:e5e5/e5e5/e5e5\e\\", preg_match_all('/\e\]10;\?/', $output)); // foreground
    $replies .= str_repeat("\e[?0u", substr_count($output, "\e[?u"));                          // keyboard protocol
    $replies .= str_repeat("\eP>|bench\e\\", substr_count($output, "\e[>0q") + substr_count($output, "\e[>q"));
    $replies .= str_repeat("\e[8;" . ROWS . ';' . COLUMNS . 't', substr_count($output, "\e[18t"));
    return $replies;
}

/**
 * Starts an agent's interactive screen in a terminal of COLUMNS×ROWS and
 * waits until it stops drawing: the time to the last byte before QUIET
 * seconds of stillness, the memory of it and its children then, and the
 * last words it showed.
 *
 * @param list<string> $command
 * @return array{float, int, string}|null Null when it never drew anything.
 */
function ready(array $command, string $cwd): ?array
{
    $line = 'stty rows ' . ROWS . ' cols ' . COLUMNS . ' 2>/dev/null; exec ' . implode(' ', array_map('escapeshellarg', $command));
    $start = hrtime(true);
    // A session of its own with the terminal as its controlling one, as a
    // terminal window gives: /dev/tty then works, which some agents need.
    $process = proc_open(['setsid', '--ctty', '/bin/sh', '-c', $line], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes, $cwd, [...getenv(), 'TERM' => 'xterm-256color', 'COLUMNS' => (string) COLUMNS, 'LINES' => (string) ROWS]);
    if (!is_resource($process)) {
        return null;
    }
    $pid = proc_get_status($process)['pid'];
    stream_set_blocking($pipes[1], false);
    $last = null;
    $seen = '';
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        $now = (hrtime(true) - $start) / 1e9;
        if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
            // When the agent exits, reading its terminal fails (EIO): that is its end.
            $chunk = (string) @fread($pipes[1], 65536);
            if ($chunk === '' && feof($pipes[1])) {
                break;
            }
            $last = (hrtime(true) - $start) / 1e9;
            $seen = substr($seen . $chunk, -20000);
            if (($reply = answer($chunk)) !== '') {
                fwrite($pipes[0], $reply);
            }
        }
        if (($last !== null && $now - $last >= QUIET) || $now >= LIMIT || !proc_get_status($process)['running']) {
            break;
        }
    }
    $memory = rss($pid);
    foreach (array_reverse(tree($pid)) as $each) {
        @posix_kill($each, SIGKILL);
    }
    foreach ($pipes as $pipe) {
        @fclose($pipe);
    }
    proc_close($process);
    if ($last === null) {
        return null;
    }
    $words = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\e(\[[0-9;?<>=!]*[ -\/]*[@-~]|\][^\a\e]*(\a|\e\\\\)|P[^\e]*\e\\\\|.)/s', ' ', $seen)));
    return [$last, $memory, mb_substr($words, -70)];
}

function size(array $paths): int
{
    $bytes = 0;
    foreach ($paths as $path) {
        $out = shell_exec('du -sbL ' . escapeshellarg($path) . ' 2>/dev/null');
        $bytes += (int) $out;
    }
    return $bytes;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    return $n === 0 ? 0.0 : ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
}

$mb = static fn (int $kb): string => number_format($kb / 1024, 0) . ' MB';
$results = [];
foreach ($agents as $name => $agent) {
    fwrite(STDERR, "{$name}: ");
    $times = $peaks = [];
    timed($agent['version']); // warm the disk cache once, not measured
    for ($i = 0; $i < $runs; $i++) {
        [$t, $kb] = timed($agent['version']);
        $times[] = $t;
        $peaks[] = $kb;
        fwrite(STDERR, '.');
    }
    $readyTimes = $readyMemory = [];
    $words = '';
    for ($i = 0; $i < min($runs, 3); $i++) {
        $result = ready($agent['run'], $root);
        if ($result !== null) {
            [$t, $kb, $words] = $result;
            $readyTimes[] = $t;
            $readyMemory[] = $kb;
        }
        fwrite(STDERR, '*');
    }
    fwrite(STDERR, "\n");
    $results[$name] = [
        'version' => median($times),
        'versionMemory' => (int) median($peaks),
        'ready' => $readyTimes === [] ? null : median($readyTimes),
        'readyMemory' => $readyMemory === [] ? null : (int) median($readyMemory),
        'size' => size($agent['size']),
        'runtime' => $agent['runtime'] ?? null,
        'words' => $words,
    ];
}

echo "\n| Agent | `--version` | Peak memory | Ready (interactive) | Memory when ready | Install size |\n";
echo "|---|---:|---:|---:|---:|---:|\n";
foreach ($results as $name => $r) {
    printf(
        "| %s | %d ms | %s | %s | %s | %s |\n",
        $name,
        (int) round($r['version'] * 1000),
        $mb($r['versionMemory']),
        $r['ready'] === null ? 'n/a' : (int) round($r['ready'] * 1000) . ' ms',
        $r['readyMemory'] === null ? 'n/a' : $mb($r['readyMemory']),
        number_format($r['size'] / 1048576, 1) . ' MB' . ($r['runtime'] !== null ? " + {$r['runtime']}" : ''),
    );
}
echo "\n{$runs} runs of `--version` (median), " . min($runs, 3) . " interactive starts (median), " . COLUMNS . '×' . ROWS . " terminal, ready = last output before " . QUIET . " s of stillness.\n";
echo "\nLast words on each screen when ready (to check nothing was waiting for an answer):\n";
foreach ($results as $name => $r) {
    echo "- {$name}: " . ($r['words'] === '' ? '(nothing)' : $r['words']) . "\n";
}
