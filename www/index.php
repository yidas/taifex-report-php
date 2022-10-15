<?php

date_default_timezone_set('Asia/Taipei');

require __DIR__ . '/../vendor/autoload.php';

define("CACHE_MODE", true);
define("DATE_FORMAT", "Y/m/d");
define("CACHE_FILEPATH", __DIR__ . '/../cache/data.php');
define("CACHE_PROTECT_SCRIPT", '<?php die("Access Denied");?>');

// Controller
$data = processData();
// print_r($data);exit;

// Shortcut
$ref = [];
$ref['tx'] = & $data['current']['future']['tx']['oi'];
$ref['mtx'] = & $data['current']['future']['mtx']['oi'];
$ref['txo'] = & $data['current']['option']['txo']['options']['oi'];
$ref['call'] = & $data['current']['option']['txo']['call']['oi'];
$ref['put'] = & $data['current']['option']['txo']['put']['oi'];
$diff = [];
$diff['tx'] = & $data['diff']['future']['tx']['oi'];
$diff['mtx'] = & $data['diff']['future']['mtx']['oi'];
$diff['txo'] = & $data['diff']['option']['txo']['options']['oi'];
$diff['call'] = & $data['diff']['option']['txo']['call']['oi'];
$diff['put'] = & $data['diff']['option']['txo']['put']['oi'];

/**
 * Controller
 *
 * @return array Data
 */
function processData()
{
    // Read cache
    $cacheString = @file_get_contents(CACHE_FILEPATH); 
    $cacheData = json_decode(str_replace(CACHE_PROTECT_SCRIPT, "", $cacheString), true);
    // print_r($cacheData);exit;

    // The date of the last available data
    $currentDate = isset($cacheData['current']['date']) ? $cacheData['current']['date'] : '0000/01/01';
    $fileUpdatedAt = isset($cacheData['updatedAt']) ? $cacheData['updatedAt'] : 0;
    // TAIFEX's renew time is 15:00~15:30 GMT+8
    $renewStartTime = strtotime(date(DATE_FORMAT . " 15:00:00"));
    $renewEndTime = strtotime(date(DATE_FORMAT . " 15:30:00"));
    $currentTime = time();
    $inRenewPeriodNow = ($renewStartTime - $currentTime < 0 && $renewEndTime - $currentTime > 0) ? true : false;
    $beforeRenewPeriodNow = ($renewStartTime - $currentTime > 0) ? true : false;
    $fileUpdatedDiffDays = ceil(($renewEndTime - $fileUpdatedAt) / (60*60*24));
    // echo "inRenew:";var_dump($inRenewPeriodNow);echo " | beforeRenew:";var_dump($beforeRenewPeriodNow);echo " | DiffDay: {$fileUpdatedDiffDays}";exit;

    // Cache conditions  
    if (CACHE_MODE && ($currentDate == date(DATE_FORMAT) || (!$inRenewPeriodNow && $fileUpdatedDiffDays <= 1 && ($beforeRenewPeriodNow || $fileUpdatedDiffDays <= 0)))) {
        // Read cache
        return $cacheData;

    } else {

        // Init parser
        $parser = \yidas\twStockCrawler\TaifexCrawler::config();

        // TAIFEX's renew time is 15:00 GMT+8
        $crawlDiffDays = (date("H") >= 15) ? 0 : 1;
        // $crawlDiffDays +=3; // Test for current date
        
        /**
         * Crawling process
         */
        $data = [];
        // Holiday retry
        $dataRangeDays = 30;
        $response = [];
        while ($crawlDiffDays <= $dataRangeDays) { 
            $response = $parser::getFutureContracts(date(DATE_FORMAT, strtotime("-{$crawlDiffDays} days")));
            if ($response)
                break;
            $crawlDiffDays++;
        }
        // Data check
        if (!$response) {
            return $cacheData['data'];
        }

        $data['current']['date'] = $response['date'];
        $data['current']['future'] = $response;
        $data['current']['option'] = $parser::getOptions(date(DATE_FORMAT, strtotime("-{$crawlDiffDays} days")));
        // print_r($data);exit;

        // Diff data
        $crawlDiffDays++;
        $response = [];
        while ($crawlDiffDays <= ($dataRangeDays + 1)) { 
            $response = $parser::getFutureContracts(date(DATE_FORMAT, strtotime("-{$crawlDiffDays} days")));
            if ($response)
                break;
            $crawlDiffDays++;
        }
        // Data check
        if (!$response) {
            die("No data is available for {$dataRangeDays} days.");
        }

        $data['diff']['date'] = $response['date'];
        $data['diff']['future'] = $response;
        $data['diff']['option'] = $parser::getOptions(date(DATE_FORMAT, strtotime("-{$crawlDiffDays} days")));

        // print_r($data);exit;

        /**
         * Others accumulation
         */
        calculateOthers($data['current']['future']['tx']['oi']);
        calculateOthers($data['current']['future']['mtx']['oi']);
        calculateOthers($data['current']['option']['txo']['call']['oi']);
        calculateOthers($data['current']['option']['txo']['put']['oi']);
        // Diff
        calculateOthers($data['diff']['future']['tx']['oi']);
        calculateOthers($data['diff']['future']['mtx']['oi']);
        calculateOthers($data['diff']['option']['txo']['call']['oi']);
        calculateOthers($data['diff']['option']['txo']['put']['oi']);
        calculateDiff($data['diff']['future']['tx']['oi'], $data['current']['future']['tx']['oi']);
        calculateDiff($data['diff']['future']['mtx']['oi'], $data['current']['future']['mtx']['oi']);
        calculateDiff($data['diff']['option']['txo']['call']['oi'], $data['current']['option']['txo']['call']['oi']);
        calculateDiff($data['diff']['option']['txo']['put']['oi'], $data['current']['option']['txo']['put']['oi']);
        // Option calculation for TXO (Should after above processes done)
        calculateOptions($data['current']['option']['txo']);
        calculateOptions($data['diff']['option']['txo']); 
        // Option price calculation
        calculateOptionPrice($data['current']['option']['txo']);
        calculateOptionPrice($data['diff']['option']['txo']);

        // print_r($data);exit;

        // Write cache
        $data['updatedAt'] = time();
        $cacheString = CACHE_PROTECT_SCRIPT . json_encode($data);
        $result = @file_put_contents(CACHE_FILEPATH, $cacheString);  
        // var_dump($result);exit;

        return $data;
    }
}

