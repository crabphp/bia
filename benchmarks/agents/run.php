<?php

declare(strict_types=1);

/**
 * Runs one task through several coding agents on the same model, through
 * one metering proxy, and writes a page to compare what each made.
 *
 *   php benchmarks/agents/run.php [--task=voxel-world] [--agents=bia,claude,codex,pi]
 *       [--model=gpt-6-astra] [--effort=medium] [--runs=1] [--timeout=2400] [--sequential]
 *   php benchmarks/agents/run.php --recheck=results/<run>   (check again, rewrite the page)
 *
 * The upstream is BENCH_UPSTREAM (an OpenAI-style base URL ending in /v1)
 * and BENCH_UPSTREAM_KEY; both default to the "tdaproxy" provider in
 * ~/.bia/config.php. The upstream must also answer Anthropic's /v1/messages
 * for Claude Code. Each agent runs in a fresh git repository under
 * ~/.cache/bia-bench (Codex refuses its helpers under /tmp), outside any
 * project, with its own empty home: no user instructions, memory, settings
 * or MCP servers reach it. Results land in results/, which git ignores.
 */

const AGENTS = ['bia', 'claude', 'codex', 'pi'];
const MAX_OUTPUT_TOKENS = 64_000;

$options = getopt('', ['task:', 'agents:', 'model:', 'effort:', 'runs:', 'timeout:', 'sequential', 'recheck:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, (string) preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string) file_get_contents(__FILE__))[1])[0]));
    exit(0);
}
$here = __DIR__;
if (isset($options['recheck'])) {
    recheck((string) $options['recheck'], $here);
    exit(0);
}
$task = (string) ($options['task'] ?? 'voxel-world');
$agents = array_values(array_filter(explode(',', (string) ($options['agents'] ?? implode(',', AGENTS)))));
$model = (string) ($options['model'] ?? 'gpt-6-astra');
$effort = (string) ($options['effort'] ?? 'medium');
$runs = max(1, (int) ($options['runs'] ?? 1));
$timeout = max(60, (int) ($options['timeout'] ?? 2400));
$sequential = isset($options['sequential']);

$promptFile = "{$here}/tasks/{$task}/prompt.md";
if (!is_file($promptFile)) {
    fail("No task {$task}: {$promptFile} is missing.");
}
foreach ($agents as $agent) {
    if (!in_array($agent, AGENTS, true)) {
        fail("Unknown agent {$agent}; choose from " . implode(', ', AGENTS) . '.');
    }
}
$prompt = trim((string) file_get_contents($promptFile));
[$upstream, $key] = upstream();
$binaries = [
    'bia' => dirname($here, 2) . '/bin/bia',
    'claude' => which('claude'),
    'codex' => which('codex'),
    'pi' => which('pi'),
    'node' => which('node'),
];
foreach ($agents as $agent) {
    if ($binaries[$agent] === null) {
        fail("{$agent} is not installed.");
    }
}
$shell = headlessShell();

$stamp = date('Ymd-His');
$results = "{$here}/results/{$stamp}-{$task}";
$scratch = (getenv("XDG_CACHE_HOME") ?: getenv("HOME") . "/.cache") . "/bia-bench/{$stamp}";
mkdir($results, 0755, true);
mkdir($scratch, 0700, true);

// The meter, on a free port.
$port = freePort();
$meterLog = "{$results}/meter.jsonl";
touch($meterLog);
$meter = proc_open(
    [$binaries['node'], "{$here}/meter.mjs", (string) $port, $upstream, $meterLog],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "{$results}/meter.err", 'w']],
    $meterPipes,
    null,
    ['PATH' => (string) getenv('PATH'), 'BENCH_UPSTREAM_KEY' => $key],
);
if (!is_resource($meter) || !str_contains((string) fgets($meterPipes[1]), 'ready')) {
    fail('The meter did not start; see ' . "{$results}/meter.err");
}
register_shutdown_function(static function () use ($meter): void {
    proc_terminate($meter);
});

