<?php
namespace App\Model\R2m;
use DB;
use Exception;
use Redis;

class Redis2Mysql {
    private $tableName;
    private $dbKey;
    private $cacheKey;

    /**
     * @var Redis
     */
    public $objRedis;
    /**
     * @var TableHelper
     */
    public $objTable;
    public $cacheInfo;

    private $_preKey;
    private $_preRowKey;
    private $_preAllKey;
    private $_key = '_keys';
    private $_debug = false;
    private $_debugMsg = '';

    public function __construct($tableName, $dbKey = 'default', $cacheKey = 'default') {
        $this->objTable = new TableHelper($tableName);
        $this->tableName = $tableName;
        $this->dbKey = $dbKey;
        $this->cacheKey = $cacheKey;

        //检查r2m配置
        $conf_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'conf'. DIRECTORY_SEPARATOR .'r2mInfo.conf.php';
        $conf = include($conf_dir);
        $cacheInfo = $conf['r2mInfo'][$this->cacheKey][$this->tableName];
        $this->cacheInfo = $cacheInfo ? $cacheInfo : $conf['r2mInfo'][$this->dbKey][$this->tableName];
        if (!$this->cacheInfo) {
            throw new Exception("redis没配置table name:{$this->tableName}");
        }

        //检查Redis
        $redis = new Redis();
        //连接
        $redis->connect($conf['redisInfo']['default']['host'], $conf['redisInfo']['default']['port']);
        $connect_status = $redis->ping();
        if($connect_status !== "+PONG") {
            throw new Exception("redis连接丢失");
        } else {
            $this->objRedis = $redis;
        }
        
        $dbName = $dbKey;
        $this->_preKey = "{$dbName}:{$this->tableName}";
        $this->_preRowKey = "{$this->_preKey}:row";
        $this->_preAllKey = "{$this->_preKey}:all";
        $this->_debugMsg = $this->_preKey . "\r\n";
    }

    public function setObjRedis($obj) {
        $this->objRedis = $obj;
    }

    public function _addObject(array $data) {
        $sql = $this->objTable->addObject($data);
        $ret = DB::select($sql);
        return $ret;
    }

    public function _getRow(array $where = [], array $keyWord = []) {
        $sql = $this->objTable->getRow($where, $keyWord);
        $datas = DB::select($sql);
        if (count($datas) > 1) {
            throw new Exception('查询的结果大于1条。');
        } else if (count($datas) <= 0) {
            return null;
        } 
        
        return (array) current($datas);
    }

    public function _getAll(array $where = array(), array $keyWord = array()) {
        $sql = $this->objTable->getAll($where, $keyWord);
        return DB::select($sql);
    }

    public function _updateObject(array $args = [], array $where = []) {
        $sql = $this->objTable->updateObject($args, $where);
        return DB::select($sql);
    }

    public function _delObject(array $where) {
        $sql = $this->objTable->delObject($where);
        return DB::select($sql);
    }

    //获取除了主键查询外，其余查询条件的md5 key值
    private function _getMd5Key(array $where, $prefix, array $keyWord = array()) {
        $cacheKey = $this->_getPreKey($where, $prefix, true);

        $args = array_merge($where, $keyWord);
        $key = http_build_query($args);
        if (strlen($key) > 32) {
            $key = md5($key);
        }

        if ($key) {
            $cacheKey .= ":$key";
        } else {
            $cacheKey .= ":empty";
        }

        return $cacheKey;
    }

    /**
     * 获取主键缓存key
     * @param array $where 数据
     * @param string $prefix 前缀
     * @param boolean $trimWhere 修正where
     * @return string
     */
    private function _getPreKey(array &$where, $prefix = 'row', $trimWhere = false) {
        $key = $prefix == 'row' ? $this->cacheInfo['key'] : $this->cacheInfo['all_key'];
        $cacheKeys = array();
        $cacheKey = "{$this->_preKey}:{$prefix}";
        if ($key) {
            $keys = explode(',', $key);
            foreach ($keys as $key) {
                $key = trim($key);
                if (isset($where[$key])) {
                    $cacheKeys[] = $this->_getKeyItem($key, $where[$key]);
                } else if ($prefix == 'row') {
                    return false;
                } else {
                    return $cacheKey . ':others';
                }
            }

            // 如果需要修正where，则需要在遍历后去掉
            if ($trimWhere) {
                foreach ($keys as $key) {
                    unset($where[$key]);
                }
            }
        }

        if ($cacheKeys) {
            return $cacheKey . ':' . join(':', $cacheKeys);
        } else {
            return $cacheKey . ':others';
        }
    }

