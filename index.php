<?php
	require_once "config.php";

	define("QUERY_RETURN_ROWS_AFFECTED"	,1);
	define("QUERY_RETURN_DATA_ARRAY"	,2);
	define("QUERY_RETURN_INSERT_ID"		,3);
	
	if (getParameter("i") !== false) {
		includeLink();
	} elseif (getParameter("remove") !== false) {
		removeLink();
	} elseif (getParameter("install") !== false) {
		installShrtnr();
	} else {
		echo routeLink();
	}
	
	function routeLink() {
		$link	= parseREST();
		if (!validateLink($link))	redirectToErrorPage();
		$url	= getURL($link);
		if ($url === false) 		redirectToErrorPage();
		header("Location: $url");
	}
	function includeLink() {
		if (INSERTION_PWD_REQUIRED && !checkPassword()) error("Invalid password");
		$url = getParameter('url');
		if ($url === false) 						error("Invalid call - missing URL parameter");
		if (!filter_var($url, FILTER_VALIDATE_URL))	error("Invalid URL");
		
		$customURL	= getParameter('customURL');
		if ($customURL && substr($customURL, 0, 1) == LINK_PADDING)
													error("Custom links cannot begin with '".LINK_PADDING."'");

		if (isCustomURLAlreadyInDB($customURL))		error("Custom URL is already being used");
		
		$fields		= array("lnkURL", "lnkTimestamp", "lnkIP", "lnkClicks");
		$values		= array($url, date("YmdHis"), $_SERVER['REMOTE_ADDR'], 0);
		
		if ($customURL !== false) {
			$fields[] = "lnkCustomURL";
			$values[] = $customURL;
		}

		$result = insertLinkIntoDatabase($fields, $values);
		if ($result === false) error("Error inserting link into database");

		success($customURL !== false ? $customURL : intToBase($result));
	}
	function removeLink() {
		if (DELETION_PWD_REQUIRED && !checkPassword()) error("Invalid password");
		if (!CAN_DELETE)		error("Links cannot be removed");
		$link = getParameter("link");
		if ($link === false	)	error("Invalid call - missing shortened link to remove");
	
		$result = removeLinkFromDatabase($link);
		if ($result) {
			success(null, true);
		} else {
			error("Error removing link");
		}
	}
	function installShrtnr() {
		$script = "
CREATE TABLE `links` (
  `lnkID` int(11) NOT NULL AUTO_INCREMENT,
  `lnkURL` varchar(50000) NOT NULL,
  `lnkTimestamp` datetime NOT NULL,
  `lnkIP` varchar(15) NOT NULL,
  `lnkClicks` int(11) NOT NULL,
  `lnkCustomURL` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`lnkID`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		
		if (dbconnection::execSQLUnprepared($script) === true) {
			success("", false, "Database was prepared successfully! You can start using shrtnr!");
		} else {
			error("Error creating shrtnr tables... maybe do it by hand?");
		}
		exit;
	}
	
	function getURL($link) {
		$customURL = (isCustomURL($link));
	
		$query = new selectquery();
		$query->setTable("links");
		$query->addField("lnkURL");
		if ($customURL) {
			$query->addWhere("lnkCustomURL", "=", $link);
		} else {
			$id = baseToInt($link);
			$query->addWhere("lnkID", "=", $id);
		}
		$result = $query->getArrayResult(true);
	
		if (count($result) == 0) return false;
		return $result[0];
	}
	function insertLinkIntoDatabase($fields, $values) {
		$query = new insertquery();
		$query->setTable("links");
		$query->setFields($fields);
		$query->setValues($values);
		$result = $query->runQuery();
		
		if ($result === false) error("Error inserting link into database");

		return $result;
	}
	function removeLinkFromDatabase($link) {
		$customURL = isCustomURL($link);
	
		$query = new deletequery();
		$query->setTable("links");
		if ($customURL) {
			$query->addWhere("lnkCustomURL", "=", $link);
		} else {
			$id = baseToInt($link);
			$query->addWhere("lnkID", "=", $id);
		}
		$result = $query->runQuery();
	
		return $result;
	}
	
	function intToBase($num) {
		$radix 	= strlen(SYMBOLS);
		$pos	= 0;
		$symbols= SYMBOLS;
		$out	= "";
		
		if ($num==0) {
			$out[$pos] = $symbols[0];
		} else {
			while ($num > 0) {
				$r = $num % $radix;
				$out .= $symbols[$r];
				$num = ($num - $r) / $radix;
				$pos--;
			}
		}
		
		$out = str_pad($out, URL_PADDING - 1, "0", STR_PAD_LEFT);
		$out = LINK_PADDING.$out;

		return $out;
	}
	function baseToInt($base) {
		$base = ltrim($base, LINK_PADDING);
		$base = ltrim($base, "0");

		$radix	= strlen(SYMBOLS);
		$arr 	= str_split($base,1);
		$i 		= 0;
		$out	= 0;

		foreach($arr as $char) {
			$pos = strpos(SYMBOLS, $char);
			$partialSum = $pos * pow($radix, $i);
			$out += $partialSum;
			$i++;
		}
		
		return $out;
	}
	
	function parseREST() {
		$a = str_replace(HTTPD_FILES_PATH, "", $_SERVER['REQUEST_URI']);
		$b = explode("/", $a);
		return $b[0];
	}
	function isCustomURL($link) {
		return substr($link, 0, 1) != LINK_PADDING;
	}
	function validateLink($link) {
		if (isCustomURL($link)) return true;
		if (strlen($link) != URL_PADDING) return false;

		$link = ltrim($link, LINK_PADDING);

		foreach(str_split($link) as $char) {
			if (strpos(SYMBOLS, $char) === false) return false;
		}
		return true;
	}
	function getParameter($param) {
		if (USE_POST) {
			if (!isset($_POST[$param])) return false;
			return $_POST[$param];
		} else {
			if (!isset($_GET[$param])) return false;
			return $_GET[$param];
		}
	}
	function isCustomURLAlreadyInDB($customURL) {
		$query = new selectquery();
		$query->setTable("links");
		$query->addField("lnkID");
		$query->addWhere("lnkCustomURL", "=", $customURL);
		$result = $query->getArrayResult(true);
		
		return count($result) > 0;
	}
	
	function redirectToErrorPage() {
		header("Location: ".ERROR_PAGE);
	}
	function error($msg) {
		$output['status'] = 0;
		$output['error'] = $msg;
	
		echo json_encode($output);
		exit;
	}
	function success($link=null, $remove=false, $customMsg=null) {
		$output['status'] = 1;
		if ($link != null) 			$output['shrtnrURL'] = $link;
		if ($customMsg !== null)	$output['message'] = $customMsg;
		echo json_encode($output);
		exit;
	}

	function checkPassword() {
		$pwd = getParameter("pwd");
		if ($pwd === false) error("Password needed to perform the action");
		
		$hash = md5(PWD_HASH.getParameter("url").getParameter("link").getParameter("customURL"));
		return ($hash == $pwd);
	}	
	
	class dbconnection {
		private static $instance = null;
		public static $lasterror = "";
		public static function getInstance() {
			if (!(self::$instance instanceof MySQLi)) {
				self::createInstance();
			}
			return self::$instance;
		}
		private static function createInstance() {
			require_once "config.php";
			self::$instance = new mysqli(DB_HOST, DB_USER, DB_PWD, DB_DB);
			self::$instance->set_charset("UTF8");
		}
		public static function getResults($query, $forceUTF8Conversion = false, $args = array()) {
			$output		= array();
			$link		= self::getInstance();
			if ($args !== array()) {
				$types		= self::getBindTypes($args);
				array_unshift($args, $types);
			}
			$returnData	= substr($query, 0, 6);
			switch ($returnData) {
				case "SELECT":
					$returnData = QUERY_RETURN_DATA_ARRAY;
					break;
				case "UPDATE":
				case "DELETE":
					$returnData = QUERY_RETURN_ROWS_AFFECTED;
					break;
				case "INSERT":
					$returnData = QUERY_RETURN_INSERT_ID;
					break;
			}
			$result = self::execSQL($query, $args, $returnData);
			if ($returnData == QUERY_RETURN_DATA_ARRAY) {
				if (!is_array($result)) {
					$result = array();
				}
				foreach($result as $row) {
					$output[] = $row;
				}
			} else {
				$output = $result;
			}
			return $output;
		}
		public static function sqlEscape($data) {
			if (!isset($data) || empty($data)) return '';
			if (is_numeric($data)) return $data;
	
			$non_displayables = array(
					'/%0[0-8bcef]/', // url encoded 00-08, 11, 12, 14, 15
					'/%1[0-9a-f]/', // url encoded 16-31
					'/[\x00-\x08]/', // 00-08
					'/\x0b/', // 11
					'/\x0c/', // 12
					'/[\x0e-\x1f]/'
			); // 14-31
	
			foreach ($non_displayables as $regex)
				$data = preg_replace($regex, '', $data);
			$data = str_replace("'", "''", $data);
			return $data;
		}
		public static function getBindTypes($array) {
			$types = '';
			foreach ($array as $par) {
				if (is_int($par)) {
					$types .= 'i';
				} elseif (is_float($par)) {
					$types .= 'd';
				} elseif (is_string($par)) {
					$types .= 's';
				} else {
					$types .= 'b';
				}
			}
			return $types;
		}
		public static function execSQLUnprepared($sql) {
			$mysqli = self::getInstance();
			return $mysqli->query($sql);
		}
		private static function execSQL($sql, $params, $returnMode) {
			$mysqli = self::getInstance();
			if (!$stmt = $mysqli->prepare($sql)) return false;
	
			if ($params !== array()) {
				call_user_func_array(array(
						$stmt,
						'bind_param'
				), self::refValues($params));
			}
			$stmt->execute();
	
			switch ($returnMode) {
				case QUERY_RETURN_ROWS_AFFECTED:
					$result = $mysqli->affected_rows;
					break;
				case QUERY_RETURN_INSERT_ID:
					$result = $stmt->insert_id;
					break;
				case QUERY_RETURN_DATA_ARRAY:
					$meta = $stmt->result_metadata();
	
					while ($field = $meta->fetch_field()) {
						$parameters[] = &$row[$field->name];
					}
	
					call_user_func_array(array(
							$stmt,
							'bind_result'
					), self::refValues($parameters));
	
					$results = array();
					while ($stmt->fetch()) {
						$x = array();
						foreach ($row as $key=>$val) {
							$x[$key] = $val;
						}
						$results[] = $x;
					}
	
					$result = $results;
					break;
				default:
					$result = array();
					break;
			}
	
			$error = $mysqli->error;
			if ($error != "") {
				self::$lasterror = $error;
			}
			$stmt->close();
			self::closeConnection();
	
			return ($error != "" ? false : $result);
		}
		private static function closeConnection() {
			self::$instance->close();
			self::$instance = null;
		}
		private static function refValues($arr) {
			if (strnatcmp(phpversion(), '5.3') >= 0) {
				$refs = array();
				foreach ($arr as $key=>$value)
					$refs[$key] = &$arr[$key];
				return $refs;
			}
			return $arr;
		}
	}
	class selectquery{
		private $where;
		private $orderBy;
		private $table;
		private $fields;
		private $groups;
		private $joins = array();
		private $lowerlimit = null;
		private $limitQtd = null;
		private $args = array();
		private $stmt;
		private $formatter = array();
		private $runFunc = array();
	
		public function addWhere($field, $type, $condition, $level=null, $fixedValue=true) {
			if ($level == null) {
				$newLevel = rand();
			} else {
				$newLevel = $level;
			}
			$this->where[$newLevel][] = array("field"=>$field,"type"=>$type,"condition"=>$condition,"fixedValue"=>$fixedValue);
		}
		public function addOrderBy($field,$rule="ASC"){
			$this->orderBy[] = array($field,$rule);
		}
		public function addField($fields) {
			if (is_array($fields)) {
				foreach ($fields as $field) {
					$this->fields[] = $field;
				}
			} else {
				foreach(func_get_args() as $field) {
					$this->fields[] = $field;
				}
			}
		}
		public function addJoin($type, $outertable, $srcfield, $dstfield=null){
			$dstfield = ($dstfield == null ? $srcfield : $dstfield);
			$this->joins[] = "$type $outertable ON $outertable.$dstfield = $this->table.$srcfield";
		}
		public function addJoinAdvanced($type, $outertable, $outertablealias, $arrConditions) {
			$conditions = "";
			if (is_array($arrConditions)) {
				foreach($arrConditions as $cond) {
					$conditions .= "$cond AND ";
				}
				$conditions = substr($conditions,0,-4);
			} else {
				$conditions = $arrConditions;
			}
			$this->joins[] = "$type $outertable $outertablealias ON $conditions";
		}
		public function addGroupBy($field) {
			$this->groups[] = $field;
		}
		public function setTable($table) {
			$this->table = $table;
		}
		public function addFormatter($field, $type) {
			$this->formatter[$field] = $type;
		}
		public function addFunction($field, $strFunction, $arrArgs) {
			$this->runFunc[$field] = array("function"=>$strFunction, "args"=>$arrArgs);
		}
		private function finalJoins() {
			$joinClause = "";
			foreach ($this->joins as $join) {
				$joinClause .= $join." ";
			}
			return $joinClause;
		}
		private function finalGroups() {
			if (count($this->groups) == 0) return null;
			$groupClause = "GROUP BY ";
			foreach ($this->groups as $group) {
				$groupClause .= $group.", ";
			}
			return substr($groupClause,0,-2);
		}
		private function finalWhere() {
			$whereClause = "";
			$this->args = array();
			if (count($this->where) != 0) {
				$whereClause = " WHERE ";
				foreach($this->where as $level) {
					$whereClause .= "(";
					foreach($level as $clause) {
						if ($clause['fixedValue']) {
							$whereClause .= $clause['field']." ".$clause['type']." ? OR ";
							$this->args[] = $clause['condition'];
						} else {
							$whereClause .= $clause['field']." ".$clause['type']." ".$clause['condition']." OR ";
						}
					}
					$whereClause = substr($whereClause,0,-4).") AND ";
				}
				$whereClause = substr($whereClause,0,-4);
			}
			return $whereClause;
		}
		private function finalOrder() {
			$orderClause = "";
			if (count($this->orderBy) != 0) {
				$orderClause = " ORDER BY ";
				foreach($this->orderBy as $clause) {
					$orderClause .= "$clause[0] $clause[1],";
				}
				$orderClause = substr($orderClause,0,-1);
			}
			return $orderClause;
		}
		private function finalFields() {
			$fields = "";
			if (count($this->fields) != 0) {
				foreach($this->fields as $field) {
					$fields .= "$field,";
				}
				$fields = substr($fields,0,-1);
			} else {
				$fields = "*";
			}
			return $fields;
		}
		private function finalQry() {
			if ($this->lowerlimit !== null && $this->limitQtd !== null) {
				$init = $this->lowerlimit;
				$offset = "LIMIT $init,$this->limitQtd";
			} elseif ($this->lowerlimit != null) {
				$offset = "LIMIT $this->lowerlimit;";
			} else {
				$offset = null;
			}
			$fields = $this->finalFields();
			$table = $this->table;
			$joins = $this->finalJoins();
			$where = $this->finalWhere();
			$order = $this->finalOrder();
			$group = $this->finalGroups();
			return "SELECT $fields FROM $table $joins $where $group $order $offset";
		}
		private function getResultParamArray() {
			$meta = $this->stmt->result_metadata();
			$fields	= $results = array();
			while ($field = $meta->fetch_field()) {
				$var = $field->name;
				$$var = null;
				$fields[$var] = &$$var;
			}
			return $fields;
		}
		public function getObjResult() {
			$query			= $this->finalQry();
			return dbconnection::getResults($query, false, $this->args);
		}
		private function recreateSingleColumn($array) {
			$output = array();
			foreach($array as $row) {
				$keys = array_keys($row);
				$output[] = $row[$keys[0]];
			}
			return $output;
		}
		private function getBindTypes() {
			foreach($this->where as $level) {
				foreach($level as $param) {
					$par[] = $param['condition'];
				}
			}
			return dbconnection::getBindTypes($par);
		}
		private function formatOutput($array) {
			$b = array();
			foreach($array as $row) {
				foreach($row as $key=>$val) {
					if(isset($this->formatter[$key])) {
						$a[$key] = stringFunctions::formatByType($val, $this->formatter[$key], $key);
					} else {
						$a[$key] = $val;
					}
				}
				$b[] = $a;
			}
			return $b;
		}
		private function runFunctions($array) {
			$b = array();
			foreach($array as $row) {
				foreach($row as $key=>$val) {
					if(isset($this->runFunc[$key])) {
						$args = array();
						$f = $this->runFunc[$key];
						foreach($f["args"] as $arg) {
							$args[] = ($arg === "%FIELD%" ? $val : $arg);
						}
						$a[$key] = call_user_func_array($f["function"], $args);
					} else {
						$a[$key] = $val;
					}
				}
				$b[] = $a;
			}
			return $b;
		}
	
		public function getArrayResult($singleField=false) {
			$array = $this->getObjResult();
	
			if ($this->runFunc!= array()) 			$array = $this->runFunctions($array);
			if ($this->formatter!= array()) 		$array = $this->formatOutput($array);
	
			if ($singleField) return $this->recreateSingleColumn($array);
			return $array;
		}
		public function setLimit($lower, $limit=null) {
			$this->lowerlimit = $lower;
			$this->limitQtd = $limit;
		}
		public function getQuery() {
			return $this->finalQry();
		}
		public function getFields() {
			return $this->fields;
		}
	}
	class insertquery {
		private $values = array();
		private $fields = array();
		private $table;
		private $fromValues = true;
		private $stmt;
	
		public function setFromValues($boolean) {
			$this->fromValues = $boolean;
		}
		public function setValues($values) {
			if (is_array($values)) {
				$this->values = $values;
			} else {
				$this->values = func_get_args();
			}
		}
		public function setFields($fields) {
			if (is_array($fields)) {
				$this->fields = $fields;
			} else {
				$this->fields = func_get_args();
			}
		}
		public function setTable($table) {
			$this->table = $table;
		}
		private function finalValues() {
			if (count($this->values) == 0) return;
			$val = "(";
			foreach ($this->values as $value) {
				$val .= "?,";
			}
			$val = substr($val,0,-1).")";
			return $val;
		}
		private function finalFields() {
			if (count($this->fields) == 0) return;
			$fie = "(";
			foreach ($this->fields as $field) {
				$fie .= "$field,";
			}
			$fie = substr($fie,0,-1).")";
			return $fie;
		}
		private function finalQry() {
			$fields = $this->finalFields();
			$values = $this->finalValues();
			$valuesFinal = ($this->fromValues ? "VALUES$values" : $values);
			$query = "INSERT INTO $this->table$fields $valuesFinal";
			return $query;
		}
		public function runQuery($getInsertedID = true) {
			$query = $this->finalQry();
			$dbconnection = dbconnection::getInstance();
	
			return dbconnection::getResults($query, false, $this->values);
		}
		public function getQuery() {
			return $this->finalQry();
		}
	}
	class deletequery {
		private $table;
		private $where;
		private $stmt;
		private $args = array();
	
		public function setTable($table) {
			$this->table = $table;
		}
		public function addWhere($field, $condition, $value) {
			$this->where[] = array("field"=>$field, "condition"=>$condition);
			$this->args[] = $value;
		}
		private function finalWhere() {
			$whereClause = "";
			if (count($this->where) != 0) {
				$whereClause = "WHERE ";
				foreach($this->where as $clause) {
					$whereClause .= $clause['field'].$clause['condition']."? AND ";
				}
				$whereClause = substr($whereClause,0,-4);
			}
			return $whereClause;
		}
		public function getQuery() {
			$where = $this->finalWhere();
			return "DELETE FROM $this->table $where";
		}
		public function runQuery($returnAffectedRows=false) {
			$query = $this->getQuery();
			$dbconnection = dbconnection::getInstance();
				
			return dbconnection::getResults($query, false, $this->args);
		}
		public static function getInstance() {
			return new deletequery();
		}
	}