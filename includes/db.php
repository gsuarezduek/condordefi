<?php

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $config = require __DIR__ . '/../config.php';
        $c = $config['db'];
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
