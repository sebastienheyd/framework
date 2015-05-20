<?php
/**
 * This class uses phpredis (https://github.com/nicolasff/phpredis) for redis client.
 * To configure, add the following to your project.xml :
   <injection>
     <entry name="f_DataCacheService">f_DataCacheRedisService</entry>
   </injection>
   <datacache-redis>
     <server>
       <entry name="host">...</entry>
       <entry name="port">...</entry>
       <entry name="database">...</entry>
       <!-- Optionnal entries -->
       <entry name="password">...</entry>
     </server>
   </datacache-redis>
 */
class f_DataCacheRedisService extends f_DataCacheService
{
	private static $instance;
	private static $defaultRedisPort = 6379;
	private $useCompression = false;
	
	/**
	 * @var Redis
	 */
	private $redis = null;
	
	protected function __construct()
	{
		$this->redis = $this->getRedis();
	}
	
	/**
	 * @return Redis
	 */
	protected function getRedis()
	{
		if ($this->redis === null)
		{
			$conf = Framework::getConfigurationValue("datacache-redis/server");
			$redis = new Redis();
			
			try
			{
				if (!isset($conf["host"]))
				{
					Framework::warn(__METHOD__." host is not defined");
					$redis = new f_FakeRedis();
				}
				
				if (!isset($conf["port"]))
				{
					if (Framework::isDebugEnabled())
					{
						Framework::debug(__METHOD__." using default port ".self::$defaultRedisPort);
					}
					$conf["port"] = self::$defaultRedisPort;
				}
				$con = false;
				$timeout = isset($conf["timeout"]) ? $conf["timeout"] : 0.1;
				$persistent = isset($conf["persistent"]) && f_util_Convert::toBoolean($conf["persistent"]); 
				if ($persistent)
				{
				    $con = $redis->pconnect($conf["host"], $conf["port"], $timeout);
				}
				else
				{
				    $con = $redis->connect($conf["host"], $conf["port"], $timeout);
				}
				if (!$con)
				{
					Framework::warn(__METHOD__." could not connect to ".$conf["host"].":".$conf["port"]." persistent = ".var_export($persistent, true));
					$redis = new f_FakeRedis();
				}
				
				if (isset($conf["password"]) && ! $redis->auth($conf["password"]))
				{
					Framework::warn(__METHOD__." could not authenticate");
					$redis = new f_FakeRedis();
				}
				
				if (!isset($conf["database"]))
				{
					Framework::warn(__METHOD__." database not defined");
					$redis = new f_FakeRedis();
				}
				
				if (!$redis->select($conf["database"]))
				{
					Framework::warn(__METHOD__." could not select database ".$conf["database"]);
					$redis = new f_FakeRedis();
				}
				
				$this->useCompression = isset($conf["compression"]) && f_util_Convert::toBoolean($conf["compression"]);
			}
			catch (RedisException $e)
			{
				$redis = new f_FakeRedis();
			}
			
			$this->redis = $redis;
		}
		return $this->redis;
	}
	
	/**
	 * @return f_DataCacheService
	 */
	public static function getInstance()
	{
		if (self::$instance === null)
		{
			self::$instance = self::getServiceClassInstance(get_class());
		}
		return self::$instance;
	}
	
	public function clearCommand()
	{
		$this->redis->flushDB();
	}
	
	/**
	 * @param String $pattern
	 */
	public function getCacheIdsForPattern($pattern)
	{
		return $this->redis->sMembers("pattern-".$pattern);
	}
	
	protected function commitClear()
	{
		Framework::debug(__METHOD__);
		if ($this->clearAll)
		{
			Framework::debug(__METHOD__." : clear all");
			$this->redis->flushDB();
		}
		else
		{
			$keysToDelete = array();
			if (!empty($this->idToClear))
			{
				if (Framework::isDebugEnabled())
				{
					Framework::debug(__METHOD__.": idToClear : ".var_export($this->idToClear, true));
				}
				foreach (array_keys($this->idToClear) as $itemNameSpace)
				{
					$itemsKey = "items-$itemNameSpace";
					foreach ($this->redis->sMembers($itemsKey) as $keyParams)
					{
						$keysToDelete[] = "item-$itemNameSpace-$keyParams";
					}
				}
			}
			if (!empty($this->docIdToClear))
			{
				if (Framework::isDebugEnabled())
				{
					Framework::debug(__METHOD__.": docIdToClear : ".var_export($this->docIdToClear, true));
				}
				foreach (array_keys($this->docIdToClear) as $docId)
				{
					foreach ($this->redis->sMembers("pattern-".$docId) as $cacheKey)
					{
						$keysToDelete[] = "item-$cacheKey";
					}
				}
			}
			$this->redis->delete($keysToDelete);
		}
		
		$this->clearAll = false;
		$this->idToClear = null;
		$this->docIdToClear = null;
	}
	
