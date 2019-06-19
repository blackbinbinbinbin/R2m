<?php
namespace App\Model\R2m;
use Exception;

class TableHelper {
	private $_tableName;

	function __construct($tableName) {
		$this->_tableName = $tableName;
	}

	function getTableName(&$args = []) {
        if (isset($args['_tableName'])) {
            $tableName = arrayPop($args, '_tableName');
        } else {
            $tableName = $this->_tableName;
        }
        
        return $tableName;
    }

	/**
     * 【兼容函数】读取数据
     * @param array $args 参数列表，特殊参数前缀_
     * @param array $keyWord 查询关键字, array('_field', '_where', '_limit', '_sortKey', '_sortDir', '_lockRow', '_tableName', '_groupby', '_foundRows')
     */
    private function getObject(array $where = array(), $keyWord = array()) {
        $where = array_merge($where, $keyWord);
        
        $fetch = isset($where['_fetch']) ? $where['_fetch'] : null;
        $fetch || $fetch = 'getAll';
        
        $field = isset($where['_field']) ? $where['_field'] : null;
        $field || $field = "*";
        

        $tableNames = (array) $this->getTableName($where);
        
        $allSql = '';
        foreach ($tableNames as $i => $tableName) { 
            //检查表名是不是表达式
            if (strpos($tableName, ' ') !== false) { 
                continue;
            }
        }
        
       if (!$tableNames) {
           return array();
       }
        
        foreach ($tableNames as $i => $tableName) {
            if (count($tableNames) > 1) {
                //多表查询的情况
                $sql = $this->buildSql2($tableName, $field, $where, true);
                if ($allSql) {
                    $allSql .= "UNION ($sql)";
                } else {
                    $allSql = "($sql)";
                }
            } else  {
                //单表查询的情况
                $allSql = $this->buildSql2($tableName, $field, $where, false);
            }
        }
        
        if (count($tableNames) > 1) {
            $allSql = str_replace('SQL_CALC_FOUND_ROWS', '', $allSql);
            $allSql = $this->buildSql2("({$allSql}) AS t", $field, $where, false);
        }
        
        return $allSql;
    }


    function buildSql2($tableName, $field, $where, $onlyData) {
        $_where = isset($where['_where']) ? $where['_where'] : '1';
        if ($onlyData) {
            $field = "*";
        }
        
        $sql = "SELECT $field FROM {$tableName} WHERE {$_where} ";
        //构造条件部分
        $where = $this->escape($where);
        foreach ($where as $key => $value) {
            if ($key[0] == '_') {
                continue;
            }
    
            if (is_array($value)) {
                $sql .= "AND `{$key}` IN ('" . implode("','", $value) . "') ";
            } else {
                isset($value) && $sql .= "AND `{$key}` = '{$value}' ";
            }
        }
    
        if (!$onlyData) {
            isset($where['_groupby']) && $sql .= "GROUP BY {$where['_groupby']} ";
            $sortKey = isset($where['_sortKey']) ? $where['_sortKey'] : '';
            $sort = isset($where['_sort']) ? $where['_sort'] . ', ' : '';
            
            //排序
            if ($sortKey) {
                $sql .= "ORDER BY {$sort} {$sortKey}  {$sortDir} ";
            }
        }

        //标识是否锁行，注意的是也有可能锁表
        isset($where['_lockRow']) && $sql .= "FOR UPDATE ";
    
        return $sql;
    }

    /**
     * 把key => value的数组转化为后置连接字符串 
     * @author benzhan
     * @param array $args
     * @param string $connect
     */
    function genBackSql(array $args, $connect = ', ') {
        $str = '';
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $str .= "`$key` IN ('" . join("','", $value) . "') " . $connect; 
            } else if (isset($value)) {
                $str .= "`$key` = '$value'" . $connect; 
            } else {
                $str .= "`$key` IS NULL" . $connect; 
            }
        }
        return substr($str, 0, -strlen($connect));
    }

    /**
     * 把key => value的数组转化为前置连接字符串 
     * @author benzhan
     * @param array $args
     * @param string $connect
     */
    function genFrontSql(array $args, $connect = 'AND ') {
        $str = isset($args['_where']) ? "{$connect} {$args['_where']} " : '';
//        $str = '';
        foreach ($args as $key => $value) {
            if ($key[0] == '_') {
                continue;
            }

            if (is_array($value)) {
                $str .= "$connect `$key` IN ('" . join("','", $value) . "') "; 
            } else if (isset($value)) {
                $str .= "$connect `$key` = '$value' "; 
            } else {
                $str .= "$connect `$key` IS NULL "; 
            }
        }
        return $str;
    }

    /**
     * 转义需要插入或者更新的字段值
     *
     * 在所有查询和更新的字段变量都需要调用此方法处理数据
     *
     * @param mixed $str 需要处理的变量
     * @return mixed 返回转义后的结果
     */
    public function escape($where) {
    	foreach ($where as $key => $str) {
    		if (is_array($str)) {
	            $where[$key] = $this->escape($value);
	        } else {
	            $where[$key] = addslashes($str);
	        }
    	}
        
        return $where;
    }

    /**
     * 读取数据
     * @author benzhan
     * @param array $args 参数列表，特殊参数前缀_
     * @param array $keyWord 查询关键字, array('_field', '_where', '_limit', '_sortKey', '_sortDir', '_lockRow', '_tableName', '_groupby')
     * @return array 返回二维数组
     */
    function getAll(array $where = array(), $keyWord = array()) {      
        return $this->getObject($where, $keyWord);
    }

    /**
     * 读取一行数据
     * @author benzhan
     * @param array $args 参数列表，特殊参数前缀_
     * @param array $keyWord 查询关键字, array('_field', '_where', '_lockRow', '_tableName')
     */
    function getRow(array $where = array(), $keyWord = array()) {      
        $args['_limit'] = 2;
        return $this->getObject($where, $keyWord);
    }

    private function _addObject(array $args, $type = 'add') {
        $sql = ($type == 'add' ? 'INSERT INTO ' : 'REPLACE INTO ');
        $tableName = $this->_tableName;
        $args = $this->escape($args);
        $sql .= "{$tableName} SET " . $this->genBackSql($args, ', ');
        return $sql;
    }

    /**
     * INSERT一行数据
     * @author benzhan
     * @param array $args 参数列表
     */
    function addObject(array $args) {
        return $this->_addObject($args, 'add');
    }

    /**
     * 修改一条数据
     * @author benzhan
     * @param array $args 更新的内容
     * @param array $where 更新的条件
     */
    function updateObject(array $args, array $where) {
        $args = $this->escape($args);
        $where = $this->escape($where);
        $tableName = $this->_tableName;
        
        if (!$where) {
        	throw new Exception('更新数据不能没有限制条件');
        }
        
        $sql = "UPDATE `{$tableName}` SET " . $this->genBackSql($args, ', ') . ' WHERE 1 '. $this->genFrontSql($where, 'AND ');
        return $sql;
    }

    /**
     * 删除数据
     * @author benzhan
     * @param array $where 更新的条件
     */
    function delObject(array $where) {
        $where = $this->escape($where);
        $tableName = $this->_tableName;
        
        if (!$where) {
            throw new Exception('删除数据不能没有限制条件');
        }
        
        $sql = "DELETE FROM `{$tableName}` WHERE 1 " . $this->genFrontSql($where, 'AND ');
        return $sql;
    }
} 