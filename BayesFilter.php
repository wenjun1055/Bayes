<?php
namespace Lib;

class BayesFilter
{
  const SPAMSCALE = 0.5;
	const HEALTHYSCALE = 0.5;
    private $totalWordNum;
	private $sphinx;
	private $db;
	private $redis;
	private static $instance;
	
	public static function getInstance()
    {
		$class = get_called_class();
        if(!self::$instance) self::$instance = new $class();
        return self::$instance;
	}
	
	private function __construct()
    {
		$this->sphinx       = \Utility_SphinxClient::Connection('filter_comment');
		$this->db           = new \JMDbMysqlReadWriteSplit();
		$this->redis        = \JMRedis::getConnectionByName ('filter_comment');
        $this->totalWordNum = $this->redis->get(COMMENT_WORDS_COUNTER);
	}
	
	public function bayes($sentence)
    {
		$list  = $this->splitWords($sentence);
		$count = count($list);
		$pn1   = 1.0;
		$pn2   = 1.0;
		
		for ($i = 0; $i < $count; $i++)
        {
			$probability = $this->getProbability($list[$i]['tokenized']);
			$spam        = $probability['spam'];
			$healthy     = $probability['healthy'];
			$temp        = ($spam * self::SPAMSCALE) / (($spam * self::SPAMSCALE) + ($healthy * self::HEALTHYSCALE));
			$pn1        *= $temp;
			$pn2        *= (1 - $temp);
		}
		@$p = $pn1 / ($pn1 + $pn2);
		return $p * 100;
	}
	
	private function getProbability($word)
    {
		$result = $this->redis->get(COMMENT_WORDS_PREFIX . $word);
		if (empty($result)) {
			$data["spam"]    = 0.4;
			$data["healthy"] = 0.6;
			return $data;
		} else {
			$temp = explode("_", $result);
			$data['spam_num']    = $temp[1];
			$data['healthy_num'] = $temp[0];
		}
		$data["spam"]    = $data['spam_num'] / $this->totalWordNum;
		$data["healthy"] = $data['healthy_num'] / $this->totalWordNum;
		if (($data["spam"] + $data['healthy']) < 0.005) {
			$data["spam"]    = 0.4;
			$data["healthy"] = 0.6;
		}
		$data["spam"]    = ($data["spam"] < 0.001) ? 0.001 : $data["spam"];
		$data["healthy"] = ($data["healthy"] < 0.001) ? 0.001 : $data["healthy"];
		return $data;
	}
	
	public function splitWords($sentence)
    {
		$pattern = '/[\x{4e00}-\x{9fa5}]+/u';
		$matches = "";
		preg_match_all($pattern, $sentence, $matches);
		$sentence = "";
		foreach ($matches[0] as $row) {
			$sentence = $sentence.$row." ";
		}
		$result = $this->sphinx->buildKeywords($sentence, $index = INDEX, false);
		return $result;
	}
}
