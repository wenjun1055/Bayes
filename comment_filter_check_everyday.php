<?php
/**
 * Author: WenJun
 * Date:   2013-02-22 上午10:03
 * Email:  wenjun1055@gmail.com
 *
 * File:   comment_filter_check_everyday.php
 * Desc:   每天运行一次，纠正程序自动屏蔽评论的错误问题
 */

if(!defined('SHOWMONITORS')){
    define('SHOWMONITORS', false);
}
require_once(dirname(__FILE__).'/../../Koubei/config.inc.php');
require_once(JM_FRAMEWORK_ROOT . 'JMFrameworkConsole.php');

JMRegistry::set('serverConfig', $serverConfig);
JMRegistry::set('rpcServer', $rpcServer);
JMRegistry::set('monoLogger', $monoLogger);

$dbRW      = new JMDbMysqlReadWriteSplit();
$dbRW->addMaster(JMDbMysql::GetConnection(DATABASE));

$redis     = JMRedis::getConnectionByName('local');

$rpcClient = new JMRpcClient('filter_comment');
$rpcClient->_setClass('Comment');

$sphinx  = Utility_SphinxClient::Connection();
$sphinx->ResetFilters();

$allWordCounter = 0;

$healthArray = array();
$spamArray   = array();
$allArray    = array();

function utfSubstr($str)
{
    for ($i = 0; $i < 30; $i++) {
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

$endTime        = time();
$startTime = $endTime - 86400;
$allWordCounter = $redis->get(COMMENT_WORDS_COUNTER);


    $sql  = "select content,status,score from product_report_comments where ";
    $sql .= "dateline>={$startTime} and dateline<={$endTime}";

    $commentContent = $dbRW->master()->queryAll($sql);
    foreach ($commentContent as $row) {
        if ($row['score'] >= 90) {
            $spamArray[utfSubstr($row['content'])] = $row;
        } else {
            $healthArray[utfSubstr($row['content'])] = $row;
        }
    }

    $spamArrayCounter   = count($spamArray);
    $allSpamWord        = array();
    $allHealthWord      = array();
    $counter            = 0;

    $allArray = array_merge($spamArray, $healthArray);

    foreach ($allArray as $row) {
        $key     = array();
        $content = $sphinx->EscapeString($row['content']);
        $words   = $sphinx->BuildKeywords($content, 'reports', false);
        $num     = count($words);
        if ($num) {
            for ($i = 0; $i < $num; $i++) {
                $key[$i] = $words[$i]['tokenized'];
            }
        }
        $key         = array_unique($key);
        if ($row['score'] >= SPAM_COMMENT_SCORE && $row['status'] == 1) {
            $allSpamWord = array_merge($key, $allSpamWord);
        } elseif ($row['score'] >= SPAM_COMMENT_SCORE && $row['status'] == 0) {
            $allHealthWord = array_merge($key, $allHealthWord);
            $allHealthWord = array_merge($key, $allHealthWord);
        } elseif ($row['score'] < SPAM_COMMENT_SCORE) {
            if ($counter < $spamArrayCounter) {
                $allHealthWord = array_merge($key, $allHealthWord);
                $counter++;
            }
        }

    }
    $allSpamWord   = array_count_values($allSpamWord);
    $allHealthWord = array_count_values($allHealthWord);

    //get the same key from $spamArray and $healthyKey
    $sameWordValue   = array_intersect_key($allSpamWord, $allHealthWord);
    $sameWord        = array_keys($sameWordValue);
    $spamWordValue   = array_diff_key($allSpamWord, $sameWordValue);
    $healthWordValue = array_diff_key($allHealthWord, $sameWordValue);

    foreach ($sameWord as $word) {
        $numString = $redis->get(COMMENT_WORDS_PREFIX . $word);
        if ($numString) {
            $temp = explode("_", $numString);
            $spam_word_num    = $temp[1] + $allSpamWord[$word];
            $healthy_word_num = $temp[0] + $allHealthWord[$word];
            $redis->set(COMMENT_WORDS_PREFIX . $word, $healthy_word_num . '_' . $spam_word_num);
        } else {
            //redis中不存在这个词语
            $redis->set(COMMENT_WORDS_PREFIX . $word, $allHealthWord[$word] . '_' . $allSpamWord[$word]);
            $allWordCounter++;
        }
    }

    foreach ($spamWordValue as $k => $v) {
        $numString = $redis->get(COMMENT_WORDS_PREFIX . $k);
        if ($numString) {
            $temp = explode("_", $numString);
            $spam_word_num    = $temp[1] + $v;
            $healthy_word_num = $temp[0];
            $redis->set(COMMENT_WORDS_PREFIX . $k, $healthy_word_num . '_' . $spam_word_num);
        } else {
            $redis->set(COMMENT_WORDS_PREFIX . $k, '0_' . $v);
            $allWordCounter++;
        }
    }

    foreach ($healthWordValue as $k => $v) {
        $numString = $redis->get(COMMENT_WORDS_PREFIX . $k);
        if ($numString) {
            $temp = explode("_", $numString);
            $spam_word_num    = $temp[1];
            $healthy_word_num = $temp[0] + $v;
            $redis->set(COMMENT_WORDS_PREFIX . $k, $healthy_word_num . '_' . $spam_word_num);
        } else {
            $redis->set(COMMENT_WORDS_PREFIX . $k, $v . '_0');
            $allWordCounter++;
        }
    }

    foreach ($spamWordValue as $k => $v) {
        $numString = $redis->get(COMMENT_WORDS_PREFIX . $k);
        if ($numString) {
            $temp = explode("_", $numString);
            $spam_word_num    = $temp[1] + $v;
            $healthy_word_num = $temp[0];
            $redis->set(COMMENT_WORDS_PREFIX . $k, $healthy_word_num . '_' . $spam_word_num);
        } else {
            $redis->set(COMMENT_WORDS_PREFIX . $k, '0_' . $v);
        }
    }

    $redis->set(COMMENT_WORDS_COUNTER, $allWordCounter);
