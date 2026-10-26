<?php

// Loads the SDK for the test scripts: Composer's autoloader when installed, else straight from src/.

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';

    return;
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (strpos($class, 'Zharp\\Logs\\') === 0) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen('Zharp\\Logs\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