    private function _getKeyItem($key, $value) {
        if (is_array($value)) {
            $tmpKey = join(',', $value);
            if (strlen($tmpKey) > 32) {
                $tmpKey = md5($tmpKey);
            }
            return "{$key}={$tmpKey}";
        } else {
            return "{$key}={$value}";
        }
    }

    //md5 key 映射到主键上这样删除就不用keys遍历删除了
    private function _md5Key2Prekey($md5Key) {
        $pos = strrpos($md5Key, ':');
        return substr($md5Key, 0, $pos);
    }

    private function _getOtherKey() {
        return "{$this->_preKey}:all:others";
    }

    private function setCache(array $args) {
        $cacheKey = $this->_getPreKey($args, 'row');
        if (!$cacheKey) {
            $msg = "没设置key:$cacheKey, cacheInfo:" . json_encode($this->cacheInfo) . ', args:' . json_encode($args);
            die($msg);
        }

        if ($this->_debug) {
            $this->_debugMsg .= "setCache, hMset:{$cacheKey}\r\n";
        }
        $this->objRedis->hMset($cacheKey, $args);

        if ($this->cacheInfo['ttl'] > 0) {
            if ($this->_debug) {
                $this->_debugMsg .= "expire:{$cacheKey}, ttl:{$this->cacheInfo['ttl']}\r\n";
            }
            $this->objRedis->expire($cacheKey, $this->cacheInfo['ttl']);
        }
    }


    /**
     * 删除列表的缓存
     * @param array $where
     */
    public function delListCache(array $where = [], $delOthers = true) {
        $table = $this->cacheInfo['table'];
        if ($delOthers && $table) {
            // 删除相关表
//            $tables = explode(',', $table);
//            foreach ($tables as $table) {
//                $table = trim($table);
//                $objR2m = new Redis2Mysql($table, $this->dbKey, $this->cacheKey);
//                $objR2m->setDebug($this->_debug);
//                $objR2m->delListCache($where, false);
//                $this->_debugMsg .= $objR2m->getDebugMsg();
//            }
        } else {
            $preKey = $this->_getPreKey($where, 'all');
            $otherKey = $this->_getOtherKey();

            // 记录getAll的key，避免keys的调用
            $_allKey = "{$this->_key}:{$preKey}";
            $_otherKey = "{$this->_key}:{$otherKey}";
            $otherKeys = $this->objRedis->hKeys($_otherKey);
            $_noOtherKey = "{$this->_key}:{$this->_preAllKey}:no_others";

            if ($_allKey == $_otherKey) {
                // 除了删除others，还需要删除no_others
                $keys = $this->objRedis->hKeys($_noOtherKey);
                $keys[] = $_noOtherKey;
            } else {
                // 需要清除所有key删除
                $keys = $this->objRedis->hKeys($_allKey);
                // 从no_others中删除对应的key
                $param = [$_noOtherKey];
                $param = array_merge($param, $keys);
                call_user_func_array(array($this->objRedis, "hDel"), $param);

                $keys[] = $_allKey;
            }

            $keys = array_merge($keys, $otherKeys);
            $keys[] = $_otherKey;

            if ($this->_debug) {
                $this->_debugMsg .= ("delListCache:" . join("\r\n", $keys) . "\r\n");
            }

            $this->objRedis->del($keys);
        }
    }

    /**
     * 删除行的缓存
     * @param array $where
     * @param
     * @throws R2m_Exception
     */
    public function delRowCache(array $where, $delOthers = true) {
        $table = $this->cacheInfo['table'];
        if ($delOthers && $table) {
            // 删除相关表
//            $tables = explode(',', $table);
//            foreach ($tables as $table) {
//                $table = trim($table);
//                $objR2m = new Redis2Mysql($table, $this->dbKey, $this->cacheKey);
//                $objR2m->setDebug($this->_debug);
//                $objR2m->delRowCache($where, false);
//                $this->_debugMsg .= $objR2m->getDebugMsg();
//            }
        } else {
            $cacheKey = $this->_getPreKey($where, 'row');
            if (!$cacheKey) {
                $msg = "没设置key:$cacheKey," . json_encode($this->cacheInfo);
                die($msg);
            }

            $this->objRedis->del($cacheKey);
        }
    }


