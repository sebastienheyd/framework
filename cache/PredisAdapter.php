<?php
$wfRedisExists = class_exists("Redis", false);

if (!$wfRedisExists || (defined("WF_USE_PREDIS") || WF_USE_PREDIS))
{
	require_once WEBEDIT_HOME.'/framework/libs/predis/Autoloader.php';
	Predis\Autoloader::register();

	class wf_PredisAdapter
	{
		protected $connected = false;
		protected $multiMode = false;
		
		protected $payLoadOK;
		
		/**
		 * @var Predis\Transaction
		 */
		protected $transaction = null;
		/**
		 * @var Predis\Client
		 */
		protected $predis = null;
		
		function __construct()
		{
			$this->payLoadOK = Predis\Response\Status::get("OK");
		}
		
		/**
		 * @param Predis\Response\Status $responseStatus
		 * @return boolean
		 */
		protected function isResponseOK($responseStatus)
		{
			return $this->payLoadOK === $responseStatus;
		}

		function connect($host, $port = 6379, $timeout = 0, $reserved = null, $retry_interval = 100)
		{
			$connectOpts = $this->makeConnectOpts($host, $port, $timeout, $reserved, $retry_interval);
			$this->predis = $this->getClient($connectOpts);
			return $this->predis !== null;
		}

		function pconnect($host, $port = 6379, $timeout = 0, $reserved = null, $retry_interval = 100)
		{
			$connectOpts = $this->makeConnectOpts($host, $port, $timeout, $reserved, $retry_interval);
			$connectArgs["persistent"] = true;
			$this->predis = $this->getClient($connectOpts);
			return $this->predis !== null;
		}
		
		/**
		 * @param unknown $connectOpts
		 * @return \Predis\Client
		 */
		protected function getClient($connectOpts) {
			try {
				$predis = new Predis\Client($connectOpts);
				$this->connected = true;
			} catch (Exception $e) {
				Framework::exception($e);
				$predis = null;
			}
			return $predis;
		}
		
		protected function makeConnectOpts($host, $port = 6379, $timeout = 0, $reserved = null, $retry_interval = 100)
		{
			if ($timeout == 0) {
				$timeout = 60;
			}
			$opts = array('profile' => new \Predis\Profile\RedisVersion280(), 'scheme' => 'tcp',
					'host' => $host, 'port' => $port, 'timeout' => $timeout);
			
			return $opts;
		}
		
		function auth()
		{
			// Not implemented
			return true;
		}

		function select($db)
		{
			return $this->isResponseOK($this->predis->select($db));
		}

		function flushDB()
		{
			$this->predis->flushdb();
			return true;
		}

		function sMembers($key)
		{
			if ($this->multiMode) {
				$this->transaction->smembers($key);
				return $this;
			} else {
				return $this->predis->smembers($key);
			}
		}

		/**
		 * @return wf_PredisAdapter|number
		 */
		function delete()
		{
			return $this->_delete(func_get_args()); 
		}
		
		/**
		 * @return wf_PredisAdapter|number
		 */
		function del()
		{
			return $this->_delete(func_get_args());
		}
		
		function _delete($keys)
		{
			if (isset($keys[0]) && is_array($keys[0])) {
				$keys = $keys[0];
			}
			if ($this->multiMode) {
				$this->transaction->del($keys);
				return $this;
			} else {
				return $this->predis->del($keys);
			}
		}

		function sIsMember($key, $member)
		{
			if ($this->multiMode) {
				$this->transaction->sismember($key, $member);
				return $this;
			} else {
				return $this->predis->sismember($key, $member);
			}
		}

		function sAdd($key, $member)
		{
			if ($this->multiMode) {
				$this->transaction->sadd($key, array($member));
				return $this;
			} else {
				return $this->predis->sadd($key, array($member));
			}
		}

		function sscan($key, &$ite, $pattern = null)
		{
			$opts = array();
			if ($pattern !== null) {
				$opts["match"] = $pattern;
			}
			if ($this->multiMode) {
				$this->transaction->sscan($key, $ite, $opts);
				return $this;
			} else {
				if ($ite === "0") {
					return false;
				}
				$res = $this->predis->sscan($key, $ite, $opts);
				$ite = $res[0];
				return $res[1];
			}
		}
		
		function scan(&$ite, $pattern = null)
		{
			$opts = array();
			if ($pattern !== null) {
				$opts["match"] = $pattern;
			}
			if ($this->multiMode) {
				$this->transaction->scan($ite, $opts);
				return $this;
			} else {
				if ($ite === "0") {
					return false;
				}
				$res = $this->predis->scan($ite, $opts);
				$ite = $res[0];
				return $res[1];
			}
		}

		function setex($key, $ttl, $value)
		{
			if ($this->multiMode) {
				$this->transaction->setex($key, $ttl, $value);
				return $this;
			} else {
				return $this->isResponseOK($this->predis->setex($key, $ttl, $value));
			}
		}

		function get($key)
		{
			if ($this->multiMode) {
				$this->transaction->get($key);
				return $this;
			} else {
				$value = $this->predis->get($key); 
				return $value === null ? false : $value;
			}
		}

		/**
		 * @param string $key
		 * @param mixed $value
		 * @return boolean
		 */
		function set($key, $value)
		{
			if ($this->multiMode) {
				$this->transaction->set($key, $value);
				return $this;
			} else {
				return $this->isResponseOK($this->predis->set($key, $value));
			}
		}

		function hGetAll($key)
		{
			if ($this->multiMode) {
				$this->transaction->hgetall($key);
				return $this;
			} else {
				return $this->predis->hgetall($key);
			}
		}
		
		function hMSet($key, $members)
		{
			if ($this->multiMode) {
				$this->transaction->hmset($key, $members);
				return $this;
			} else {
				return $this->isResponseOK($this->predis->hmset($key, $members));
			}
		}
		
		function expire($key, $seconds)
		{
			if ($this->multiMode) {
				$this->transaction->expire($key, $seconds);
				return $this;
			} else {
				return $this->predis->expire($key, $seconds);
			}
		}

		function multi()
		{
			$this->multiMode = true;
			$this->transaction = $this->predis->transaction();
			return $this;
		}

		function exec()
		{
			$this->multiMode = false;
			if ($this->transaction !== null) {
				$res = $this->transaction->execute();
				$this->transaction = null;
				return $res;
			}
			return array();
		}
		
		function close()
		{
			$this->predis->disconnect();
		}

		function __call($method, $args) {
			throw new Exception("Unimplemented method $method");
		}
	}
	
	if (!$wfRedisExists)
	{
		class Redis extends wf_PredisAdapter
		{
			const PIPELINE = 2;
			// Nothing here
		}
	}
}
