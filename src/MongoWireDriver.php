<?php

namespace App;

use Exception;

class SimpleBson
{
    public static function encode(array $doc): string
    {
        $body = '';
        foreach ($doc as $key => $val) {
            $keyStr = (string)$key . "\x00";

            if (is_string($val)) {
                $strBytes = $val . "\x00";
                $body .= "\x02" . $keyStr . pack('V', strlen($strBytes)) . $strBytes;
            } elseif (is_int($val)) {
                $body .= "\x10" . $keyStr . pack('V', $val);
            } elseif (is_float($val)) {
                $body .= "\x01" . $keyStr . pack('e', $val);
            } elseif (is_bool($val)) {
                $body .= "\x08" . $keyStr . ($val ? "\x01" : "\x00");
            } elseif (is_null($val)) {
                $body .= "\x0A" . $keyStr;
            } elseif (is_array($val)) {
                if (isset($val['$binary']) && is_string($val['$binary'])) {
                    $binData = base64_decode($val['$binary']);
                    $body .= "\x05" . $keyStr . pack('V', strlen($binData)) . "\x00" . $binData;
                } else {
                    $isAssoc = empty($val) || array_keys($val) !== range(0, count($val) - 1);
                    $typeByte = $isAssoc ? "\x03" : "\x04";
                    $subBson = self::encode($val);
                    $body .= $typeByte . $keyStr . $subBson;
                }
            }
        }
        $len = strlen($body) + 5;
        return pack('V', $len) . $body . "\x00";
    }

    public static function decode(string $bson, int &$offset = 0): array
    {
        if (strlen($bson) < $offset + 5) {
            return [];
        }
        $docLen = unpack('Vlen', substr($bson, $offset, 4))['len'];
        $start = $offset + 4;
        $end = $offset + $docLen - 1;
        $offset += $docLen;

        $res = [];
        $ptr = $start;
        while ($ptr < $end) {
            $type = ord($bson[$ptr]);
            $ptr++;
            $nullPos = strpos($bson, "\x00", $ptr);
            if ($nullPos === false || $nullPos >= $end) {
                break;
            }
            $key = substr($bson, $ptr, $nullPos - $ptr);
            $ptr = $nullPos + 1;

            if ($type === 0x02) { // String
                $sLen = unpack('Vlen', substr($bson, $ptr, 4))['len'];
                $ptr += 4;
                $val = substr($bson, $ptr, $sLen - 1);
                $ptr += $sLen;
                $res[$key] = $val;
            } elseif ($type === 0x05) { // Binary
                $bLen = unpack('Vlen', substr($bson, $ptr, 4))['len'];
                $ptr += 5; // 4 bytes len + 1 byte subtype
                $val = substr($bson, $ptr, $bLen);
                $ptr += $bLen;
                $res[$key] = $val;
            } elseif ($type === 0x10) { // Int32
                $val = unpack('Vval', substr($bson, $ptr, 4))['val'];
                $ptr += 4;
                $res[$key] = $val;
            } elseif ($type === 0x12) { // Int64
                $val = unpack('Pval', substr($bson, $ptr, 8))['val'];
                $ptr += 8;
                $res[$key] = $val;
            } elseif ($type === 0x01) { // Double
                $val = unpack('eval', substr($bson, $ptr, 8))['val'];
                $ptr += 8;
                $res[$key] = $val;
            } elseif ($type === 0x08) { // Bool
                $val = (ord($bson[$ptr]) !== 0);
                $ptr += 1;
                $res[$key] = $val;
            } elseif ($type === 0x0A) { // Null
                $res[$key] = null;
            } elseif ($type === 0x03 || $type === 0x04) { // Embedded Doc / Array
                $sub = self::decode($bson, $ptr);
                $res[$key] = $sub;
            } else {
                break;
            }
        }
        return $res;
    }
}

class MongoWireDriver
{
    private static $socket = null;
    private static int $reqId = 1;
    private static ?array $parsedUri = null;
    private static array $logs = [];

