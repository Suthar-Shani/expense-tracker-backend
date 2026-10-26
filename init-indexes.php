<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

echo "=== MongoDB Index Initializer ===\n";
echo "Ensuring indexes for transactions, categories, accounts, and users...\n";

$results = Database::ensureIndexes();

foreach ($results as $index => $status) {
    echo "- {$index}: " . json_encode($status) . "\n";
}

echo "Indexes creation completed!\n";
