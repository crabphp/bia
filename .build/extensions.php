<?php

// Lists the PHP extensions the given PHP files call into: every function and
// class name in them, looked up by reflection. Usage: php extensions.php DIR...

declare(strict_types=1);

$found = [];
foreach (array_slice($argv, 1) as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $tokens = PhpToken::tokenize(file_get_contents($file->getPathname()));
        foreach ($tokens as $i => $token) {
            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $name = ltrim($token->text, '\\');
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST])) {
                continue;
            }
            $extension = null;
            if (function_exists($name)) {
                $extension = (new ReflectionFunction($name))->getExtensionName();
            } elseif (class_exists($name, false) || interface_exists($name, false)) {
                $extension = (new ReflectionClass($name))->getExtensionName();
            } elseif (defined($name)) {
                foreach (get_defined_constants(true) as $group => $constants) {
                    if ($group !== 'user' && array_key_exists($name, $constants)) {
                        $extension = $group;
                        break;
                    }
                }
            }
            if (is_string($extension) && $extension !== '') {
                $found[strtolower($extension)][$name] = true;
            }
        }
    }
}
ksort($found);
foreach ($found as $extension => $names) {
    $names = array_keys($names);
    printf("%-12s %3d  %s\n", $extension, count($names), implode(' ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' …' : ''));
}