say("Task {$task} · model {$model} · effort {$effort} · " . implode(', ', $agents) . " · {$runs} run(s) each");
say("Results: {$results}");

// Every run gets its own repository and home.
$queue = [];
for ($n = 1; $n <= $runs; $n++) {
    foreach ($agents as $agent) {
        $id = "{$agent}-{$n}";
        $dir = "{$scratch}/{$id}";
        $work = "{$dir}/work";
        $home = "{$dir}/home";
        mkdir($work, 0755, true);
        mkdir($home, 0700, true);
        git($work, ['init', '-q']);
        git($work, ['-c', 'user.name=bench', '-c', 'user.email=bench@localhost', 'commit', '-q', '--allow-empty', '-m', 'Start']);
        $base = "http://127.0.0.1:{$port}/r/" . rawurlencode("{$stamp}-{$id}");
        $queue[] = ['id' => $id, 'agent' => $agent, 'run' => $n, 'dir' => $dir, 'work' => $work, 'home' => $home, 'meter' => "{$stamp}-{$id}"]
            + command($agent, $binaries, $prompt, $model, $effort, $work, $home, $base);
    }
}

// Run them: all at once by default, under the same upstream conditions.
$running = [];
$done = [];
while ($queue !== [] || $running !== []) {
    while ($queue !== [] && ($running === [] || !$sequential)) {
        $job = array_shift($queue);
        $job['started'] = microtime(true);
        $job['process'] = proc_open(
            ['setsid', ...$job['command']],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$job['dir']}/stdout.txt", 'w'], 2 => ['file', "{$job['dir']}/stderr.txt", 'w']],
            $pipes,
            $job['work'],
            $job['env'],
        );
        if (!is_resource($job['process'])) {
            fail("Could not start {$job['id']}.");
        }
        $job['pid'] = (int) proc_get_status($job['process'])['pid'];
        say("· {$job['id']} started");
        $running[$job['id']] = $job;
    }
    usleep(250_000);
    foreach ($running as $id => $job) {
        $status = proc_get_status($job['process']);
        $elapsed = microtime(true) - $job['started'];
        $timedOut = false;
        if ($status['running'] && $elapsed > $timeout) {
            posix_kill(-$job['pid'], SIGKILL);
            $timedOut = true;
            usleep(200_000);
            $status = proc_get_status($job['process']);
        }
        if ($status['running']) {
            continue;
        }
        // Anything it left running in the background goes with it.
        @posix_kill(-$job['pid'], SIGKILL);
        $job['seconds'] = round(microtime(true) - $job['started'], 1);
        $job['exit'] = $timedOut ? null : $status['exitcode'];
        $job['timed_out'] = $timedOut;
        unset($running[$id]);
        $done[] = $job;
        say("· {$id} finished in " . duration($job['seconds']) . ($timedOut ? ' (timed out)' : " (exit {$job['exit']})"));
    }
}
proc_terminate($meter);
usleep(200_000);