    /**
     * 尝试删除单条数据,没数据则查where条件的数据
     * @param array $where
     * @return bool
     * @author benzhan
     */
    private function _delListCache2(array $where) {
        $allKey = $this->cacheInfo['all_key'];
        if ($allKey) {
            // 需要删除指定数据
            $preKey = $this->_getPreKey($where, 'all');
            if ($preKey == $this->_getOtherKey()) {
                // 获取完整信息
                $args = $this->getRow($where);
                if (!$args) {
                    if ($this->_debug) {
                        $this->_debugMsg .= ("_delListCache2, no data:" . http_build_query($where) . "\r\n");
                    }
                    // 不存在数据，则不需要清除list缓存
                    return true;
                }
            } else {
                $args = $where;
            }
        } else {
            $args = $where;
        }

        $this->delListCache($args);
    }


    /**
     * 读取多行数据
     * @param array $where
     * @param array $keyWord 查询关键字, array('_field', '_where', '_limit', '_sortKey', '_sortDir', '_lockRow', '_tableName')
     * @param  bool $updateList 是否强制更新缓存
     * @return array:
     */
    public function getAll(array $where = array(), array $keyWord = array(), $updateList = false) {
        $cacheKey = $this->_getMd5Key($where, 'all', $keyWord);

        // 判断是否要读取缓存
        if (!$updateList) {
            $this->_debug && $this->_debugMsg .= ("getAll, objRedis->get:{$cacheKey}\r\n");
            $data = $this->objRedis->get($cacheKey);
            if ($data) {
                return json_decode($data, true);
            }
        }

        // 读取数据库信息
        if ($this->_debug) {
            $this->_debugMsg .= ("getAll, objTable->getAll:" . http_build_query($where) . "\r\n");
        }
        $data = $this->_getAll($where, $keyWord);

        // 设置到cache里面
        if ($this->_debug) {
            $this->_debugMsg .= ("objRedis->setex({$cacheKey}, {$this->cacheInfo['ttl']}, data:" . count($data) . "\r\n");
        }

        if ($this->cacheInfo['ttl'] > 0) {
            $this->objRedis->setex($cacheKey, $this->cacheInfo['ttl'], json_encode($data));
        } else {
            $this->objRedis->set($cacheKey, json_encode($data));
        }

        $preKey = $this->_md5Key2Prekey($cacheKey);
        if ($preKey == $this->_getOtherKey()) {
            // 记录other的key，避免keys的调用
            $key2 = "{$this->_key}:{$preKey}";
            $this->_debug && $this->_debugMsg .= ("objRedis->hSet({$key2}, {$cacheKey}, " . date('Y-m-d H:i:s') . "\r\n");
            $this->objRedis->hSet($key2, $cacheKey, date('Y-m-d H:i:s'));
        } else {
            // 记录指定条件的key
            $key2 = "{$this->_key}:{$preKey}";
            $this->_debug && $this->_debugMsg .= ("objRedis->hSet({$key2}, {$cacheKey}, " . date('Y-m-d H:i:s') . "\r\n");
            $this->objRedis->hSet($key2, $cacheKey, date('Y-m-d H:i:s'));
            if ($this->cacheInfo['ttl'] > 0) {
                $this->objRedis->expire($key2, $this->cacheInfo['ttl']);
            }

            // 只记录非other的key
            $key3 = "{$this->_key}:{$this->_preAllKey}:no_others";
            $this->_debug && $this->_debugMsg .= ("objRedis->hSet({$key3}, {$cacheKey}, " . date('Y-m-d H:i:s') . "\r\n");
            $this->objRedis->hSet($key3, $cacheKey, date('Y-m-d H:i:s'));
        }

        return $data;
    }



