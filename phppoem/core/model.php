<?php
namespace Poem;

class Model{
	protected $_db = null; // 数据库资源
	protected $db_cfg = array(); // 数据库配置

	protected $_table = '';
	protected $_distinct = '';
	protected $_field = '*';
	protected $_join = array();
	protected $_where = array();
	protected $_group = '';
	protected $_having = '';
	protected $_order = '';
	protected $_limit = '';
	protected $_union = '';
	protected $_lock = '';
	protected $_comment = '';
	protected $_force = '';
	protected $_ismaster = false; // 针对查询，手动选择主库

	protected $_bind  = array();
	protected $_sql   = '';

	function __construct($tb_name='',$config=''){
		$this->_table = $tb_name;
		if( $config === '' ){
			// 配置文件
			if( $dsn = config('db_dsn') ){
				$this->db_cfg = $dsn;
			}else{
				$this->db_cfg = array(
					'db_type'       => config('db_type'),
					'db_host'       => config('db_host'),
					'db_name'       => config('db_name'),
					'db_user'       => config('db_user'),
					'db_pass'       => config('db_pass'),
					'db_port'       => config('db_port'),
					'db_charset'    => config('db_charset'),
					'db_deploy'     => config('db_deploy'),
					'db_rw_separate'=> config('db_rw_separate'),
					'db_master_num' => config('db_master_num'),
					'db_slave_no'   => config('db_slave_no'),
				);
			}
		}else{
			// 用户指定配置
			$this->db_cfg = $config;
		}
		$this->connectDB();
	}

	function connectDB(){
		if( $this->_db !== null ) return $this->_db;
		$this->_db = Db::getIns($this->db_cfg);
		return $this->_db;
	}

	function sql() {
		return $this->_sql;
	}

	function beginTransaction(){
		$this->_db->init_connect(true);

		$this->_db->beginTransaction();
	}
	function rollBack(){
		$this->_db->rollBack();
	}
	function commit(){
		$this->_db->commit();
	}
	function master(){
		$this->_ismaster = true;
		return $this;
	}
	
	function query($sql,$bind=array()) {
		$this->_db->init_connect($this->_ismaster);
		$this->_sql = $sql;
		$info = $this->_db->select($sql,$bind);
		$this->afterSql();
		return $info;
	}
	function exec($sql) {
		$this->_db->init_connect(true);

		$this->_sql = $sql;
		$info = $this->_db->exec($sql);
		$this->afterSql();
		return $info;
	}
	function setInc($field,$num) {
		return $this->update("{$field}={$field}+".intval($num));
	}
	function setDec($field,$num) {
		return $this->update("{$field}={$field}-".intval($num));
	}

	function bind($val){
		$key = count($this->_bind);
		$this->_bind[":$key"] = $val;
		return $this;
	}

	function distinct($flag=true){
		$this->_distinct = $flag ? 'DISTINCT ':'';
		return $this;
	}

	function field($str){
		$this->_field = $str;
		return $this;
	}

	function join($str,$type='INNER'){
		$this->_join[] = stristr($str, 'JOIN') ? $str : $type.' JOIN '.$str;
		return $this;
	}

	function where($arr){
		if(is_string($arr)) $this->_where['_string'] = $arr;
		else $this->_where = array_merge($this->_where,$arr);
		return $this;
	}

	function having($str){
		$this->_having = $str;
		return $this;
	}

	function limit($b=0,$e=0){
		if( $e == 0 ){ $e=$b; $b=0;}
		$this->_limit = $b;
		if( $e ) $this->_limit .= ",$e";
		return $this;
	}

	function order($str){
		$this->_order = $str;
		return $this;
	}
	function group($str){
		$this->_group = $str;
		return $this;
	}

