<?php
// Crawl all approved events from 臺南市政府道路路權申請系統
// Each event saved as data/{Year}/{Month}/{管制編號}.json
// Year/Month based on the start time of 起訖時間
// Usage: php crawl_all.php

$apiUrl = 'https://mtgis.tainan.gov.tw/api/RoadApplicTwo/ApprovedQuery/';
$dataDir = dirname(__DIR__) . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$pageSize = 500;
$start = 0;
$allRecords = [];
$draw = 1;

while (true) {
    $postData = http_build_query([
        'draw' => $draw,
        'start' => $start,
        'length' => $pageSize,
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
        break;
    }

    $json = json_decode($response, true);
    if (!$json || empty($json['data'])) {
        break;
    }

    $allRecords = array_merge($allRecords, $json['data']);
    $total = intval($json['recordsTotal']);
    echo "Fetched " . count($allRecords) . " / {$total}\n";

    if (count($allRecords) >= $total) {
        break;
    }

    $start += $pageSize;
    $draw++;
    sleep(1);
}

echo "Total records fetched: " . count($allRecords) . "\n";

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

$saved = 0;
$monthIndex = [];
foreach ($allRecords as $record) {
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

    file_put_contents($dir . '/' . $controlNo . '.json', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $entry = [
        '管制編號' => $controlNo,
        '地區' => $record['地區'],
        '集或遊' => $record['集或遊'],
        '起訖時間' => $record['起訖時間'],
        '集會場所' => $record['集會場所'],
    ];
    foreach ($months as $key) {
        $monthIndex[$key][] = $entry;
    }
    $saved++;
}

foreach ($monthIndex as $key => $entries) {
    $dir = $dataDir . '/' . $key;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    usort($entries, function ($a, $b) {
        return strcmp($b['管制編號'], $a['管制編號']);
    });
    $indexPath = $dir . '/index.json';
    file_put_contents($indexPath, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Index: {$key} - " . count($entries) . " events\n";
}

echo "Saved {$saved} event files.\n";
echo "Done.\n";
