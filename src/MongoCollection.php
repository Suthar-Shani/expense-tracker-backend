<?php

namespace App;

use App\Helpers\UuidHelper;
use Exception;

class MongoCollection
{
    private string $collectionName;

    public function __construct(string $name)
    {
        $this->collectionName = $name;
    }

    /**
     * Find documents in MongoDB database via direct wire protocol.
     */
    public function find(array $filter = [], array $options = []): array
    {
        $cmdArgs = [
            'find' => $this->collectionName,
            'filter' => $filter
        ];

        if (isset($options['sort']) && is_array($options['sort'])) {
            $cmdArgs['sort'] = $options['sort'];
        }
        if (isset($options['limit']) && is_numeric($options['limit'])) {
            $cmdArgs['limit'] = (int)$options['limit'];
        }
        if (isset($options['projection']) && is_array($options['projection'])) {
            $cmdArgs['projection'] = $options['projection'];
        }

        $res = MongoWireDriver::command('find', $cmdArgs);

        if (empty($res['ok']) && isset($res['errmsg'])) {
            throw new Exception("MongoDB Find Error: " . $res['errmsg']);
        }

        $batch = $res['cursor']['firstBatch'] ?? [];

        $result = [];
        foreach ($batch as $doc) {
            $item = (array)$doc;
            if (isset($item['_id']) && is_array($item['_id'])) {
                $item['_id'] = (string)($item['_id']['id'] ?? json_encode($item['_id']));
            }
            $item['id'] = (string)($item['_id'] ?? ($item['id'] ?? null));
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Find single document in MongoDB database.
     */
    public function findOne(array $filter = []): ?array
    {
        $results = $this->find($filter, ['limit' => 1]);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Insert one document into MongoDB database.
     */
    public function insertOne(array $document): array
    {
        if (!isset($document['_id']) && !isset($document['id'])) {
            $document['_id'] = UuidHelper::generate();
            $document['id'] = $document['_id'];
        } elseif (isset($document['_id']) && !isset($document['id'])) {
            $document['id'] = (string)$document['_id'];
        } elseif (isset($document['id']) && !isset($document['_id'])) {
            $document['_id'] = (string)$document['id'];
        }

        if (!isset($document['created_at'])) {
            $document['created_at'] = date('Y-m-d H:i:s');
        }

        $cmdArgs = [
            'insert' => $this->collectionName,
            'documents' => [$document]
        ];

        $res = MongoWireDriver::command('insert', $cmdArgs);

        if (empty($res['ok']) && isset($res['errmsg'])) {
            throw new Exception("MongoDB Insert Error: " . $res['errmsg']);
        }

        return $document;
    }

    /**
     * Update document in MongoDB database.
     */
    public function updateOne(array $filter, array $update): bool
    {
        $cmdArgs = [
            'update' => $this->collectionName,
            'updates' => [
                [
                    'q' => $filter,
                    'u' => $update,
                    'multi' => false
                ]
            ]
        ];

        $res = MongoWireDriver::command('update', $cmdArgs);

        if (empty($res['ok']) && isset($res['errmsg'])) {
            throw new Exception("MongoDB Update Error: " . $res['errmsg']);
        }

        return isset($res['n']) && $res['n'] > 0;
    }

    /**
     * Delete one document in MongoDB database.
     */
    public function deleteOne(array $filter): bool
    {
        $cmdArgs = [
            'delete' => $this->collectionName,
            'deletes' => [
                [
                    'q' => $filter,
                    'limit' => 1
                ]
            ]
        ];

        $res = MongoWireDriver::command('delete', $cmdArgs);

        if (empty($res['ok']) && isset($res['errmsg'])) {
            throw new Exception("MongoDB Delete Error: " . $res['errmsg']);
        }

        return isset($res['n']) && $res['n'] > 0;
    }

    /**
     * Delete all matching documents in MongoDB database.
     */
    public function deleteMany(array $filter = []): int
    {
        $cmdArgs = [
            'delete' => $this->collectionName,
            'deletes' => [
                [
                    'q' => $filter,
                    'limit' => 0
                ]
            ]
        ];

        $res = MongoWireDriver::command('delete', $cmdArgs);

        if (empty($res['ok']) && isset($res['errmsg'])) {
            throw new Exception("MongoDB DeleteMany Error: " . $res['errmsg']);
        }

        return (int)($res['n'] ?? 0);
    }

    /**
     * Create index on MongoDB collection.
     */
    public function createIndex(array $keys, array $options = []): array
    {
        $indexSpec = array_merge([
            'key' => $keys,
            'name' => implode('_', array_keys($keys)) . '_idx'
        ], $options);

        $cmdArgs = [
            'createIndexes' => $this->collectionName,
            'indexes' => [$indexSpec]
        ];

        return MongoWireDriver::command('createIndexes', $cmdArgs);
    }
}
