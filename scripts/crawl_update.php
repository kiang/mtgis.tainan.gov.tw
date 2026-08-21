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

function getCoveredMonths($timeRange) {
    $parts = explode('~', $timeRange);
    if (count($parts) !== 2) return [];
    if (!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', trim($parts[0]), $s)) return [];
    if (!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', trim($parts[1]), $e)) return [];
    $months = [];
    $cur = new DateTime("{$s[1]}-{$s[2]}-01");
    $end = new DateTime("{$e[1]}-{$e[2]}-01");
    while ($cur <= $end) {
        $months[] = $cur->format('Y') . '/' . $cur->format('m');
        $cur->modify('+1 month');
    }
    return $months;
}

$newCount = 0;
$updatedCount = 0;
$touchedMonths = [];

foreach ($json['data'] as $record) {
    $controlNo = $record['管制編號'];
    $months = getCoveredMonths($record['起訖時間']);
    if (empty($months)) {
        echo "Warning: Cannot parse date for {$controlNo}: {$record['起訖時間']}\n";
        continue;
    }

    $primaryMonth = $months[0];
    $dir = $dataDir . '/' . $primaryMonth;
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
    foreach ($months as $key) {
        $touchedMonths[$key] = true;
    }
}

foreach (array_keys($touchedMonths) as $key) {
    $monthDir = $dataDir . '/' . $key;
    if (!is_dir($monthDir)) {
        mkdir($monthDir, 0755, true);
    }
    $entries = [];

    // Collect events whose files live in this month
    foreach (glob($monthDir . '/*.json') as $file) {
        if (basename($file) === 'index.json') continue;
        $event = json_decode(file_get_contents($file), true);
        $entries[$event['管制編號']] = [
            '管制編號' => $event['管制編號'],
            '地區' => $event['地區'],
            '集或遊' => $event['集或遊'],
            '起訖時間' => $event['起訖時間'],
            '集會場所' => $event['集會場所'],
        ];
    }

    // Also scan other months for events that span into this month
    list($year, $month) = explode('/', $key);
    $yearDir = $dataDir . '/' . $year;
    foreach (glob($yearDir . '/*/') as $otherMonthDir) {
        if (rtrim($otherMonthDir, '/') === $monthDir) continue;
        foreach (glob($otherMonthDir . '/*.json') as $file) {
            if (basename($file) === 'index.json') continue;
            $event = json_decode(file_get_contents($file), true);
            if (isset($entries[$event['管制編號']])) continue;
            $coveredMonths = getCoveredMonths($event['起訖時間']);
            if (in_array($key, $coveredMonths)) {
                $entries[$event['管制編號']] = [
                    '管制編號' => $event['管制編號'],
                    '地區' => $event['地區'],
                    '集或遊' => $event['集或遊'],
                    '起訖時間' => $event['起訖時間'],
                    '集會場所' => $event['集會場所'],
                ];
            }
        }
    }

    $entries = array_values($entries);
    usort($entries, function ($a, $b) {
        return strcmp($b['管制編號'], $a['管制編號']);
    });
    file_put_contents($monthDir . '/index.json', json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Index: {$key} - " . count($entries) . " events\n";
}

echo "New: {$newCount}, Updated: {$updatedCount}\n";
echo "Done.\n";
