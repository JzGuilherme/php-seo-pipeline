<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/**
 * Injectable PDO Connection Factory.
 *
 * Returns a closure or direct PDO instance configured for MySQL 8.0+
 * with strict error reporting, real prepared statements, and UTF-8 MB4 encoding.
 *
 * @return Closure(array<string, mixed>=): PDO
 */
return static function (array $overrides = []): PDO {
    $rootDir = dirname(__DIR__);

    if (class_exists(Dotenv::class) && file_exists($rootDir . '/.env')) {
        $dotenv = Dotenv::createImmutable($rootDir);
        $dotenv->safeLoad();
    }

    $host = (string) ($overrides['host'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int) ($overrides['port'] ?? $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
    $database = (string) ($overrides['database'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'seo_pipeline');
    $username = (string) ($overrides['username'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'seo_user');
    $password = (string) ($overrides['password'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        return new PDO($dsn, $username, $password, $options);
    } catch (PDOException $e) {
        // Mask database credentials in stack traces
        throw new RuntimeException(
            sprintf('Database connection failed for host "%s:%d" and schema "%s": %s', $host, $port, $database, $e->getMessage()),
            (int) $e->getCode()
        );
    }
};