/**
 * calculate Others by row reference
 * 
 * @param array $row array from ContractCode
 */
function calculateOptions(& $row)
{
    $roles = ['fini', 'dealers', 'others'];
    foreach ($roles as $key => $role) {
        $row['options']['oi']['long']['volume'][$role] = $row['call']['oi']['long']['volume'][$role] + $row['put']['oi']['short']['volume'][$role];
        $row['options']['oi']['long']['value'][$role] = $row['call']['oi']['long']['value'][$role] + $row['put']['oi']['short']['value'][$role];
        $row['options']['oi']['short']['volume'][$role] = $row['call']['oi']['short']['volume'][$role] + $row['put']['oi']['long']['volume'][$role];
        $row['options']['oi']['short']['value'][$role] = $row['call']['oi']['short']['value'][$role] + $row['put']['oi']['long']['value'][$role];
        $row['options']['oi']['net']['volume'][$role] = $row['options']['oi']['long']['volume'][$role] - $row['options']['oi']['short']['volume'][$role];
        $row['options']['oi']['net']['value'][$role] = $row['options']['oi']['long']['value'][$role] - $row['options']['oi']['short']['value'][$role];
    }
}

/**
 * calculate Others by row reference
 */
function calculateOthers(& $row)
{

    $row['long']['volume']['others'] = ($row['short']['volume']['dealers'] > $row['long']['volume']['fini'])
        ? $row['short']['volume']['dealers'] - $row['long']['volume']['fini']
        : $row['short']['volume']['fini'] - $row['long']['volume']['dealers'];
    $row['long']['value']['others'] = ($row['short']['value']['dealers'] > $row['long']['value']['fini'])
        ? $row['short']['value']['dealers'] - $row['long']['value']['fini']
        : $row['short']['value']['fini'] - $row['long']['value']['dealers'];

    $row['short']['volume']['others'] = ($row['long']['volume']['dealers'] > $row['short']['volume']['fini'])
        ? $row['long']['volume']['dealers'] - $row['short']['volume']['fini']
        : $row['long']['volume']['fini'] - $row['short']['volume']['dealers'];
    $row['short']['value']['others'] = ($row['long']['value']['dealers'] > $row['short']['value']['fini'])
        ? $row['long']['value']['dealers'] - $row['short']['value']['fini']
        : $row['long']['value']['fini'] - $row['short']['value']['dealers'];
    $row['net']['volume']['others'] = 0 - $row['net']['volume']['dealers'] - $row['net']['volume']['fini'];
    $row['net']['value']['others'] = 0 - $row['net']['value']['dealers'] - $row['net']['value']['fini'];
}