	/**
	 * @param f_DataCacheItem $item
	 */
	protected function register($item)
	{
		$itemNamespace = $item->getNamespace();
		if (!$this->redis->sIsMember("registration", $itemNamespace))
		{
			$multiRedis = $this->redis->multi(Redis::PIPELINE);
			foreach ($this->optimizeCacheSpecs($item->getPatterns()) as $pattern)
			{
				$multiRedis->sAdd("pattern-".$pattern, $itemNamespace);
			}
			$multiRedis->sAdd("registration", $itemNamespace);
			$multiRedis->exec();
		}
		
		$itemKey = $item->getNamespace()."-".$item->getKeyParameters();
		foreach ($item->getPatterns() as $pattern)
		{
			$multiRedis = $this->redis->multi(Redis::PIPELINE);
			if (is_numeric($pattern))
			{
				$multiRedis->sAdd("pattern-".$pattern, $itemKey);
			}
			$multiRedis->exec();
		}
	}
	
	/**
	 * @param f_DataCacheItem $item
	 */
	public function writeToCache($item)
	{
		$this->register($item);
		$data = array("v" => $item->getValues(),
			"c" => time(), "t" => $item->getTTL());
		$keyParams = $item->getKeyParameters();
		$itemNameSpace = $item->getNamespace();
		
		$serialized = $this->serialize($data);
		if ($serialized !== false) {
		    $this->redis->multi(Redis::PIPELINE)
		    ->sAdd("items-$itemNameSpace", $keyParams)
		    ->setex("item-".$itemNameSpace."-".$keyParams, $item->getTTL(), $serialized)
		    ->exec();
		}
	}

	/**
	 * @param f_DataCacheItem $item
	 * @return f_DataCacheItem
	 */
	protected function getData($item)
	{
		$dataSer = $this->redis->get("item-".$item->getNamespace()."-".$item->getKeyParameters());
		if ($dataSer !== false)
		{
			$data = $this->unserialize($dataSer);
			if ($data !== false)
			{
				$item->setCreationTime($data["c"]);
				$item->setValues($data["v"]);
				$item->setTTL($data["t"]);
				$item->setValidity(true);
			}
		}
		return $item;
	}
	
	/**
	 * @param unknown $data
	 * @return boolean|string false on error
	 */
	protected function serialize($data)
	{
	    $serialized = serialize($data);
	    if ($serialized === false) {
	        return false;
	    }
	    if ($this->useCompression) {
	        $serialized = gzcompress($serialized);
	    }
	    return $serialized;
	}
	
	/**
	 * @param unknown $data
	 * @return boolean|mixed false on error
	 */
	protected function unserialize($data)
	{
	    if ($this->useCompression) {
	        $data = gzuncompress($data);
	    }
	    if ($data === false) {
	        return false;
	    }
	    return unserialize($data);
	}
}

class f_FakeRedis
{
	private $multiMode = false;

	function connect()
	{
		return true;
	}
	
	function auth()
	{
		return true;
	}
	
	function select()
	{
		return true;
	}
	
	function flushDB()
	{
		return true;
	}

	function sMembers()
	{
		return ($this->multiMode) ? $this : array();
	}

	function delete()
	{
		return ($this->multiMode) ? $this : 1;
	}
	
	function sIsMember()
	{
		return ($this->multiMode) ? $this : false;
	}
	
	function sAdd()
	{
		return ($this->multiMode) ? $this : true;
	}
		
	function setex($key, $ttl, $value)
	{
		return ($this->multiMode) ? $this : true;
	}
	
	function get($key)
	{
		 
		return ($this->multiMode) ? $this : false;
	}

	function set($key, $value)
	{
		return ($this->multiMode) ? $this : false;
	}
	
	function multi()
	{
		$this->multiMode = true;
		return $this;
	}
	
	function exec()
	{
		$this->multiMode = false;
		return array();
	}
}
