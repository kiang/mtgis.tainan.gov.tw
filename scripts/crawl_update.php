<?php
// Crawl the first page (50 newest events) and save each as individual file
// Each event saved as data/{Year}/{Month}/{管制編號}.json
// Year/Month based on the start time of 起訖時間
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
$touchedMonths = [];

foreach ($json['data'] as $record) {
    $controlNo = $record['管制編號'];
    $startDate = explode('~', $record['起訖時間'])[0];
    if (preg_match('/^(\d{4})\/(\d{2})\//', $startDate, $m)) {
        $year = $m[1];
        $month = $m[2];
    } else {
        echo "Warning: Cannot parse date for {$controlNo}: {$record['起訖時間']}\n";
        continue;
    }

    $dir = $dataDir . '/' . $year . '/' . $month;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filePath = $dir . '/' . $controlNo . '.json';
    if (file_exists($filePath)) {
        $updatedCount++;
    } else {
        $newCount++;
    }

    file_put_contents($filePath, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $touchedMonths[$year . '/' . $month] = true;
}

foreach (array_keys($touchedMonths) as $key) {
    $monthDir = $dataDir . '/' . $key;
    $entries = [];
    foreach (glob($monthDir . '/*.json') as $file) {
        if (basename($file) === 'index.json') {
            continue;
        }
        $event = json_decode(file_get_contents($file), true);
        $entries[] = [
            '管制編號' => $event['管制編號'],
            '地區' => $event['地區'],
            '集或遊' => $event['集或遊'],
            '起訖時間' => $event['起訖時間'],
            '集會場所' => $event['集會場所'],
        ];
    }
    usort($entries, function ($a, $b) {
        return strcmp($b['管制編號'], $a['管制編號']);
    });
    file_put_contents($monthDir . '/index.json', json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Index: {$key} - " . count($entries) . " events\n";
}

echo "New: {$newCount}, Updated: {$updatedCount}\n";
echo "Done.\n";