    public static function log(string $message): void
    {
        $time = date('H:i:s');
        self::$logs[] = "[{$time}] {$message}";
    }

    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function parseUri(string $uri): array
    {
        $pattern = '/^(mongodb(?:\+srv)?):\/\/(?:([^:]+):([^@]+)@)?([^?\/]+)(?:\/([^?]*))?(?:\?(.*))?$/i';
        if (preg_match($pattern, $uri, $m)) {
            return [
                'scheme' => strtolower($m[1]),
                'user' => isset($m[2]) && $m[2] !== '' ? urldecode($m[2]) : null,
                'pass' => isset($m[3]) && $m[3] !== '' ? urldecode($m[3]) : null,
                'hosts' => $m[4],
                'database' => !empty($m[5]) ? $m[5] : null,
                'options' => !empty($m[6]) ? $m[6] : ''
            ];
        }
        return [
            'scheme' => 'mongodb',
            'user' => null,
            'pass' => null,
            'hosts' => '127.0.0.1:27017',
            'database' => null,
            'options' => ''
        ];
    }

    private static function rawCommand($fp, string $cmdName, array $cmdArgs, string $db = 'admin'): array
    {
        $cmd = array_merge([$cmdName => $cmdArgs[$cmdName] ?? 1], $cmdArgs);
        $cmd['$db'] = $db;

        $bson = SimpleBson::encode($cmd);

        // OP_MSG opcode = 2013
        $opMsgBody = "\x00\x00\x00\x00" . "\x00" . $bson;
        $totalLen = 16 + strlen($opMsgBody);

        $header = pack('V4', $totalLen, self::$reqId++, 0, 2013);
        @fwrite($fp, $header . $opMsgBody);

        $resHeader = @fread($fp, 16);
        if (strlen($resHeader) < 16) {
            throw new Exception("MongoDB server response header incomplete");
        }

        $headerData = unpack('Vlen/VreqId/VresponseTo/VopCode', $resHeader);
        $remaining = $headerData['len'] - 16;
        $resBody = '';
        while (strlen($resBody) < $remaining) {
            $chunk = @fread($fp, $remaining - strlen($resBody));
            if (!$chunk) break;
            $resBody .= $chunk;
        }

        $offset = 5;
        return SimpleBson::decode($resBody, $offset);
    }