	function insert($data=null){
		if( $data == null ){ return; }

		$this->_db->init_connect(true);
		// INSERT INTO more (id, NaMe) values (?, ?)
		$keys = '';$vals='';
		foreach ($data as $k => $v) {
			if(is_null($v)) continue;
			$keys .= "$k,";
			$vals .= ":$k,";
			$this->_bind[":$k"] = $v;
		}
		$keys = substr($keys, 0,-1);
		$vals = substr($vals, 0,-1);
		$this->_sql  = 'INSERT INTO '.$this->_table." ($keys) VALUES ($vals)";
		$info = $this->_db->insert($this->_sql,$this->_bind);
		$this->afterSql();
		return $info;
	}
	function insertAll($data=null,$num=1000){
		if( !is_array($data[0]) ){ return false; }
		$this->_db->init_connect(true);

		$keys = implode(',',array_keys($data[0]));
		$sql = "insert into ".$this->_table." ($keys) values";
		$vals = array();
		foreach ($data as $v) {
			$vals[] = '('.implode(',' ,$this->parseValue($v) ).')';
			if( count($vals)>=$num ){
				$this->_sql  = 'INSERT INTO '.$this->_table." ($keys) VALUES ".implode(',',$vals);
				$info = $this->_db->insert($this->_sql,$this->_bind);
				$vals = array();
			}
		}
		if( count($vals) ){
			$this->_sql  = 'INSERT INTO '.$this->_table." ($keys) VALUES ".implode(',',$vals);
			$info = $this->_db->insert($this->_sql,$this->_bind);
		}
		$this->afterSql();
		return $info;
	}

	function update($data=null){
		if( $data == null ){ return; }
		$this->_db->init_connect(true);

		if( isset($data['id']) ){
			$this->where(array('id'=>$data['id']));
			unset($data['id']);
		}
		if( empty($this->_where) ) return false;
		if( is_array($data) ){
			foreach ($data as $k => $v) {
				$keys .= "$k=:$k,";
				$bind[":$k"] = $v;
			}
			$keys = substr($keys, 0,-1);
			$this->_bind = array_merge($this->_bind,$bind);
		}else if( is_string($data)){
			$keys = $data;
		}else{
			new \Exception('update params must be array or string');
		}

		$this->_sql  = 'UPDATE '.$this->_table." SET {$keys}";
		$this->setWhere($this->_where);
		$info = $this->_db->update($this->_sql,$this->_bind);
		$this->afterSql();
		return $info;
	}

	function delete(){
		$this->_db->init_connect(true);

		$this->_sql  = 'DELETE FROM '.$this->_table;
		$this->setWhere($this->_where);
		$info = $this->_db->delete($this->_sql,$this->_bind);
		$this->afterSql();
		return $info;
	}

	function select(){
		$this->_db->init_connect($this->_ismaster);

		// $selectSql = 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%LOCK%%COMMENT%';
		$this->_sql = 'SELECT '.$this->_distinct.$this->_field.' FROM '.$this->_table;
        $this->setJoin($this->_join);
        $this->setWhere($this->_where);
        $this->setGroup($this->_group);
        $this->setHaving($this->_having);
        $this->setOrder($this->_order);
        $this->setLimit($this->_limit);
        $this->setUnion($this->_union);
        $this->setLock($this->_lock);
        $this->setComment($this->_comment);
        $this->setForce($this->_force);

		$info = $this->_db->select($this->_sql,$this->_bind);
		$this->afterSql();
		return $info;
	}

	function count(){
		$this->_db->init_connect(true);

		$this->_sql = 'SELECT count(*) as num FROM '.$this->_table;
        $this->setJoin($this->_join);
		$this->setWhere($this->_where);
		$this->setGroup($this->_group);
		$this->setOrder($this->_order);
		$this->setLimit($this->_limit);
		$info = $this->_db->select($this->_sql,$this->_bind);
		$this->afterSql();
		return $info[0]['num'];
	}

	function find(){
		$info = $this->select();
		return $info[0];
	}

	function id($id){
		return $this->where(array('id'=>$id))->find();
	}

	protected function afterSql(){
		foreach ($this->_bind  as $key => $value) {
			$this->_sql = str_replace($key, $this->_db->_conn->quote($value), $this->_sql);
		}
		$time = number_format(T('poem_db_exec',-1)*1000,2);
		Log::trace('SQL',$this->_sql."[{$time}ms]");
		$this->_distinct = '';
		$this->_field = '*';
		$this->_join = array();
		$this->_where = array();
		$this->_group = '';
		$this->_having = '';
		$this->_order = '';
		$this->_limit = '';
		$this->_union = '';
		$this->_lock = '';
		$this->_comment = '';
		$this->_force = '';
		$this->_ismaster  = false;
		$this->_bind  = array();
	}


