<?php

/**
 * Simple Autoloader for AgeWallet SDK
 *
 * Use this if you are not using Composer.
 * Usage: require_once 'path/to/agewallet-sdk/autoload.php';
 */

spl_autoload_register(function ($class) {
    $prefix = 'AgeWallet\\Sdk\\';
    $base_dir = __DIR__ . '/src/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});