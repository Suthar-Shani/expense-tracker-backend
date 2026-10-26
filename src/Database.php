<?php

namespace App;

class Database
{
    private static array $collections = [];

    /**
     * Get a MongoDB collection handler.
     * Uses official MongoDB\Client if PHP mongodb extension is enabled,
     * otherwise uses MongoCollection wire driver.
     *
     * @param string $collectionName
     * @return mixed
     */
    public static function getCollection(string $collectionName)
    {
        if (isset(self::$collections[$collectionName])) {
            return self::$collections[$collectionName];
        }

        $uri = $_ENV['MONGO_URI'] ?? $_SERVER['MONGO_URI'] ?? getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        $dbName = $_ENV['MONGO_DB'] ?? $_SERVER['MONGO_DB'] ?? getenv('MONGO_DB') ?: 'explanse_db';

        if (class_exists('\MongoDB\Client') && extension_loaded('mongodb')) {
            try {
                $client = new \MongoDB\Client($uri);
                $collection = $client->selectCollection($dbName, $collectionName);
                self::$collections[$collectionName] = $collection;
                return $collection;
            } catch (\Throwable $t) {
                // Fallback to MongoCollection wire driver if official client fails
            }
        }

        $collection = new MongoCollection($collectionName);
        self::$collections[$collectionName] = $collection;
        return $collection;
    }

    /**
     * Create essential MongoDB indexes for optimal query execution performance.
     */
    public static function ensureIndexes(): array
    {
        $results = [];
        try {
            // Transactions indexes
            $txCol = self::getCollection('transactions');
            if (method_exists($txCol, 'createIndex')) {
                $results['transactions_user_date'] = $txCol->createIndex(['user_fk' => 1, 'date' => -1]);
                $results['transactions_user_cat'] = $txCol->createIndex(['user_fk' => 1, 'category_fk' => 1]);
                $results['transactions_user_acc'] = $txCol->createIndex(['user_fk' => 1, 'account_fk' => 1]);

                // Categories index
                $catCol = self::getCollection('categories');
                $results['categories_user_name'] = $catCol->createIndex(['user_fk' => 1, 'name' => 1]);

                // Accounts index
                $accCol = self::getCollection('accounts');
                $results['accounts_user'] = $accCol->createIndex(['user_fk' => 1]);

                // Users index (unique email)
                $usersCol = self::getCollection('users');
                $results['users_email'] = $usersCol->createIndex(['email' => 1], ['unique' => true]);
            }
        } catch (\Throwable $t) {
            $results['error'] = $t->getMessage();
        }
        return $results;
    }
}