	protected function setWhere($_where=null,$flag=false){
		if( $_where == null ) return '';
		$logic = 'AND';
		if( isset( $_where['_logic'] ) ){
			$logic = strtoupper($_where['_logic']);
			unset($_where['_logic']);
		}

		$item = array();
		foreach ($_where as $k => $v) {
			if( $k == '_complex' ){
				$item[] = substr($this->setWhere($v,true), 7);
			}elseif( is_array($v) ){
				$exp = strtoupper($v[0]); //  in like
				if( preg_match('/^(NOT IN|IN)$/',$exp) ){
					if( is_string($v[1]) ) $v[1] = explode(',', $v[1]);
					$vals = implode(',', $this->parseValue($v[1]) );
					$item[] = "$k $exp ($vals)";
				}elseif( preg_match('/^(=|!=|<|<>|<=|>|>=)$/',$exp) ){
					$k1  = count($this->_bind);
					$item[] = "$k $exp :$k1";
					$this->_bind[":$k1"] = $v[1];
				}elseif( preg_match('/^(BETWEEN|NOT BETWEEN)$/',$exp) ){
					$tmp = is_string($v[1]) ? explode(',', $v[1]): $v[1];
					$k1  = count($this->_bind);
					$k2  = $k1 + 1;
					$item[] = "$k $exp :$k1 AND :$k2";
					$this->_bind[":$k1"] = $tmp[0];
					$this->_bind[":$k2"] = $tmp[1];
				}elseif( preg_match('/^(LIKE|NOT LIKE)$/',$exp) ){
					if( is_array($v[1]) ){
						$likeLogic = isset($v[2]) ? strtoupper($v[2]) : 'OR';
						$like = [];
						foreach ($v[1] as $like_item)  $like[] = "$k $exp ".$this->parseValue($like_item);
						$str = implode($likeLogic, $like);
						$item[] = "($str)";
					}else{
						$wyk = ':'.count($this->_bind);
						$item[] = "$k $exp $wyk";
						$this->_bind[$wyk] = $v[1];
					}
				}else{
					throw new \Exception("exp error", 1);
					// $wyk = ':'.count($this->_bind);
					// $item[] = "$k $exp $wyk";
					// $this->_bind[$wyk] = $val;
				}
			}elseif( $k=='_string' ){
				$item[] = $v;
			}else{
				$wyk = ':'.count($this->_bind);
				$item[] = "$k=$wyk";
				$this->_bind[$wyk] = $v;
			}
		}
		
		$str = ' WHERE ('.implode(" $logic ", $item).')';
		if( $flag == true ) return $str;
		$this->_sql .= $str;
	}

	function setJoin($_join){
		if( empty($_join) ) return false;
		$this->_sql .= ' '.implode(' ', $_join);
	}
	
	function setGroup($_group){
	    if( empty($this->_group) ) return false;
		$this->_sql .= ' GROUP BY '.$this->_group;
	}
	function setHaving($_having){
	    if( empty($this->_having) ) return false;
		$this->_sql .= ' HAVING '.$this->_having;
	}
	function setOrder($_order){
	   	if( empty($this->_order) ) return false;
		$this->_sql .= ' ORDER BY '.$this->_order;
	}
	function setLimit($_limit){
		if( empty($this->_limit) ) return false;
		$this->_sql .= ' LIMIT '.$this->_limit;
	}
	function setUnion($_union){
		return '';
	}
	function setLock($_lock){
		return '';
	}
	function setComment($_comment){
		return '';
	}
	function setForce($_force){
		return '';
	}


	protected function parseValue($val){
		if( is_string($val) ) return $this->_db->_conn->quote($val);
		elseif( is_array($val) ) return array_map([$this,'parseValue'], $val);
		elseif( is_bool($val) ) return $val ?1 :0 ;
		elseif( is_null($val) ) return 'null';
		else return $val;
	}
	

}



?>