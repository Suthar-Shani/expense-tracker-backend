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
}
