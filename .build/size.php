<?php

// Where the bytes of a micro.sfx go: each symbol (from its debug file) put
// down to the PHP source directory or static library it came from.
//
// Usage: php size.php <micro.sfx> <syms.txt> <libsyms.txt>, in .build/spc/buildroot:
//   nm -S -l --defined-only debug/micro.sfx.debug > syms.txt
//   for a in lib/*.a /usr/lib/libc.a; do nm --defined-only -A "$a" | awk -v lib="$(basename "$a" .a)" \
//     'NF >= 3 && $(NF-1) ~ /^[TtDdRrBbVvWw]$/ { print lib, $NF }'; done > libsyms.txt
// (libc.a: musl's, in the Alpine the binary was built in)

declare(strict_types=1);

[, $binary, $symsFile, $libsFile] = $argv;

// The sections, by address: a symbol is counted in the one it lies in, as the
// debug file's type letters call read-only data bss.
$sections = [];
exec('readelf -SW ' . escapeshellarg($binary), $lines);
foreach ($lines as $line) {
    if (preg_match('/\]\s+(\.\S+)\s+\S+\s+([0-9a-f]{16})\s+[0-9a-f]+\s+([0-9a-f]+)/', $line, $m) && hexdec($m[2]) > 0) {
        $sections[] = [$m[1], hexdec($m[2]), hexdec($m[2]) + hexdec($m[3])];
    }
}
$sectionOf = static function (int $address) use ($sections): string {
    foreach ($sections as [$name, $start, $end]) {
        if ($address >= $start && $address < $end) {
            return $name;
        }
    }
    return '';
};

// Which library defines a symbol, from the archives linked in.
$library = [];
foreach (file($libsFile, FILE_IGNORE_NEW_LINES) as $line) {
    [$lib, $symbol] = explode(' ', $line, 2) + [1 => ''];
    $library[$symbol] ??= $lib;
}

/** The PHP part a source file belongs to. */
function part(string $file): string
{
    if (preg_match('#/php-src/(ext/[a-z0-9_]+)/#', $file, $m)) {
        return "php {$m[1]}";
    }
    if (preg_match('#/php-src/(Zend|main|sapi/micro|TSRM)/#', $file, $m)) {
        return 'php ' . ($m[1] === 'Zend' ? 'Zend engine' : $m[1]);
    }
    if (preg_match('#/source/([a-z0-9_-]+)/#', $file, $m)) {
        return $m[1];
    }
    return '';
}

$parts = [];
$kinds = [];
$top = [];
foreach (file($symsFile, FILE_IGNORE_NEW_LINES) as $line) {
    // address size type name [file:line]
    if (!preg_match('/^([0-9a-f]+) ([0-9a-f]+) (\w) (\S+)(?:\t(\S+))?/', $line, $m)) {
        continue;
    }
    [, $address, $hex, , $name] = $m;
    $m[4] = $m[5] ?? '';
    $size = hexdec($hex);
    $where = part($m[4] ?? '');
    $base = preg_replace('/\.(localalias|constprop|isra|part|cold|lto_priv)(\.\d+)*$/', '', $name);
    if ($where === '') {
        $where = $library[$name] ?? $library[$base] ?? (str_starts_with($name, '__') || str_starts_with($name, '_') ? 'libc' : 'unknown');
    }
    $kind = match ($sectionOf(hexdec($address))) {
        '.text', '.init', '.fini' => 'code',
        '.rodata', '.data.rel.ro' => 'rodata',
        '.data' => 'data',
        '.bss', '.tbss' => 'bss',
        default => 'other',
    };
    $parts[$where][$kind] = ($parts[$where][$kind] ?? 0) + $size;
    $kinds[$kind] = ($kinds[$kind] ?? 0) + $size;
    $top[$where][] = [$size, $name];
}

$file = filesize($binary);
$mb = static fn (int|float $b): string => sprintf('%5.2f', $b / 1048576);

echo "Sections in the file:\n";
foreach ($sections as [$name, $start, $end]) {
    if ($name !== '.bss' && $end - $start >= 4096) {
        printf("  %s MB  %s\n", $mb($end - $start), $name);
    }
}
echo "\n";

// Groups for reading: libraries by what they are for.
$group = static fn (string $part): string => match (true) {
    in_array($part, ['libcrypto', 'libssl'], true) => 'OpenSSL (HTTPS)',
    $part === 'libsqlite3' || $part === 'php ext/sqlite3' || $part === 'php ext/pdo_sqlite' || $part === 'php ext/pdo' => 'SQLite (sessions)',
    in_array($part, ['libedit', 'libncurses', 'libcurses', 'libtic', 'php ext/readline'], true) => 'readline (libedit + ncurses)',
    in_array($part, ['libc', 'libgcc', 'libgcc_eh'], true) => 'C library (musl + libgcc)',
    $part === 'libz' || $part === 'php ext/zlib' => 'zlib',
    str_starts_with($part, 'php ') => 'PHP',
    default => 'other',
};

$groups = [];
foreach ($parts as $part => $byKind) {
    $groups[$group($part)][$part] = array_sum($byKind) - ($byKind['bss'] ?? 0);
}
uasort($groups, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

$onDisk = array_sum(array_map('array_sum', $groups));
printf("micro.sfx %s MB on disk; %s MB of it in named symbols (code, read-only data, data; not .bss)\n\n", $mb($file), $mb($onDisk));
foreach ($groups as $name => $members) {
    arsort($members);
    printf("%s MB  %4.1f%%  %s\n", $mb(array_sum($members)), 100 * array_sum($members) / $file, $name);
    foreach ($members as $part => $bytes) {
        if ($bytes >= 8192) {
            $k = $parts[$part];
            printf("          %s MB  %-24s code %s · tables %s · data %s\n", $mb($bytes), $part, $mb($k['code'] ?? 0), $mb($k['rodata'] ?? 0), $mb($k['data'] ?? 0));
        }
    }
}

echo "\nLargest symbols:\n";
$all = [];
foreach ($top as $part => $symbols) {
    foreach ($symbols as [$size, $name]) {
        $all[] = [$size, $name, $part];
    }
}
usort($all, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
foreach (array_slice($all, 0, 20) as [$size, $name, $part]) {
    printf("  %6.0f KB  %-42s %s\n", $size / 1024, $name, $part);
}
