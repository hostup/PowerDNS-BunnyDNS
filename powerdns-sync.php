<?php

require_once('vendor/autoload.php');

// Constants
const BUNNY_BASE_URL = 'https://api.bunny.net/dnszone/';

// PostgreSQL configuration
$host = '10.0.0.1';
$db = 'powerdns';
$user = 'sync';
$pass = 'pw';
$dsn = <<<DSN
pgsql:host=$host;dbname=$db
DSN;

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Initialize PDO and Guzzle Client
$pdo = new PDO($dsn, $user, $pass, $options);
$client = new \GuzzleHttp\Client([
    'headers' => [
        'AccessKey' => 'BUNNYDNSAPI',
        'accept' => 'application/json',
        'content-type' => 'application/json',
    ]
]);

function fetchAllZones($client) {
    $zones = [];
    $page = 1;
    $perPage = 1000; // set to maximum
    $hasMore = true;

    while ($hasMore) {
        $response = $client->request('GET', BUNNY_BASE_URL . "?page=$page&perPage=$perPage");
        $items = json_decode($response->getBody(), true)['Items'];
        
        $zones = array_merge($zones, $items);
        
        if (count($items) < $perPage) {
            $hasMore = false;
        } else {
            $page++;
        }
    }

    return $zones;
}

// Fetch all domains from Bunny
$dnsZones = fetchAllZones($client);
$existingDomains = [];
foreach ($dnsZones as $item) {
    $existingDomains[$item['Domain']] = $item['Id'];
}

function getPrimaryDomain($name) {
    $parts = explode('.', $name);
    $lastTwo = array_slice($parts, -2);
    return implode('.', $lastTwo);
}

$allNames = $pdo->query("SELECT DISTINCT name FROM records")->fetchAll(PDO::FETCH_COLUMN);
$domains = array_unique(array_map('getPrimaryDomain', $allNames));

foreach ($domains as $domain) {
    echo "Processing domain: {$domain}\n";

    $zoneId = $existingDomains[$domain] ?? null;
    if (!$zoneId) {
        try {
            $response = $client->request('POST', BUNNY_BASE_URL, ['json' => ['Domain' => $domain]]);
            $body = json_decode($response->getBody(), true);
            $zoneId = $body['Id'];
            $client->request('POST', BUNNY_BASE_URL . $zoneId, [
                'json' => [
                    'CustomNameserversEnabled' => true,
                    'Nameserver1' => 'ns1.hostup.se',
                    'Nameserver2' => 'ns2.hostup.se',
                    'SoaEmail' => 'registry@hostup.se'
                ]
            ]);
        } catch (\Exception $e) {
            echo "Failed to process domain: {$domain}. Error: " . $e->getMessage() . "\n";
            continue;
        }
    }

    $response = $client->request('GET', BUNNY_BASE_URL . $zoneId);
    $bunnyRecords = json_decode($response->getBody(), true)['Records'];
    $existingRecordsLookup = [];
    foreach ($bunnyRecords as $record) {
        $recordKey = "{$record['Type']}-{$record['Name']}";
        $existingRecordsLookup[$recordKey] = true;
    }

    $recordsQuery = $pdo->prepare("SELECT name, type, ttl, content, prio FROM records WHERE name LIKE :domain");
    $recordsQuery->execute([':domain' => '%' . $domain]);
    $pgRecords = $recordsQuery->fetchAll();

    foreach ($pgRecords as $pgRecord) {
        $recordType = match($pgRecord['type']) {
            'A' => 0, 'AAAA' => 1, 'CNAME' => 2, 'TXT' => 3, 'MX' => 4,
            'Redirect' => 5, 'Flatten' => 6, 'PullZone' => 7, 'SRV' => 8,
            'CAA' => 9, 'PTR' => 10, 'Script' => 11, default => null
        };

        if ($recordType === null) continue;

        $recordName = $pgRecord['name'] === $domain ? '' : str_replace('.' . $domain, '', $pgRecord['name']);

        $checkKey = "{$recordType}-{$recordName}";
        if (isset($existingRecordsLookup[$checkKey])) {
            echo "Record {$checkKey} already exists for domain: {$domain}. Skipping...\n";
            continue;
        }

        $recordBody = [
            'Name' => $recordName,
            'Type' => $recordType,
            'Ttl' => $pgRecord['ttl'],
            'Value' => $pgRecord['content'],
        ];

        if ($pgRecord['type'] === 'MX') {
            $recordBody['Priority'] = $pgRecord['prio'];
        }

        if ($pgRecord['type'] === 'SRV') {
            $recordBody['Priority'] = $pgRecord['prio'];
            
            $contentParts = explode(' ', $pgRecord['content']);
            
            // Ensure there are at least three parts: weight, port, and value
            if (count($contentParts) >= 3) {
                list($weight, $port, $value) = $contentParts;
                
                $recordBody['Weight'] = $weight;
                $recordBody['Port'] = $port;
                $recordBody['Value'] = $value;
            } else {
                // Handle the error, e.g., skip this record or log the inconsistency
                echo "Invalid SRV record content for domain: {$domain}. Skipping...\n";
                continue;
            }
        }        

        try {
            $client->request('PUT', "https://api.bunny.net/dnszone/{$zoneId}/records", [
                'json' => $recordBody
            ]);
        } catch (\Exception $e) {
            echo "Failed to synchronize record for domain: {$domain}. Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "Synchronization complete.\n";
?>