/**
 * calculate Others by row reference
 */
function calculateDiff(& $diffRow, $row)
{
    $roles = ['fini', 'dealers', 'others'];
    foreach ($roles as $key => $role) {
        $diffRow['long']['volume'][$role] = $row['long']['volume'][$role] - $diffRow['long']['volume'][$role];
        $diffRow['long']['value'][$role] = $row['long']['value'][$role] - $diffRow['long']['value'][$role];
        $diffRow['short']['volume'][$role] = $row['short']['volume'][$role] - $diffRow['short']['volume'][$role];
        $diffRow['short']['value'][$role] = $row['short']['value'][$role] - $diffRow['short']['value'][$role];
        $diffRow['net']['volume'][$role] = $row['net']['volume'][$role] - $diffRow['net']['volume'][$role];
        $diffRow['net']['value'][$role] = $row['net']['value'][$role] - $diffRow['net']['value'][$role];
    }
}

/**
 * calculate options price
 */
function calculateOptionPrice(& $row)
{
    $types = ['call', 'put', 'options'];
    $cols = ['long', 'short', 'net'];
    $roles = ['fini', 'dealers', 'others'];
    foreach ($types as $key => $type) {
        foreach ($cols as $key => $col) {
            foreach ($roles as $key => $role) {
                // Value unit: 1000; Price unit: 50
                $row[$type]['oi'][$col]['price'][$role] = ($row[$type]['oi'][$col]['volume'][$role]) ? abs(round($row[$type]['oi'][$col]['value'][$role] / $row[$type]['oi'][$col]['volume'][$role] * 1000 / 50)) : 0;
            }
        }
    }
}

/**
 * Render diff value with HTML
 */
