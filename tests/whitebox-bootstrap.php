<?php

// Fail before Laravel or RefreshDatabase can access any configured database.
if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:' || getenv('DB_URL')) {
    throw new RuntimeException('Whitebox requires the isolated SQLite :memory: database.');
}
$cache = __DIR__.'/../bootstrap/cache/whitebox-unused-config.php';
if (file_exists($cache)) throw new RuntimeException('Unexpected whitebox configuration cache.');
putenv('APP_CONFIG_CACHE='.$cache);
$_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $cache;
require __DIR__.'/../vendor/autoload.php';
