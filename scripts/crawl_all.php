<?php
// Crawl all approved events from 臺南市政府道路路權申請系統
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

$byYear = [];
foreach ($allRecords as $record) {
    $controlNo = $record['管制編號'];
    $rocYear = intval(substr($controlNo, 0, 3));
    $adYear = $rocYear + 1911;
    $byYear[$adYear][] = $record;
}

foreach ($byYear as $year => $records) {
    $yearDir = $dataDir . '/' . $year;
    if (!is_dir($yearDir)) {
        mkdir($yearDir, 0755, true);
    }
    $filePath = $yearDir . '/events.json';
    usort($records, function ($a, $b) {
        return strcmp($b['管制編號'], $a['管制編號']);
    });
    file_put_contents($filePath, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Saved " . count($records) . " records to {$filePath}\n";
}

echo "Done.\n";
