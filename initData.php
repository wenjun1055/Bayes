<?php
require 'sphinxapi.php';

$dbLocal = new PDO('mysql:host=localhost;dbname=words','root', 'root');
$dbData  = new PDO('mysql:host=localhost;dbname=words','root', 'root');
$sql     = 'set names utf8';
$dbLocal->exec($sql);
$dbData->exec($sql);

$sphinx  = new SphinxClient();
@$sphinx->SetServer('127.0.0.1', '9312');
$sphinx->Open();
$spamArray   = array();
$healthArray = array();

function getAllKey($sql, $dbData, $sphinx)
{
    $allKey = array();
    $result = $dbData->query($sql);
    while ($row = $result->fetch()) {
        //$pattern = '/[a-zA-Z0-9\x{4e00}-\x{9fa5}]+/u';
        //$pattern = '/[\x{4e00}-\x{9fa5}]+/u';
        //preg_match_all($pattern, $row['content'], $content);
        //$content = join('', $content[0]);
        $key     = $sphinx->buildKeywords($row['content'], 'mysql', false);
        $num     = count($key);
        if ($num) {
            for ($i = 0; $i < $num; $i++) {
                $key[$i] = $key[$i]['tokenized'];
            }  
        }
        $key    = array_unique($key);
        $allKey = array_merge($key, $allKey);
        $key     = null;  
    }
    return array_count_values($allKey) ;
}

function utfSubstr($str)
{
    for ($i = 0; $i < 15; $i++) {
        $tempStr = substr($str, 0, 1);
        if (ord($tempStr) > 127) {
            $i++;
            if ($i < 15) {
                $newStr[] = substr($str, 0, 3);
                $str      = substr($str, 3);
            }
        } else {
            $newStr[] = substr($str, 0 ,1);
            $str      = substr($str, 1);
        }
    }
    return join('', $newStr);
}

$dataArray     = array();
$shortDataArray = array();
$contentArray  = array();
$spamSql       = 'select distinct content as content from product_report_comments where status=1';
$result        = $dbData->query($spamSql);
while ($row = $result->fetch()) {
    $data = array(
        'short'   => utfSubstr($row['content']),
        'content' => $row['content']
    );
    array_push($dataArray, $data);
    array_push($shortDataArray, $data['short']);
}
echo count($shortDataArray);
$shortDataArray = array_flip(array_flip($shortDataArray));
echo "\r\n",count($shortDataArray),"\r\n";
foreach ($shortDataArray as $v) {
    foreach ($dataArray as $row) {
         if ($row['short'] == $v) {
             array_push($contentArray, $row['content']);
             break;
         }
    } 
}
echo count($contentArray),"\r\n";
foreach ($contentArray as $v) {
    $key   = $sphinx->buildKeywords($v, 'mysql', false);
    $num   = count($key);
    if ($num) {
        for ($i = 0; $i < $num; $i++) {
            $key[$i] = $key[$i]['tokenized'];
        }
    }
    $key       = array_unique($key); 
    $spamArray = array_merge($key, $spamArray);
    $key       = null;
    // exit();
}
echo count($spamArray),"\r\n";
$spamArray     = array_count_values($spamArray);
echo count($spamArray),"\r\n";

$healthSql     = 'select distinct content from product_report_comments where status=0 limit ' . count($spamArray);
$healthArray   = getAllKey($healthSql, $dbData, $sphinx);

//get the same key from $spamArray and $healthyKey
$sameKeySpam   = array_intersect_key($spamArray, $healthArray);
$sameKeyHealth = array_intersect_key($healthArray, $spamArray); 
$sameKey       = array_keys($sameKeySpam);

$spamAlone   = array_diff_key($spamArray, $sameKeySpam);
$healthAlone = array_diff_key($healthArray, $sameKeySpam);


$insertSql   = 'insert into word values '; 
$sqlValue    = '';
$sameNum     = count($sameKeySpam);
for ($i = 0; $i < $sameNum; $i++) {
     $sqlValue .= "('', '{$sameKey[$i]}', {$sameKeyHealth[$sameKey[$i]]}, {$sameKeySpam[$sameKey[$i]]}),";
    if ( (($i != 0) && ($i % 100 == 0)) || ($i == $sameNum - 1) ) {
         $sqlValue = rtrim($sqlValue, ',');
        $dbLocal->exec($insertSql . $sqlValue);
        $sqlValue = '';
    }
}

$sqlValue = '';
$i        = 1;
foreach ($spamAlone as $k => $v) {
    $sqlValue .= "('', '{$k}', 0, {$v}),";
    if ($i % 1000 == 0) {
        $sqlValue = rtrim($sqlValue, ',');
        $dbLocal->exec($insertSql . $sqlValue);
        $sqlValue = '';
    }
    $i++;
}
if ($sqlValue) {
    $sqlValue = rtrim($sqlValue, ',');
    $dbLocal->exec($insertSql . $sqlValue);
}

$i        = 1;
$sqlValue = '';
foreach ($healthAlone as $k => $v) {
    $sqlValue .= "('', '{$k}', {$v}, 0),";
    if ($i % 1000 == 0) {
        $sqlValue = rtrim($sqlValue, ',');
        $dbLocal->exec($insertSql . $sqlValue);
        $sqlValue = '';
    }
    $i++;
}
if ($sqlValue) {
    $sqlValue = rtrim($sqlValue, ',');
    $dbLocal->exec($insertSql . $sqlValue);
}


