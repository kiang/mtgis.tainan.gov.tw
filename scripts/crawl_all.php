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

$saved = 0;
foreach ($allRecords as $record) {
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

    file_put_contents($dir . '/' . $controlNo . '.json', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $saved++;
}

echo "Saved {$saved} event files.\n";
echo "Done.\n";