function renderDiff($value, $title=false)
{
    $type = ($value < 0) ? 'over-selling' : 'over-buying';
    $titleHtml = ($title) ? "title=\"Diff Price: {$title}\"" : "";
    return "<span {$titleHtml} class=\"{$type}\">(" . number_format($value) . ")</span>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TAIFEX Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
  <style>
    html {font-size: 13px;}
    body {
      color: rgb(209, 205, 199);
      background-color: rgb(24, 26, 27);
      -webkit-tap-highlight-color: transparent;
      padding: 10px 0;
    }
    div.table-container {overflow-x: auto;}
    .table {
      --darkreader-bg--bs-table-bg: rgba(0, 0, 0, 0);
      --darkreader-bg--bs-table-accent-bg: rgba(0, 0, 0, 0);
      --darkreader-text--bs-table-striped-color: #d1cdc7;
      --darkreader-bg--bs-table-striped-bg: rgba(0, 0, 0, 0.05);
      --darkreader-text--bs-table-active-color: #d1cdc7;
      --darkreader-bg--bs-table-active-bg: rgba(0, 0, 0, 0.1);
      --darkreader-text--bs-table-hover-color: #d1cdc7;
      --darkreader-bg--bs-table-hover-bg: rgba(0, 0, 0, 0.07);
      color: rgb(209, 205, 199);
      border-color: rgb(56, 61, 63);
    }
    .table th {
      text-align: center;   
    }
    .table td {
      text-align: right;   
    }
    .table tr.striped {
        background-color: rgb(29, 31, 32);
    }
    span.volume {
      color: #AAAAFF;
    }
    span.over-buying {
      color: #FF0000;
    }
    span.over-selling {
      color: #00CC00;
    }
    a {color: #99c1d9; text-decoration: none;}
    a:hover {color: #477ea0; text-decoration: underline;}
  </style>
</head>
<body>
  <div class="container"><p class="text-center"><b>Date: <?=$data['current']['date']?></b></p></div>
  <div class="container table-container">
  <table class="table">
    <thead>
      <tr>
        <th scope="col" colspan="2" rowspan="2"></th>
        <th scope="col" colspan="2">Long</th>
        <th scope="col" colspan="2">Short</th>
        <th scope="col" colspan="2">Net</th>
      </tr>
      <tr>
        <th scope="col">Volume<br>(Difference)</th>
        <th scope="col">Value<br>(Difference)</th>
        <th scope="col">Volume<br>(Difference)</th>
        <th scope="col">Value<br>(Difference)</th>
        <th scope="col">Volume<br>(Difference)</th>
        <th scope="col">Value<br>(Difference)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <th scope="row" rowspan="3">TX</th>
        <th scope="row">Dealers</th>
        <td><span class="volume"><?=number_format($ref['tx']['long']['volume']['dealers'])?></span><br><?=renderDiff($diff['tx']['long']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['long']['value']['dealers'])?></span><br><?=renderDiff($diff['tx']['long']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['short']['volume']['dealers'])?></span><br><?=renderDiff($diff['tx']['short']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['short']['value']['dealers'])?></span><br><?=renderDiff($diff['tx']['short']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['net']['volume']['dealers'])?></span><br><?=renderDiff($diff['tx']['net']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['net']['value']['dealers'])?></span><br><?=renderDiff($diff['tx']['net']['value']['dealers'])?></td>
      </tr>
      <tr>
        <th scope="row">FINI</th>
        <td><span class="volume"><?=number_format($ref['tx']['long']['volume']['fini'])?></span><br><?=renderDiff($diff['tx']['long']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['long']['value']['fini'])?></span><br><?=renderDiff($diff['tx']['long']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['short']['volume']['fini'])?></span><br><?=renderDiff($diff['tx']['short']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['short']['value']['fini'])?></span><br><?=renderDiff($diff['tx']['short']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['net']['volume']['fini'])?></span><br><?=renderDiff($diff['tx']['net']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['net']['value']['fini'])?></span><br><?=renderDiff($diff['tx']['net']['value']['fini'])?></td>
      </tr>
      <tr class="striped">
        <th scope="row">Others</th>
        <td><span class="volume"><?=number_format($ref['tx']['long']['volume']['others'])?></span><br><?=renderDiff($diff['tx']['long']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['long']['value']['others'])?></span><br><?=renderDiff($diff['tx']['long']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['short']['volume']['others'])?></span><br><?=renderDiff($diff['tx']['short']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['short']['value']['others'])?></span><br><?=renderDiff($diff['tx']['short']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['tx']['net']['volume']['others'])?></span><br><?=renderDiff($diff['tx']['net']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['tx']['net']['value']['others'])?></span><br><?=renderDiff($diff['tx']['net']['value']['others'])?></td>
      </tr>
      <tr>
        <th colspan="8"></th>
      </tr>
      <tr>
      <tr>
        <th scope="row" rowspan="3">MTX</th>
        <th scope="row">Dealers</th>
        <td><span class="volume"><?=number_format($ref['mtx']['long']['volume']['dealers'])?></span><br><?=renderDiff($diff['mtx']['long']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['long']['value']['dealers'])?></span><br><?=renderDiff($diff['mtx']['long']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['short']['volume']['dealers'])?></span><br><?=renderDiff($diff['mtx']['short']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['short']['value']['dealers'])?></span><br><?=renderDiff($diff['mtx']['short']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['net']['volume']['dealers'])?></span><br><?=renderDiff($diff['mtx']['net']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['net']['value']['dealers'])?></span><br><?=renderDiff($diff['mtx']['net']['value']['dealers'])?></td>
      </tr>
      <tr>
        <th scope="row">FINI</th>
        <td><span class="volume"><?=number_format($ref['mtx']['long']['volume']['fini'])?></span><br><?=renderDiff($diff['mtx']['long']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['long']['value']['fini'])?></span><br><?=renderDiff($diff['mtx']['long']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['short']['volume']['fini'])?></span><br><?=renderDiff($diff['mtx']['short']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['short']['value']['fini'])?></span><br><?=renderDiff($diff['mtx']['short']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['net']['volume']['fini'])?></span><br><?=renderDiff($diff['mtx']['net']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['net']['value']['fini'])?></span><br><?=renderDiff($diff['mtx']['net']['value']['fini'])?></td>
      </tr>
      <tr class="striped">
        <th scope="row">Others</th>
        <td><span class="volume"><?=number_format($ref['mtx']['long']['volume']['others'])?></span><br><?=renderDiff($diff['mtx']['long']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['long']['value']['others'])?></span><br><?=renderDiff($diff['mtx']['long']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['short']['volume']['others'])?></span><br><?=renderDiff($diff['mtx']['short']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['short']['value']['others'])?></span><br><?=renderDiff($diff['mtx']['short']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['mtx']['net']['volume']['others'])?></span><br><?=renderDiff($diff['mtx']['net']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['mtx']['net']['value']['others'])?></span><br><?=renderDiff($diff['mtx']['net']['value']['others'])?></td>
      </tr>
      <tr>
        <th colspan="8"></th>
      </tr>
      <tr>
        <th scope="row" rowspan="3">TXO</th>
        <th scope="row">Dealers</th>
        <td><span class="volume"><?=number_format($ref['txo']['long']['volume']['dealers'])?></span><br><?=renderDiff($diff['txo']['long']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['long']['value']['dealers'])?></span><br><?=renderDiff($diff['txo']['long']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['short']['volume']['dealers'])?></span><br><?=renderDiff($diff['txo']['short']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['short']['value']['dealers'])?></span><br><?=renderDiff($diff['txo']['short']['value']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['net']['volume']['dealers'])?></span><br><?=renderDiff($diff['txo']['net']['volume']['dealers'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['net']['value']['dealers'])?></span><br><?=renderDiff($diff['txo']['net']['value']['dealers'])?></td>
      </tr>
      <tr>
        <th scope="row">FINI</th>
        <td><span class="volume"><?=number_format($ref['txo']['long']['volume']['fini'])?></span><br><?=renderDiff($diff['txo']['long']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['long']['value']['fini'])?></span><br><?=renderDiff($diff['txo']['long']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['short']['volume']['fini'])?></span><br><?=renderDiff($diff['txo']['short']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['short']['value']['fini'])?></span><br><?=renderDiff($diff['txo']['short']['value']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['net']['volume']['fini'])?></span><br><?=renderDiff($diff['txo']['net']['volume']['fini'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['net']['value']['fini'])?></span><br><?=renderDiff($diff['txo']['net']['value']['fini'])?></td>
      </tr>
      <tr class="striped">
        <th scope="row">Others</th>
        <td><span class="volume"><?=number_format($ref['txo']['long']['volume']['others'])?></span><br><?=renderDiff($diff['txo']['long']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['long']['value']['others'])?></span><br><?=renderDiff($diff['txo']['long']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['short']['volume']['others'])?></span><br><?=renderDiff($diff['txo']['short']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['short']['value']['others'])?></span><br><?=renderDiff($diff['txo']['short']['value']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['txo']['net']['volume']['others'])?></span><br><?=renderDiff($diff['txo']['net']['volume']['others'])?></td>
        <td><span class="value"><?=number_format($ref['txo']['net']['value']['others'])?></span><br><?=renderDiff($diff['txo']['net']['value']['others'])?></td>
      </tr>
      <tr>
        <th colspan="8"></th>
      </tr>
      <tr>
        <th scope="row" rowspan="3">TXO<br>Call</th>
        <th scope="row">Dealers</th>
        <td><span class="volume"><?=number_format($ref['call']['long']['volume']['dealers'])?></span><br><?=renderDiff($diff['call']['long']['volume']['dealers'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['long']['price']['dealers']?>"><?=number_format($ref['call']['long']['value']['dealers'])?></span><br><?=renderDiff($diff['call']['long']['value']['dealers'], $diff['call']['long']['price']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['short']['volume']['dealers'])?></span><br><?=renderDiff($diff['call']['short']['volume']['dealers'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['short']['price']['dealers']?>"><?=number_format($ref['call']['short']['value']['dealers'])?></span><br><?=renderDiff($diff['call']['short']['value']['dealers'], $diff['call']['short']['price']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['net']['volume']['dealers'])?></span><br><?=renderDiff($diff['call']['net']['volume']['dealers'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['net']['price']['dealers']?>"><?=number_format($ref['call']['net']['value']['dealers'])?></span><br><?=renderDiff($diff['call']['net']['value']['dealers'], $diff['call']['net']['price']['dealers'])?></td>
      </tr>
      <tr>
        <th scope="row">FINI</th>
        <td><span class="volume"><?=number_format($ref['call']['long']['volume']['fini'])?></span><br><?=renderDiff($diff['call']['long']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['long']['price']['fini']?>"><?=number_format($ref['call']['long']['value']['fini'])?></span><br><?=renderDiff($diff['call']['long']['value']['fini'], $diff['call']['long']['price']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['short']['volume']['fini'])?></span><br><?=renderDiff($diff['call']['short']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['short']['price']['fini']?>"><?=number_format($ref['call']['short']['value']['fini'])?></span><br><?=renderDiff($diff['call']['short']['value']['fini'], $diff['call']['short']['price']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['net']['volume']['fini'])?></span><br><?=renderDiff($diff['call']['net']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['net']['price']['fini']?>"><?=number_format($ref['call']['net']['value']['fini'])?></span><br><?=renderDiff($diff['call']['net']['value']['fini'], $diff['call']['net']['price']['fini'])?></td>
      </tr>
      <tr class="striped">
        <th scope="row">Others</th>
        <td><span class="volume"><?=number_format($ref['call']['long']['volume']['others'])?></span><br><?=renderDiff($diff['call']['long']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['long']['price']['others']?>"><?=number_format($ref['call']['long']['value']['others'])?></span><br><?=renderDiff($diff['call']['long']['value']['others'], $diff['call']['long']['price']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['short']['volume']['others'])?></span><br><?=renderDiff($diff['call']['short']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['short']['price']['others']?>"><?=number_format($ref['call']['short']['value']['others'])?></span><br><?=renderDiff($diff['call']['short']['value']['others'], $diff['call']['short']['price']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['call']['net']['volume']['others'])?></span><br><?=renderDiff($diff['call']['net']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['call']['net']['price']['others']?>"><?=number_format($ref['call']['net']['value']['others'])?></span><br><?=renderDiff($diff['call']['net']['value']['others'], $diff['call']['net']['price']['others'])?></td>
      </tr>
      <tr>
        <th colspan="8"></th>
      </tr>
      <tr>
        <th scope="row" rowspan="3">TXO<br>Put</th>
        <th scope="row">Dealers</th>
        <td><span class="volume"><?=number_format($ref['put']['long']['volume']['dealers'])?></span><br><?=renderDiff($diff['put']['long']['volume']['dealers'])?></td>
        <td><span class="value" data-toggle="tooltip" data-placement="bottom" title="Price: <?=$ref['put']['long']['price']['dealers']?>"><?=number_format($ref['put']['long']['value']['dealers'])?></span><br><?=renderDiff($diff['put']['long']['value']['dealers'], $diff['put']['long']['price']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['short']['volume']['dealers'])?></span><br><?=renderDiff($diff['put']['short']['volume']['dealers'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['short']['price']['dealers']?>"><?=number_format($ref['put']['short']['value']['dealers'])?></span><br><?=renderDiff($diff['put']['short']['value']['dealers'], $diff['put']['short']['price']['dealers'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['net']['volume']['dealers'])?></span><br><?=renderDiff($diff['put']['net']['volume']['dealers'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['net']['price']['dealers']?>"><?=number_format($ref['put']['net']['value']['dealers'])?></span><br><?=renderDiff($diff['put']['net']['value']['dealers'], $diff['put']['net']['price']['dealers'])?></td>
      </tr>
      <tr>
        <th scope="row">FINI</th>
        <td><span class="volume"><?=number_format($ref['put']['long']['volume']['fini'])?></span><br><?=renderDiff($diff['put']['long']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['long']['price']['fini']?>"><?=number_format($ref['put']['long']['value']['fini'])?></span><br><?=renderDiff($diff['put']['long']['value']['fini'], $diff['put']['long']['price']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['short']['volume']['fini'])?></span><br><?=renderDiff($diff['put']['short']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['short']['price']['fini']?>"><?=number_format($ref['put']['short']['value']['fini'])?></span><br><?=renderDiff($diff['put']['short']['value']['fini'], $diff['put']['short']['price']['fini'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['net']['volume']['fini'])?></span><br><?=renderDiff($diff['put']['net']['volume']['fini'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['net']['price']['fini']?>"><?=number_format($ref['put']['net']['value']['fini'])?></span><br><?=renderDiff($diff['put']['net']['value']['fini'], $diff['put']['net']['price']['fini'])?></td>
      </tr>
      <tr class="striped">
        <th scope="row">Others</th>
        <td><span class="volume"><?=number_format($ref['put']['long']['volume']['others'])?></span><br><?=renderDiff($diff['put']['long']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['long']['price']['others']?>"><?=number_format($ref['put']['long']['value']['others'])?></span><br><?=renderDiff($diff['put']['long']['value']['others'], $diff['put']['long']['price']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['short']['volume']['others'])?></span><br><?=renderDiff($diff['put']['short']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['short']['price']['others']?>"><?=number_format($ref['put']['short']['value']['others'])?></span><br><?=renderDiff($diff['put']['short']['value']['others'], $diff['put']['short']['price']['others'])?></td>
        <td><span class="volume"><?=number_format($ref['put']['net']['volume']['others'])?></span><br><?=renderDiff($diff['put']['net']['volume']['others'])?></td>
        <td><span class="value" title="Price: <?=$ref['put']['net']['price']['others']?>"><?=number_format($ref['put']['net']['value']['others'])?></span><br><?=renderDiff($diff['put']['net']['value']['others'], $diff['put']['net']['price']['others'])?></td>
      </tr>
    </tbody>
  </table>
  </div>

  <div class="container">
    <p class="text-center">
      Difference: <?=$data['current']['date']?> data - <?=$data['diff']['date']?> data
      <br>
      Updated at: <?=date("Y/m/d H:i:s", $data['updatedAt'])?> (GMT+8)
    </p>
    <p class="text-center">Powered by <a href="https://www.yidas.com" target="_blank">YIDAS</a></p>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
  <script>
    $(function () {
      $('[data-toggle="tooltip"]').tooltip()
    })
  </script>
</body>
</html>