    private static function getConnection()
    {
        if (self::$socket === null) {
            $uri = $_ENV['MONGO_URI'] ?? $_SERVER['MONGO_URI'] ?? getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
            self::$parsedUri = self::parseUri($uri);
            $parsed = self::$parsedUri;

            self::log("Connecting to MongoDB URI: " . preg_replace('/:[^@]+@/', ':****@', $uri));

            $isSsl = ($parsed['scheme'] === 'mongodb+srv' 
                || str_contains(strtolower($parsed['options']), 'ssl=true') 
                || str_contains(strtolower($parsed['options']), 'tls=true') 
                || str_contains(strtolower($parsed['hosts']), '.mongodb.net'));

            $candidateHosts = [];
            $isAtlas = str_contains(strtolower($parsed['hosts']), '.mongodb.net');

            if ($parsed['scheme'] === 'mongodb+srv' || $isAtlas) {
                $srvName = '_mongodb._tcp.' . $parsed['hosts'];
                self::log("Looking up DNS SRV records for: {$srvName}");
                $records = @dns_get_record($srvName, DNS_SRV);
                if (!empty($records)) {
                    foreach ($records as $rec) {
                        if (isset($rec['target']) && isset($rec['port'])) {
                            $candidateHosts[] = ['host' => $rec['target'], 'port' => (int)$rec['port']];
                        }
                    }
                    self::log("Resolved " . count($candidateHosts) . " SRV cluster hosts.");
                }
                $clusterName = explode('.', $parsed['hosts'])[0] ?? 'cluster0';
                $domainPart = implode('.', array_slice(explode('.', $parsed['hosts']), 1));
                foreach (['00-00', '00-01', '00-02'] as $shard) {
                    $candidateHosts[] = [
                        'host' => "{$clusterName}-shard-{$shard}.{$domainPart}",
                        'port' => 27017
                    ];
                }
                $candidateHosts[] = ['host' => $parsed['hosts'], 'port' => 27017];
            } else {
                foreach (explode(',', $parsed['hosts']) as $h) {
                    $parts = explode(':', trim($h));
                    $host = trim($parts[0]);
                    if ($host === 'localhost') {
                        $host = '127.0.0.1';
                    }
                    $candidateHosts[] = [
                        'host' => $host,
                        'port' => isset($parts[1]) ? (int)$parts[1] : 27017
                    ];
                }
            }

            $fp = null;
            $connectedHost = '';
            $lastErr = '';

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            $connectTimeout = ($parsed['scheme'] === 'mongodb+srv' || $isAtlas) ? 3 : 1.5;

            foreach ($candidateHosts as $target) {
                $proto = $isSsl ? 'ssl://' : '';
                $remote = $proto . $target['host'] . ':' . $target['port'];

                self::log("Attempting socket connection to {$remote}...");
                $fp = @stream_socket_client($remote, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
                if ($fp) {
                    $connectedHost = $target['host'] . ':' . $target['port'];
                    self::log("Socket connection SUCCESSful to {$connectedHost}");
                    break;
                }
                $lastErr = "{$remote}: {$errstr} ({$errno})";
            }

            if (!$fp) {
                self::log("Socket connection FAILED: " . $lastErr);
                throw new Exception("Unable to connect to MongoDB cluster (" . ($lastErr ?: 'connection failed') . ")");
            }

            // MongoDB Replica Set Primary Node Discovery (only if replica set or Atlas SRV)
            $isReplicaSet = ($parsed['scheme'] === 'mongodb+srv'
                || str_contains(strtolower($parsed['options']), 'replicaset=')
                || $isAtlas);

            if ($isReplicaSet) {
                try {
                    self::log("Running replica set topology check (hello/ismaster)...");
                    $helloRes = self::rawCommand($fp, 'hello', ['hello' => 1], 'admin');
                    if (empty($helloRes['ok'])) {
                        $helloRes = self::rawCommand($fp, 'ismaster', ['ismaster' => 1], 'admin');
                    }
                    if (!empty($helloRes['primary']) && is_string($helloRes['primary'])) {
                        $primaryHostPort = $helloRes['primary'];
                        self::log("Discovered Primary Node: {$primaryHostPort}");
                        if ($primaryHostPort !== $connectedHost) {
                            $proto = $isSsl ? 'ssl://' : '';
                            $primaryRemote = $proto . $primaryHostPort;
                            self::log("Switching connection to Primary Node: {$primaryRemote}");
                            $primaryFp = @stream_socket_client($primaryRemote, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
                            if ($primaryFp) {
                                @fclose($fp);
                                $fp = $primaryFp;
                                self::log("Connected to Primary Node SUCCESSfully.");
                            }
                        }
                    }
                } catch (\Throwable $t) {
                    self::log("Primary discovery note: " . $t->getMessage());
                }
            }

            self::$socket = $fp;

            if (!empty($parsed['user']) && !empty($parsed['pass'])) {
                self::log("Starting SASL authentication for user: {$parsed['user']}");
                self::authenticateWithFallback($fp, $parsed);
            }
        }
        return self::$socket;
    }

    private static function authenticateWithFallback($fp, array $parsed): void
    {
        $authDbs = [];
        if (!empty($parsed['options'])) {
            parse_str($parsed['options'], $optArr);
            if (!empty($optArr['authSource'])) {
                $authDbs[] = $optArr['authSource'];
            }
        }
        $authDbs[] = 'admin';
        if (!empty($parsed['database'])) {
            $authDbs[] = $parsed['database'];
        }
        if (isset($_ENV['MONGO_DB'])) {
            $authDbs[] = $_ENV['MONGO_DB'];
        }
        $authDbs = array_values(array_unique($authDbs));

        $lastException = null;
        foreach ($authDbs as $db) {
            try {
                self::log("Authenticating against database '{$db}'...");
                self::authenticate($fp, $parsed['user'], $parsed['pass'], $db);
                self::log("Authentication SUCCESSful on database '{$db}'!");
                return;
            } catch (Exception $e) {
                self::log("Authentication failed on database '{$db}': " . $e->getMessage());
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
    }

    private static function authenticate($fp, string $user, string $pass, string $authDb = 'admin'): void
    {
        $mechanisms = ['SCRAM-SHA-1', 'SCRAM-SHA-256'];
        $lastException = null;

        foreach ($mechanisms as $mechanism) {
            try {
                self::log("Attempting authentication with mechanism: {$mechanism}");
                self::authenticateWithMechanism($fp, $user, $pass, $authDb, $mechanism);
                self::log("Authentication SUCCESSful with mechanism: {$mechanism}!");
                return;
            } catch (Exception $e) {
                self::log("Authentication with {$mechanism} failed: " . $e->getMessage());
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
    }

    private static function authenticateWithMechanism($fp, string $user, string $pass, string $authDb, string $mechanism): void
    {
        $cNonce = bin2hex(random_bytes(16));
        $clientFirstBare = "n=" . $user . ",r=" . $cNonce;
        $clientFirst = "n,," . $clientFirstBare;

        $res1 = self::rawCommand($fp, 'saslStart', [
            'saslStart'   => 1,
            'mechanism'   => $mechanism,
            'payload'     => ['$binary' => base64_encode($clientFirst)],
            'options'     => ['skipEmptyExchange' => true],
        ], $authDb);

        self::log("saslStart ({$mechanism}, {$authDb}) response: " . json_encode($res1));
        if (empty($res1['ok'])) {
            $errMsg = $res1['errmsg'] ?? 'SASL authentication failed';
            throw new Exception("MongoDB Auth Error: " . $errMsg);
        }

        // Decode server-first-message
        $conversationId = (int)($res1['conversationId'] ?? 1);
        $rawPayload = is_array($res1['payload'])
            ? ($res1['payload']['$binary'] ?? '')
            : ($res1['payload'] ?? '');

        $serverFirstMsg = (base64_decode($rawPayload, true) !== false && str_contains(base64_decode($rawPayload, true), 'r='))
            ? base64_decode($rawPayload, true)
            : $rawPayload;

        self::log("serverFirstMsg: " . $serverFirstMsg);

        $sData = [];
        foreach (explode(',', $serverFirstMsg) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) $sData[$kv[0]] = $kv[1];
        }

        $fullNonce  = $sData['r'] ?? '';
        $salt       = base64_decode($sData['s'] ?? '');
        $iterations = (int)($sData['i'] ?? 10000);

        if (!str_starts_with($fullNonce, $cNonce)) {
            throw new Exception('MongoDB Auth Error: Server nonce does not start with client nonce');
        }

        $clientFinalWithoutProof = 'c=biws,r=' . $fullNonce;
        $authMessage = $clientFirstBare . ',' . $serverFirstMsg . ',' . $clientFinalWithoutProof;

        if ($mechanism === 'SCRAM-SHA-1') {
            $mongoPassword  = md5("{$user}:mongo:{$pass}");
            $saltedPassword = hash_pbkdf2('sha1', $mongoPassword, $salt, $iterations, 20, true);
            $clientKey      = hash_hmac('sha1', 'Client Key', $saltedPassword, true);
            $storedKey      = hash('sha1', $clientKey, true);
            $clientSignature = hash_hmac('sha1', $authMessage, $storedKey, true);
        } else {
            $saltedPassword = hash_pbkdf2('sha256', $pass, $salt, $iterations, 32, true);
            $clientKey      = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
            $storedKey      = hash('sha256', $clientKey, true);
            $clientSignature = hash_hmac('sha256', $authMessage, $storedKey, true);
        }

        $clientProof    = $clientKey ^ $clientSignature;
        $clientFinalMsg = $clientFinalWithoutProof . ',p=' . base64_encode($clientProof);

        // saslContinue
        $res2 = self::rawCommand($fp, 'saslContinue', [
            'saslContinue'   => 1,
            'conversationId' => $conversationId,
            'payload'        => ['$binary' => base64_encode($clientFinalMsg)],
        ], $authDb);

        self::log("saslContinue response: " . json_encode($res2));
        if (empty($res2['ok'])) {
            $errMsg = $res2['errmsg'] ?? 'SASL continue failed';
            throw new Exception("MongoDB Auth Error: " . $errMsg);
        }

        // If server requires final exchange
        if (isset($res2['done']) && $res2['done'] === false) {
            $res3 = self::rawCommand($fp, 'saslContinue', [
                'saslContinue'   => 1,
                'conversationId' => $conversationId,
                'payload'        => ['$binary' => base64_encode('')],
            ], $authDb);
            self::log("saslContinue (final) response: " . json_encode($res3));
            if (empty($res3['ok'])) {
                throw new Exception("MongoDB Auth Error: " . ($res3['errmsg'] ?? 'Final SASL exchange failed'));
            }
        }
    }

    public static function command(string $cmdName, array $cmdArgs, ?string $dbName = null): array
    {
        $db = $dbName ?? ($_ENV['MONGO_DB'] ?? $_SERVER['MONGO_DB'] ?? getenv('MONGO_DB') ?: 'explanse_db');
        $fp = self::getConnection();
        return self::rawCommand($fp, $cmdName, $cmdArgs, $db);
    }
}
