#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\Queue\QueueProducer;

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

/** @var Closure(array<string, mixed>=): PDO $dbFactory */
$dbFactory = require $rootDir . '/config/database.php';
$pdo = $dbFactory();

$maxAttempts = (int) ($_ENV['WORKER_MAX_ATTEMPTS'] ?? getenv('WORKER_MAX_ATTEMPTS') ?: 5);
$producer = new QueueProducer($pdo, $maxAttempts);

$demoBatch = [
    new ProductInputDTO(
        externalProductId: 'WC-SKU-9910',
        platform: 'woocommerce',
        productName: 'Notebook Gamer UltraPro 16 RTX 4070',
        category: 'Informática > Notebooks Gamer',
        specifications: [
            'Processador'       => 'Intel Core i9-14900HX (24 núcleos, até 5.8GHz)',
            'Placa de Vídeo'    => 'NVIDIA GeForce RTX 4070 8GB GDDR6 (140W TGP)',
            'Memória RAM'       => '32GB DDR5 5600MHz Dual Channel',
            'Armazenamento'     => 'SSD 1TB NVMe PCIe Gen4 x4',
            'Tela'              => '16 polegadas QHD+ (2560x1600) IPS 240Hz 100% sRGB',
        ],
        brand: 'UltraPro Tech',
        targetAudience: 'Gamers entusiastas e profissionais de renderização 3D'
    ),
    new ProductInputDTO(
        externalProductId: 'M2-SKU-4402',
        platform: 'magento',
        productName: 'Cafeteira Espresso Automática Barista Master Inox',
        category: 'Eletroportáteis > Cafeteiras Espresso',
        specifications: [
            'Pressão da Bomba'  => '19 Bar Italian ULKA Pump',
            'Moedor Integrado'  => 'Moedor cônico em aço temperado com 13 níveis de moagem',
            'Capacidade Água'   => 'Reservatório removível de 1.8 Litros',
            'Potência'          => '1450W (220V)',
            'Material do Corpo' => 'Aço Inoxidável Escovado AISI 304',
        ],
        brand: 'Barista Master',
        targetAudience: 'Apreciadores de café especial e escritórios premium'
    ),
];

echo "[php-seo-pipeline] Enfileirando lote de demonstração com verificação SHA-256...\n";

$result = $producer->enqueueBatch($demoBatch);

printf(
    "[php-seo-pipeline] Concluído! Inseridos: %d | Ignorados por Idempotência (SHA-256): %d\n",
    $result['inserted'],
    $result['skipped']
);
