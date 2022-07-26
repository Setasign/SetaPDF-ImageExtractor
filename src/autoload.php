<?php

declare(strict_types=1);

spl_autoload_register(static function ($class) {
    if (strpos($class, 'setasign\SetaPDF\ImageExtractor\\') === 0) {
        $filename = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 32)) . '.php';
        $fullpath = __DIR__ . DIRECTORY_SEPARATOR . $filename;

        if (is_file($fullpath)) {
            require_once $fullpath;
        }
    }
});