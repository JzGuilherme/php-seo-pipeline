#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use JzGuilherme\SeoPipeline\Adapters\Magento2Adapter;
use JzGuilherme\SeoPipeline\Adapters\WooCommerceAdapter;
use JzGuilherme\SeoPipeline\Providers\GeminiAiProvider;
use JzGuilherme\SeoPipeline\Queue\QueueWorker;
use JzGuilherme\SeoPipeline\Services\HtmlSanitizerService;
use JzGuilherme\SeoPipeline\Services\SeoContentGenerator;
use JzGuilherme\SeoPipeline\Services\SeoValidatorService;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoloadPath;

$rootDir = dirname(__DIR__);
if (class_exists(Dotenv::class) && file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}

$logger = new Logger('seo-worker');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Info));

/** @var Closure(array<string, mixed>=): PDO $dbFactory */
$dbFactory = require $rootDir . '/config/database.php';
$pdo = $dbFactory();

$apiKey = (string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '');
$timeoutSeconds = (int) ($_ENV['GEMINI_TIMEOUT_SECONDS'] ?? getenv('GEMINI_TIMEOUT_SECONDS') ?: 30);
$sleepSeconds = (int) ($_ENV['WORKER_SLEEP_SECONDS'] ?? getenv('WORKER_SLEEP_SECONDS') ?: 3);
$maxMemoryMb = (int) ($_ENV['WORKER_MAX_MEMORY_MB'] ?? getenv('WORKER_MAX_MEMORY_MB') ?: 128);

$aiProvider = new GeminiAiProvider($apiKey, $timeoutSeconds);
$sanitizer = new HtmlSanitizerService();
$validator = new SeoValidatorService($sanitizer);
$generator = new SeoContentGenerator($aiProvider, $validator, $pdo);

$adapters = [
    new WooCommerceAdapter(
        apiUrl: (string) ($_ENV['WOOCOMMERCE_API_URL'] ?? getenv('WOOCOMMERCE_API_URL') ?: ''),
        consumerKey: (string) ($_ENV['WOOCOMMERCE_CONSUMER_KEY'] ?? getenv('WOOCOMMERCE_CONSUMER_KEY') ?: ''),
        consumerSecret: (string) ($_ENV['WOOCOMMERCE_CONSUMER_SECRET'] ?? getenv('WOOCOMMERCE_CONSUMER_SECRET') ?: '')
    ),
    new Magento2Adapter(
        baseUrl: (string) ($_ENV['MAGENTO_BASE_URL'] ?? getenv('MAGENTO_BASE_URL') ?: ''),
        accessToken: (string) ($_ENV['MAGENTO_ACCESS_TOKEN'] ?? getenv('MAGENTO_ACCESS_TOKEN') ?: '')
    ),
];

$worker = new QueueWorker(
    pdo: $pdo,
    generator: $generator,
    platformAdapters: $adapters,
    logger: $logger,
    memoryLimitBytes: $maxMemoryMb * 1024 * 1024
);

$worker->work($sleepSeconds);
