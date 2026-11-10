<?php

// Packs a production install of Bia into a PHAR: Bia's source, the libraries
// it loads, and the framework's data files; PHP stripped of comments and
// whitespace, which nothing reads at run time.
//
// Usage: php -d phar.readonly=0 build.php <install-dir> <out.phar>
// The install dir is a copy of Bia with `composer install --no-dev` run in it.

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 build.php <install-dir> <out.phar>\n");
    exit(2);
}
[, $root, $out] = $argv;
$root = rtrim((string) realpath($root), '/');
if ($root === '' || !is_file("{$root}/vendor/autoload.php")) {
    fwrite(STDERR, "{$argv[1]} has no vendor/autoload.php: run composer install --no-dev in it first.\n");
    exit(1);
}

/** Paths kept, relative to the install: what Bia can load and the files that code reads. */
function kept(string $path): bool
{
    if (str_starts_with($path, 'src/Bia/Plugin/skills/')) {
        return true; // Bia's own skills: the catalog reads them from beside the code
    }
    if (str_starts_with($path, 'src/') || str_starts_with($path, 'vendor/composer/') || $path === 'vendor/autoload.php') {
        return str_ends_with($path, '.php');
    }
    if (preg_match('#^vendor/crabphp/framework/src/HttpClient/data/#', $path)) {
        return true; // PublicSuffixes reads its list from beside it
    }
    if (preg_match('#^vendor/[^/]+/[^/]+/src/#', $path)) {
        return str_ends_with($path, '.php');
    }
    return false;
}

@unlink($out);
$phar = new Phar($out, 0, 'bia.phar');
$phar->startBuffering();
$count = 0;
$before = 0;
$after = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = substr($file->getPathname(), strlen($root) + 1);
    if (!kept($path)) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    $before += strlen($contents);
    if (str_ends_with($path, '.php')) {
        $contents = php_strip_whitespace($file->getPathname());
    }
    $after += strlen($contents);
    $phar->addFromString($path, $contents);
    $count++;
}
// The entry point, without its shebang, which a required file would print.
$phar->addFromString('bin/bia', (string) preg_replace('/^#!.*\n/', '', (string) file_get_contents("{$root}/bin/bia")));
$phar->setStub(<<<'PHP'
    <?php
    Phar::mapPhar('bia.phar');
    require 'phar://bia.phar/bin/bia';
    __HALT_COMPILER();
    PHP);
$phar->stopBuffering();

printf("%d files, %.1f MB stripped to %.1f MB, %s\n", $count, $before / 1048576, $after / 1048576, $out);
