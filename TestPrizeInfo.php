<?php
namespace App\Model;

use App\Model\R2m\Redis2Mysql;
use DB;

class TestPrizeInfo extends Redis2Mysql
{
    protected static $tableName = 'wili_prize_info';
    protected static $dbKey = 'wili_vote';
    protected static $cacheKey = 'wili_vote';

    public function __construct() {
    	parent::__construct(self::$tableName, self::$dbKey, self::$cacheKey);
    }
}