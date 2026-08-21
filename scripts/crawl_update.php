<?php
// Crawl the first page (50 newest events) and merge new ones into existing data
// Usage: php crawl_update.php

$apiUrl = 'https://mtgis.tainan.gov.tw/api/RoadApplicTwo/ApprovedQuery/';
$dataDir = dirname(__DIR__) . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$postData = http_build_query([
    'draw' => 1,
    'start' => 0,
    'length' => 50,
    'columns[0][data]' => '流水碼',
    'columns[1][data]' => '管制編號',
    'columns[2][data]' => '地區',
    'columns[3][data]' => '',
    'columns[4][data]' => '集或遊',
    'columns[5][data]' => '核准路段',
    'search[value]' => '',
    'search[regex]' => 'false',
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || $response === false) {
    echo "Error: HTTP {$httpCode}\n";
    exit(1);
}

$json = json_decode($response, true);
if (!$json || empty($json['data'])) {
    echo "No data returned.\n";
    exit(1);
}

echo "Fetched " . count($json['data']) . " records (total on server: {$json['recordsTotal']})\n";

$newCount = 0;
$updatedCount = 0;
$updatedYears = [];

foreach ($json['data'] as $record) {
    $controlNo = $record['管制編號'];
    $rocYear = intval(substr($controlNo, 0, 3));
    $adYear = $rocYear + 1911;

    $yearDir = $dataDir . '/' . $adYear;
    if (!is_dir($yearDir)) {
        mkdir($yearDir, 0755, true);
    }

    $filePath = $yearDir . '/events.json';
    $existing = [];
    if (file_exists($filePath)) {
        $existing = json_decode(file_get_contents($filePath), true) ?: [];
    }

    $existingIndex = [];
    foreach ($existing as $i => $e) {
        $existingIndex[$e['管制編號']] = $i;
    }

    if (isset($existingIndex[$controlNo])) {
        $existing[$existingIndex[$controlNo]] = $record;
        $updatedCount++;
    } else {
        $existing[] = $record;
        $newCount++;
    }

    usort($existing, function ($a, $b) {
        return strcmp($b['管制編號'], $a['管制編號']);
    });

    file_put_contents($filePath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $updatedYears[$adYear] = true;
}

foreach (array_keys($updatedYears) as $year) {
    $filePath = $dataDir . '/' . $year . '/events.json';
    $records = json_decode(file_get_contents($filePath), true);
    echo "Year {$year}: " . count($records) . " records\n";
}

echo "New: {$newCount}, Updated: {$updatedCount}\n";
echo "Done.\n";