// Collect: the output, its check, and what the meter saw.
$meterRows = array_values(array_filter(array_map(
    static fn (string $line): ?array => json_decode($line, true),
    file($meterLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
)));
$rows = [];
foreach ($done as $job) {
    $out = "{$results}/{$job['id']}";
    mkdir($out, 0755, true);
    copyTree($job['work'], "{$out}/work", skip: ['.git']);
    foreach (['stdout.txt', 'stderr.txt'] as $file) {
        copy("{$job['dir']}/{$file}", "{$out}/{$file}");
    }
    file_put_contents("{$out}/diff.stat", git($job['work'], ['status', '--short']));
    $requests = array_values(array_filter($meterRows, static fn (array $row): bool => ($row['run'] ?? '') === $job['meter']));
    $check = check($shell, "{$out}/work/index.html", "{$out}/shot.png");
    file_put_contents("{$out}/check.txt", implode("\n", $check['console']) . "\n");
    $rows[] = [
        'id' => $job['id'],
        'agent' => $job['agent'],
        'run' => $job['run'],
        'seconds' => $job['seconds'],
        'exit' => $job['exit'],
        'timed_out' => $job['timed_out'],
        'requests' => count($requests),
        'failed_requests' => count(array_filter($requests, static fn (array $r): bool => ($r['status'] ?? 0) >= 400 || ($r['status'] ?? 0) === 0)),
        'input' => array_sum(array_map(static fn (array $r): int => (int) ($r['usage']['input'] ?? 0), $requests)),
        'cached' => array_sum(array_map(static fn (array $r): int => (int) ($r['usage']['cached'] ?? 0), $requests)),
        'output' => array_sum(array_map(static fn (array $r): int => (int) ($r['usage']['output'] ?? 0), $requests)),
        'reasoning' => array_sum(array_map(static fn (array $r): int => (int) ($r['usage']['reasoning'] ?? 0), $requests)),
        'model_seconds' => round(array_sum(array_map(static fn (array $r): int => (int) ($r['total_ms'] ?? 0), $requests)) / 1000, 1),
        'first_token_ms' => $requests === [] ? null : ($requests[0]['first_ms'] ?? null),
        'file_bytes' => is_file("{$out}/work/index.html") ? filesize("{$out}/work/index.html") : 0,
        'loads' => $check['loads'],
        'draws' => $check['draws'],
        'errors' => $check['errors'],
        'answer' => answer($job['agent'], "{$out}/stdout.txt"),
    ];
}
usort($rows, static fn (array $a, array $b): int => [$a['run'], array_search($a['agent'], AGENTS, true)] <=> [$b['run'], array_search($b['agent'], AGENTS, true)]);
file_put_contents("{$results}/runs.json", json_encode([
    'task' => $task,
    'model' => $model,
    'effort' => $effort,
    'started' => $stamp,
    'sequential' => $sequential,
    'runs' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents("{$results}/index.html", page($task, $model, $effort, $prompt, $rows));
exec('rm -rf ' . escapeshellarg($scratch));

say('');
say(table($rows));
say('');
say("Open {$results}/index.html");

// ---------------------------------------------------------------------------

/**
 * @return array{command: list<string>, env: array<string, string>}
 */
function command(string $agent, array $binaries, string $prompt, string $model, string $effort, string $work, string $home, string $base): array
{
    $env = [
        'PATH' => (string) getenv('PATH'),
        'HOME' => (string) getenv('HOME'),
        'LANG' => 'C.UTF-8',
        'TERM' => 'dumb',
        'NO_COLOR' => '1',
    ];
    switch ($agent) {
        case 'bia':
            file_put_contents("{$home}/config.php", '<?php return ' . var_export([
                'providers' => ['bench' => [
                    'base_url' => "{$base}/v1",
                    'api_key' => 'bench',
                    'wire_api' => 'responses',
                    'endpoint' => 'responses',
                    'models' => [$model => ['context_window' => 400_000]],
                ]],
                'model' => ['name' => "bench/{$model}", 'reasoning_effort' => $effort, 'context_window' => 400_000],
            ], true) . ";\n");
            return [
                'command' => ['php', $binaries['bia'], '--model', "bench/{$model}", '--effort', $effort, $prompt],
                'env' => $env + ['BIA_HOME' => $home],
            ];
        case 'claude':
            return [
                'command' => [$binaries['claude'], '-p', $prompt, '--output-format', 'json', '--dangerously-skip-permissions', '--model', $model, '--effort', $effort],
                'env' => $env + [
                    'CLAUDE_CONFIG_DIR' => $home,
                    'ANTHROPIC_BASE_URL' => $base,
                    'ANTHROPIC_AUTH_TOKEN' => 'bench',
                    'ANTHROPIC_MODEL' => $model,
                    'ANTHROPIC_DEFAULT_OPUS_MODEL' => $model,
                    'ANTHROPIC_DEFAULT_SONNET_MODEL' => $model,
                    'ANTHROPIC_DEFAULT_HAIKU_MODEL' => $model,
                    'CLAUDE_CODE_SUBAGENT_MODEL' => $model,
                    'CLAUDE_CODE_MAX_OUTPUT_TOKENS' => (string) MAX_OUTPUT_TOKENS,
                    'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
                    'DISABLE_AUTOUPDATER' => '1',
                ],
            ];
        case 'codex':
            file_put_contents("{$home}/config.toml", implode("\n", [
                'model = ' . json_encode($model, JSON_UNESCAPED_SLASHES),
                'model_provider = "bench"',
                'model_reasoning_effort = ' . json_encode($effort, JSON_UNESCAPED_SLASHES),
                'check_for_update_on_startup = false',
                '',
                '[model_providers.bench]',
                'name = "bench"',
                'base_url = ' . json_encode("{$base}/v1", JSON_UNESCAPED_SLASHES),
                'env_key = "BENCH_KEY"',
                'wire_api = "responses"',
                '',
            ]));
            return [
                'command' => [$binaries['codex'], 'exec', '--json', '--dangerously-bypass-approvals-and-sandbox', '--skip-git-repo-check', '-C', $work, $prompt],
                'env' => $env + ['CODEX_HOME' => $home, 'BENCH_KEY' => 'bench'],
            ];
        case 'pi':
            file_put_contents("{$home}/models.json", json_encode(['providers' => ['bench' => [
                'baseUrl' => "{$base}/v1",
                'api' => 'openai-responses',
                'apiKey' => 'bench',
                'models' => [[
                    'id' => $model,
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'contextWindow' => 400_000,
                    'maxTokens' => MAX_OUTPUT_TOKENS,
                ]],
            ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return [
                'command' => [$binaries['pi'], '-p', '--mode', 'json', '--session-dir', "{$home}/sessions", '--model', "bench/{$model}", '--thinking', $effort, $prompt],
                'env' => $env + ['PI_CODING_AGENT_DIR' => $home, 'PI_OFFLINE' => '1', 'PI_SKIP_VERSION_CHECK' => '1', 'PI_TELEMETRY' => '0'],
            ];
    }
    fail("No command for {$agent}.");
}

/**
 * Checks a finished run's pages again and rewrites its runs.json and page,
 * without running any agent.
 */
function recheck(string $results, string $here): void
{
    $results = realpath($results) ?: fail("No results at {$results}.");
    $data = json_decode((string) @file_get_contents("{$results}/runs.json"), true);
    if (!is_array($data) || !is_array($data['runs'] ?? null)) {
        fail("{$results}/runs.json is missing or unreadable.");
    }
    $shell = headlessShell();
    foreach ($data['runs'] as $i => $row) {
        $out = "{$results}/{$row['id']}";
        @unlink("{$out}/shot.png");
        $check = check($shell, "{$out}/work/index.html", "{$out}/shot.png");
        file_put_contents("{$out}/check.txt", implode("\n", $check['console']) . "\n");
        unset($row['file_lines']);
        $data['runs'][$i] = array_merge($row, [
            'file_bytes' => is_file("{$out}/work/index.html") ? filesize("{$out}/work/index.html") : 0,
            'loads' => $check['loads'],
            'draws' => $check['draws'],
            'errors' => $check['errors'],
        ]);
    }
    file_put_contents("{$results}/runs.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $prompt = trim((string) @file_get_contents("{$here}/tasks/{$data['task']}/prompt.md"));
    file_put_contents("{$results}/index.html", page((string) $data['task'], (string) $data['model'], (string) $data['effort'], $prompt, $data['runs']));
    say(table($data['runs']));
    say('');
    say("Open {$results}/index.html");
}

/**
 * The agent's closing message, from what it printed.
 */
function answer(string $agent, string $stdout): string
{
    $text = (string) @file_get_contents($stdout);
    $last = '';
    switch ($agent) {
        case 'claude':
            $json = json_decode($text, true);
            return is_array($json) ? (string) ($json['result'] ?? '') : tail($text);
        case 'codex':
            foreach (explode("\n", $text) as $line) {
                $event = json_decode($line, true);
                $item = $event['item'] ?? null;
                if (is_array($item) && ($item['type'] ?? '') === 'agent_message' && is_string($item['text'] ?? null)) {
                    $last = $item['text'];
                }
            }
            return $last !== '' ? $last : tail($text);
        case 'pi':
            foreach (explode("\n", $text) as $line) {
                $event = json_decode($line, true);
                $message = $event['message'] ?? null;
                if (($event['type'] ?? '') === 'message_end' && is_array($message) && ($message['role'] ?? '') === 'assistant') {
                    $parts = array_filter((array) ($message['content'] ?? []), static fn ($part): bool => is_array($part) && ($part['type'] ?? '') === 'text');
                    $joined = trim(implode("\n", array_map(static fn (array $part): string => (string) $part['text'], $parts)));
                    if ($joined !== '') {
                        $last = $joined;
                    }
                }
            }
            return $last !== '' ? $last : tail($text);
    }
    return tail($text);
}

function tail(string $text, int $lines = 40): string
{
    return implode("\n", array_slice(explode("\n", rtrim($text)), -$lines));
}

/**
 * Loads the page headless with software WebGL, for its console and a
 * screenshot: whether it loads without errors and draws more than one colour.
 *
 * @return array{loads: bool, draws: bool, errors: int, console: list<string>}
 */
function check(?string $shell, string $page, string $shot): array
{
    if (!is_file($page)) {
        return ['loads' => false, 'draws' => false, 'errors' => 0, 'console' => ['index.html was not written.']];
    }
    if ($shell === null) {
        return ['loads' => true, 'draws' => false, 'errors' => 0, 'console' => ['No headless Chromium found; nothing checked.']];
    }
    $profile = sys_get_temp_dir() . '/bia-bench-chrome-' . bin2hex(random_bytes(4));
    $command = [
        'setsid', 'timeout', '-k', '5', '60', $shell, '--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
        '--user-data-dir=' . $profile, '--window-size=1280,720', '--virtual-time-budget=8000',
        '--enable-logging=stderr', '--v=0', '--screenshot=' . $shot, 'file://' . $page,
    ];
    // A file, not a pipe: Chromium's helpers can outlive the timeout and would hold a pipe open.
    $log = "{$profile}.log";
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    $pid = (int) proc_get_status($process)['pid'];
    proc_close($process);
    @posix_kill(-$pid, SIGKILL);
    $output = (string) @file_get_contents($log);
    @unlink($log);
    exec('rm -rf ' . escapeshellarg($profile));
    $console = [];
    foreach (explode("\n", (string) $output) as $line) {
        if (preg_match('/:CONSOLE[^\]]*\] (.*)$/', $line, $match) === 1) {
            $console[] = $match[1];
        }
    }
    $errors = count(array_filter($console, static fn (string $line): bool => preg_match('/Uncaught|Error|error|failed|Failed|INVALID_/', $line) === 1));
    return ['loads' => is_file($shot), 'draws' => is_file($shot) && colours($shot) >= 16, 'errors' => $errors, 'console' => $console];
}

/**
 * How many distinct colours a grid of samples over the screenshot finds.
 */
function colours(string $png): int
{
    $image = @imagecreatefrompng($png);
    if ($image === false) {
        return 0;
    }
    $seen = [];
    $width = imagesx($image);
    $height = imagesy($image);
    for ($y = 5; $y < $height; $y += 23) {
        for ($x = 5; $x < $width; $x += 23) {
            $seen[imagecolorat($image, $x, $y) >> 2 & 0x3F3F3F] = true;
        }
    }
    return count($seen);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function table(array $rows): string
{
    $head = ['run', 'time', 'model time', 'requests', 'input', 'cached', 'output', 'size', 'screenshot', 'draws', 'errors'];
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = [
            $row['id'],
            duration((float) $row['seconds']) . ($row['timed_out'] ? ' (timeout)' : ''),
            duration((float) $row['model_seconds']),
            $row['requests'] . ($row['failed_requests'] > 0 ? " ({$row['failed_requests']} failed)" : ''),
            thousands((int) $row['input']),
            thousands((int) $row['cached']),
            thousands((int) $row['output']),
            kilobytes((int) $row['file_bytes']),
            $row['loads'] ? 'yes' : 'no',
            $row['draws'] ? 'yes' : 'no',
            (string) $row['errors'],
        ];
    }
    $widths = array_map(static fn (int $i): int => max(array_map(static fn (array $l): int => mb_strlen((string) $l[$i]), [$head, ...$lines])), array_keys($head));
    $format = static fn (array $cells): string => implode('  ', array_map(static fn ($cell, int $w): string => str_pad((string) $cell, $w), $cells, $widths));
    return implode("\n", [$format($head), ...array_map($format, $lines)]);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function page(string $task, string $model, string $effort, string $prompt, array $rows): string
{
    $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $tableRows = '';
    foreach ($rows as $row) {
        $tableRows .= '<tr><td><a href="#' . $e($row['id']) . '">' . $e($row['id']) . '</a></td>'
            . '<td>' . duration((float) $row['seconds']) . ($row['timed_out'] ? ' <b class=bad>timeout</b>' : '') . '</td>'
            . '<td>' . duration((float) $row['model_seconds']) . '</td>'
            . '<td>' . $row['requests'] . ($row['failed_requests'] > 0 ? ' <span class=bad>(' . $row['failed_requests'] . ' failed)</span>' : '') . '</td>'
            . '<td>' . thousands((int) $row['input']) . '</td><td>' . thousands((int) $row['cached']) . '</td>'
            . '<td>' . thousands((int) $row['output']) . '</td><td>' . thousands((int) $row['reasoning']) . '</td>'
            . '<td>' . kilobytes((int) $row['file_bytes']) . '</td>'
            . '<td class=' . ($row['loads'] && $row['draws'] && $row['errors'] === 0 ? 'good>ok' : 'bad>' . ($row['loads'] ? ($row['draws'] ? '' : 'blank ') . ($row['errors'] > 0 ? $row['errors'] . ' errors' : '') : ($row['file_bytes'] > 0 ? 'no screenshot ' . ($row['errors'] > 0 ? $row['errors'] . ' errors' : '') : 'missing'))) . '</td></tr>';
    }
    $cards = '';
    foreach ($rows as $row) {
        $id = $e($row['id']);
        $cards .= "<section id=\"{$id}\"><h2>{$id}</h2>"
            . "<p class=links><a href=\"{$id}/work/index.html\" target=_blank>Open full page</a> · <a href=\"{$id}/stdout.txt\">stdout</a> · <a href=\"{$id}/stderr.txt\">stderr</a> · <a href=\"{$id}/check.txt\">console</a> · <a href=\"{$id}/diff.stat\">files</a></p>"
            . "<div class=pair><iframe src=\"{$id}/work/index.html\" loading=lazy allow=\"pointer-lock\"></iframe>"
            . "<figure><img src=\"{$id}/shot.png\" alt=\"Headless screenshot of {$id}\"><figcaption>Headless screenshot at load, software WebGL</figcaption></figure></div>"
            . '<details open><summary>What it said it built</summary><pre>' . $e((string) $row['answer']) . '</pre></details></section>';
    }
    return <<<HTML
    <!doctype html>
    <meta charset=utf-8>
    <title>{$e($task)} · {$e($model)}</title>
    <style>
    body{font:14px/1.5 system-ui,sans-serif;margin:24px;background:#111;color:#ddd}
    a{color:#8cf} table{border-collapse:collapse;margin:16px 0} td,th{padding:4px 10px;border-bottom:1px solid #333;text-align:left}
    .good{color:#6d6}.bad{color:#f77} section{margin:40px 0;border-top:1px solid #333}
    .pair{display:grid;grid-template-columns:1fr 1fr;gap:12px} iframe{width:100%;aspect-ratio:16/9;border:1px solid #333;background:#000}
    figure{margin:0} img{width:100%;border:1px solid #333} figcaption{color:#888;font-size:12px}
    pre{white-space:pre-wrap;background:#1a1a1a;padding:12px;border-radius:6px} details summary{cursor:pointer;color:#aaa}
    </style>
    <h1>{$e($task)}</h1>
    <p>Model <b>{$e($model)}</b> · effort <b>{$e($effort)}</b> · every request through one meter. Click a live frame to play it; open the full page for pointer lock.</p>
    <table><tr><th>Run</th><th>Time</th><th>Model time</th><th>Requests</th><th>Input</th><th>Cached</th><th>Output</th><th>Reasoning</th><th>Size</th><th>Check</th></tr>{$tableRows}</table>
    <details><summary>The prompt</summary><pre>{$e($prompt)}</pre></details>
    {$cards}
    HTML;
}

/**
 * @return array{string, string}
 */
function upstream(): array
{
    $base = getenv('BENCH_UPSTREAM') ?: null;
    $key = getenv('BENCH_UPSTREAM_KEY') ?: null;
    $config = (string) (getenv('BIA_HOME') ?: getenv('HOME') . '/.bia') . '/config.php';
    if (($base === null || $key === null) && is_file($config)) {
        $settings = (static fn (): mixed => require $config)();
        $provider = is_array($settings) ? ($settings['providers']['tdaproxy'] ?? null) : null;
        if (is_array($provider)) {
            $base ??= is_string($provider['base_url'] ?? null) ? $provider['base_url'] : null;
            $key ??= is_string($provider['api_key'] ?? null) ? $provider['api_key'] : null;
        }
    }
    if ($base === null || $key === null) {
        fail('Set BENCH_UPSTREAM and BENCH_UPSTREAM_KEY, or a "tdaproxy" provider in ~/.bia/config.php.');
    }
    // The meter adds the /v1 the agents ask for.
    return [(string) preg_replace('#/v1/?$#', '', rtrim($base, '/')), $key];
}

function headlessShell(): ?string
{
    $found = glob((string) getenv('HOME') . '/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell') ?: [];
    rsort($found, SORT_NATURAL);
    return $found[0] ?? which('chromium') ?? which('google-chrome');
}

function which(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file("{$dir}/{$name}") && is_executable("{$dir}/{$name}")) {
            return "{$dir}/{$name}";
        }
    }
    return null;
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/**
 * @param list<string> $arguments
 */
function git(string $dir, array $arguments): string
{
    $process = proc_open(['git', '-C', $dir, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = (string) stream_get_contents($pipes[1]);
    proc_close($process);
    return $output;
}

/**
 * @param list<string> $skip
 */
function copyTree(string $from, string $to, array $skip = []): void
{
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || in_array($name, $skip, true)) {
            continue;
        }
        $path = "{$from}/{$name}";
        if (is_link($path)) {
            continue;
        }
        is_dir($path) ? copyTree($path, "{$to}/{$name}") : copy($path, "{$to}/{$name}");
    }
}

function duration(float $seconds): string
{
    $seconds = (int) round($seconds);
    return $seconds < 60 ? "{$seconds}s" : intdiv($seconds, 60) . 'm ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
}

function thousands(int $tokens): string
{
    return $tokens >= 1000 ? number_format($tokens / 1000, $tokens >= 100_000 ? 0 : 1) . 'k' : (string) $tokens;
}

function kilobytes(int $bytes): string
{
    return number_format($bytes / 1024, 1) . ' KB';
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "bench: {$message}\n");
    exit(1);
}
