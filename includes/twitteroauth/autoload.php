<?php
// Minimal PSR-4 autoloader for Abraham\TwitterOAuth (production ready)
spl_autoload_register(function ($class) {
    $prefix = 'Abraham\\TwitterOAuth\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Add this to autoload Composer\CaBundle if present in vendor
spl_autoload_register(function ($class) {
    if (strpos($class, 'Composer\\CaBundle\\') === 0) {
        $base_dir = __DIR__ . '/vendor/composer/ca-bundle/src/';
        $relative_class = substr($class, strlen('Composer\\CaBundle\\'));
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
