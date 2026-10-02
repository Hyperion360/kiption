<?php // worktree-local autoloader prepended by phpunit.xml's bootstrap
// The shared vendor/ (symlinked) binds App\ to the REAL repo's app/src, so a
// worktree's app edits would be invisible to its suite. Prepend the worktree's
// own PSR-4 maps; unknown classes fall through to the shared loader.
spl_autoload_register(function (string $class): void {
    foreach (['App\\' => __DIR__ . '/../app/src/', 'App\\Features\\' => __DIR__ . '/../app/Features/', 'App\\Tests\\' => __DIR__ . '/../tests/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
}, true, true /* prepend: beats the shared loader */);