    /**
     * 获取一个key的数据
     * @param array $where
     * @return array <NULL, array>
     */
    public function getRow(array $where) {
        $cacheKey = $this->_getPreKey($where, 'row');
        if (!$cacheKey) {
            // 找不到key，需要用别名
            $aliasCacheKey = $this->_getMd5Key($where, 'alias');
            $this->_debug && $this->_debugMsg .= ("getRow, objRedis->get:{$aliasCacheKey}\r\n");
            $cacheKey = $this->objRedis->get($aliasCacheKey);
        } else {
            $aliasCacheKey = false;
        }

        $data = [];
        if ($cacheKey) {
            if ($this->_debug) {
                $this->_debugMsg .= ("getRow, objRedis->hGetAll:{$cacheKey}\r\n");
            }
            $data = $this->objRedis->hGetAll($cacheKey);

            if ($data) {
                // 检查where, 除了主键外,还有别的条件
                foreach ($where as $key => $value) {
                    if (is_array($value)) {
                        if (!in_array($data[$key], $value)) {
                            if ($this->_debug) {
                                $this->_debugMsg .= ("\$data[\$key]:{$data[$key]}, \$value:" . json_encode($value) . "\r\n");
                            }
                            return [];
                        }
                    } else if ($data[$key] != $value) {
                        if ($this->_debug) {
                            $this->_debugMsg .= ("\$data[\$key]:{$data[$key]}, \$value:{$value}\r\n");
                        }
                        return [];
                    }
                }
            }
        }

        if (!$data) {
            // 从数据库重建
            $data = $this->_getRow($where);
            if ($this->_debug) {
                $this->_debugMsg .= ("getRow, objTable->getRow:" . http_build_query($where) . "\r\n");
            }

            if ($data) {
                $this->setCache($data);

                if ($aliasCacheKey) {
                    $cacheKey = $this->_getPreKey($data);
                    $this->_debug && $this->_debugMsg .= ("aliasCacheKey, objRedis->setex:{$aliasCacheKey}, {$this->cacheInfo['ttl']}, {$cacheKey}\r\n");
                    // 需要建立别名
                    if ($this->cacheInfo['ttl'] > 0) {
                        $this->objRedis->setex($aliasCacheKey, $this->cacheInfo['ttl'], $cacheKey);
                    } else {
                        $this->objRedis->set($aliasCacheKey, $cacheKey);
                    }
                }
            } else {
                $data = [];
            }
        }

        return $data;
    }


    /**
     * 增加一行数据
     * @param array $args
     * @param boolean $updateList
     * @return int
     */
    public function addObject(array $args, $updateList = true) {
        if ($this->_debug) {
            $this->_debugMsg .= ("addObject, objTable->addObject \r\n");
        }
        $flag = $this->_addObject($args);

        if ($updateList) {
            $this->delListCache($args);
        }

        return $flag;
    }


    /**
     * 修改一个key的数据 (如果没传全部主键,则会自己去getRow补全)
     * @param array $args 更新的内容
     * @param array $where 更新的条件
     * @param boolean $updateList
     * @return int 影响行数
     */
    public function updateObject(array $args, array $where, $updateList = true) {
        $cacheKey = $this->_getPreKey($where, 'row');

        if (!$cacheKey) {
            $row = $this->getRow($where);
            if (!$row) {
                // 没数据,不需要update
                return true;
            }

            $key =  $this->cacheInfo['key'];
            $keys = explode(',', $key);
            // 增加keyName
            foreach ($keys as $keyName) {
                $where[$keyName] = $row[$keyName];
            }
        }

        if (!$where) {
            die('更新数据不能没有限制条件');
        }

        if ($this->_debug) {
            $this->_debugMsg .= ("updateObject, objTable->updateObject:" . http_build_query($where) . "\r\n");
        }

        $flag = $this->_updateObject($args, $where);

        // 修改了主键,则需要删除老缓存
        $this->delRowCache($where);


        if ($updateList) {
            $this->_delListCache2($where);
        }

        return $flag;
    }


    /**
     * 删除单条数据
     * @param array $where
     * @param boolean $updateList
     * @throws R2m_Exception
     * @return int
     */
    public function delObject(array $where, $updateList = true) {
        $cacheKey = $this->_getPreKey($where, 'row');
        if (!$cacheKey) {
            $row = $this->getRow($where);
            if (!$row) {
                // 没数据,不需要del
                return 0;
            }

            // 增加keyName
            $key =  $this->cacheInfo['key'];
            $keys = explode(',', $key);
            foreach ($keys as $keyName) {
                $where[$keyName] = $row[$keyName];
            }
        }

        // 删除行缓存
        $this->delRowCache($where);
        if ($updateList) {
            // 删除操作先删除list的缓存操作，不然getRow查不到数据
            $this->_delListCache2($where);
        }

        if ($this->_debug) {
            $this->_debugMsg .= ("delObject, objTable->delObject:" . http_build_query($where) . "\r\n");
        }
        $flag = $this->_delObject($where);

        return $flag;
    }
}