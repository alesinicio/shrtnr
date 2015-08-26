<?php
	require_once "config.php";

	define("QUERY_RETURN_ROWS_AFFECTED"	,1);
	define("QUERY_RETURN_DATA_ARRAY"	,2);
	define("QUERY_RETURN_INSERT_ID"		,3);
	date_default_timezone_set(TIMEZONE);
	
	$shrtnr = new shrtnr();
	
	if ($shrtnr->getParameter("include") !== false) {
		$shrtnr->includeLink();
	} elseif ($shrtnr->getParameter("remove") !== false) {
		$shrtnr->removeLink();
	} elseif ($shrtnr->getParameter("install") !== false) {
		$shrtnr->installShrtnr();
	} elseif ($shrtnr->getParameter("listLinks") !== false) {
		$shrtnr->listLinks();
	} elseif ($shrtnr->getParameter("listLinksHTML") !== false) {
		$shrtnr->listLinksHTML();
	} else {
		$shrtnr->routeLink();
	}
	
	class shrtnr {
		public function routeLink() {
			$link	= $this->parseREST();
			if (strlen($link) == 0) 			$this->redirectToErrorPage();
			if (!$this->validateLink($link))	$this->redirectToErrorPage();
			$url	= $this->getURL($link);
			if ($url === false) 				$this->redirectToErrorPage();
			$this->updateCounter($link);
			header("Location: $url");
		}
		public function includeLink() {
			if (INSERTION_PWD_REQUIRED && !$this->checkPassword()) $this->error("Invalid password");
			$url = $this->getParameter('url');
			if ($url === false) 						$this->error("Invalid call - missing URL parameter");
			if (!filter_var($url, FILTER_VALIDATE_URL))	$this->error("Invalid URL");
		
			$customURL	= $this->getParameter('customURL');
			if ($customURL && substr($customURL, 0, 1) == LINK_PADDING)
				$this->error("Custom links cannot begin with '".LINK_PADDING."'");
		
			if ($this->isCustomURLAlreadyInDB($customURL))		$this->error("Custom URL is already being used");
		
			$fields		= array("lnkURL", "lnkTimestamp", "lnkIP", "lnkClicks");
			$values		= array($url, date("YmdHis"), $_SERVER['REMOTE_ADDR'], 0);
		
			if ($customURL !== false) {
				$fields[] = "lnkCustomURL";
				$values[] = $customURL;
			}
		
			$result = $this->insertLinkIntoDatabase($fields, $values);
			if ($result === false) error("Error inserting link into database");
		
			$this->success($customURL !== false ? $customURL : $this->intToBase($result));
		}
		public function removeLink() {
			if (DELETION_PWD_REQUIRED && !$this->checkPassword()) $this->error("Invalid password");
			if (!CAN_DELETE)		$this->error("Links cannot be removed");
			$link = $this->getParameter("link");
			if ($link === false	)	$this->error("Invalid call - missing shortened link to remove");
		
			$result = $this->removeLinkFromDatabase($link);
			if ($result) {
				$this->success(null, true);
			} else {
				$this->error("Error removing link");
			}
		}
		public function installShrtnr() {
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
				$this->success("", false, "Database was prepared successfully! You can start using shrtnr!");
			} else {
				$this->error("Error creating shrtnr tables... maybe do it by hand?");
			}
			exit;
		}
		public function listLinks() {
			$this->success(null, false, json_encode($this->listLinksRaw()));
		}
		public function listLinksHTML() {
			$data = $this->listLinksRaw();
			$headers = array("Link", "URL", "Creation date", "Creator IP", "Clicks", "Custom URL");
			if (file_exists("shrtnr.css")) echo "<link rel='stylesheet' type='text/css' href='shrtnr.css'>";
			echo $this->printToTable($data, $headers);
		}
		
		private function updateCounter($link) {
			$query = new updatequery();
			$query->setTable("links");
			$query->addNewValueNoPrepare("lnkClicks", "lnkClicks + 1");
			if ($this->isCustomURL($link)) {
				$query->addWhere("lnkCustomURL", "=", $link);
			} else {
				$query->addWhere("lnkID", "=", $this->baseToInt($link));
			}
			$query->runQuery();
		}
		private function getURL($link) {
			$customURL = ($this->isCustomURL($link));
		
			$query = new selectquery();
			$query->setTable("links");
			$query->addField("lnkURL");
			if ($customURL) {
				$query->addWhere("lnkCustomURL", "=", $link);
			} else {
				$id = $this->baseToInt($link);
				$query->addWhere("lnkID", "=", $id);
			}
			$result = $query->getArrayResult(true);
		
			if (count($result) == 0) return false;
			return $result[0];
		}
		private function insertLinkIntoDatabase($fields, $values) {
			$query = new insertquery();
			$query->setTable("links");
			$query->setFields($fields);
			$query->setValues($values);
			$result = $query->runQuery();
		
			if ($result === false) $this->error("Error inserting link into database");
		
			return $result;
		}
		private function removeLinkFromDatabase($link) {
			$customURL = $this->isCustomURL($link);
		
			$query = new deletequery();
			$query->setTable("links");
			if ($customURL) {
				$query->addWhere("lnkCustomURL", "=", $link);
			} else {
				$id = $this->baseToInt($link);
				$query->addWhere("lnkID", "=", $id);
			}
			$result = $query->runQuery();
		
			return $result;
		}
		private function listLinksRaw() {
			if (LIST_PWD_REQUIRED) {
				if (!$this->checkDateOffset())	$this->error("Invalid timestamp - now it's ".date("YmdHis"));
				if (!$this->checkPassword()) 	$this->error("Invalid password");
			}
		
			$query = new selectquery();
			$query->setTable("links");
			$query->addOrderBy("lnkTimestamp");
			$result = $query->getArrayResult();
		
			$output = array();
			foreach($result as $row) {
				$row['lnkID'] = $this->intToBase($row['lnkID']);
				$output[] = $row;
			}
			return $output;
		}
		
		private function intToBase($num) {
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
		private function baseToInt($base) {
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
		
		private function parseREST() {
			$a = str_replace(HTTPD_FILES_PATH, "", $_SERVER['REQUEST_URI']);
			$b = explode("/", $a);
			return $b[0];
		}
		private function isCustomURL($link) {
			return substr($link, 0, 1) != LINK_PADDING;
		}
		private function validateLink($link) {
			if ($this->isCustomURL($link)) return true;
			if (strlen($link) != URL_PADDING) return false;
		
			$link = ltrim($link, LINK_PADDING);
		
			foreach(str_split($link) as $char) {
				if (strpos(SYMBOLS, $char) === false) return false;
			}
			return true;
		}
		public function getParameter($param) {
			if (USE_POST) {
				if (!isset($_POST[$param])) return false;
				return $_POST[$param];
			} else {
				if (!isset($_GET[$param])) return false;
				return $_GET[$param];
			}
		}
		private function isCustomURLAlreadyInDB($customURL) {
			$query = new selectquery();
			$query->setTable("links");
			$query->addField("lnkID");
			$query->addWhere("lnkCustomURL", "=", $customURL);
			$result = $query->getArrayResult(true);
		
			return count($result) > 0;
		}
		private function checkDateOffset() {
			$time = $this->getParameter("timestamp");
			if ($time === false) $this->error("Invalid call - missing timestamp");
		
			$timestamp 		= DateTime::createFromFormat("YmdHis", $time);
			$now 			= new DateTime();
			$offsetMinus	= new DateTime();
			$offsetMinus->modify("-5 minutes");
		
			$offsetPlus	= new DateTime();
			$offsetPlus->modify("+5 minutes");
		
			return ($timestamp > $offsetMinus && $timestamp < $offsetPlus);
		}
		private function printToTable($arrData, $arrHeaders) {
			$output = "<table><thead><tr>";
			foreach($arrHeaders as $header) {
				$output .= "<th>$header</th>";
			}
			$output .= "</tr></thead><tbody>";
			foreach($arrData as $row) {
				$output .= "<tr>";
				foreach($row as $cell) {
					$output .= "<td>$cell</td>";
				}
				$output .= "</tr>";
			}
			$output .= "</tbody></table>";
			return $output;
		}
		
		private function redirectToErrorPage() {
			header("Location: ".ERROR_PAGE);
			exit;
		}
		private function error($msg) {
			$output['status'] = 0;
			$output['error'] = $msg;
		
			echo json_encode($output);
			exit;
		}
		private function success($link=null, $remove=false, $customMsg=null) {
			$output['status'] = 1;
			if ($link != null) 			$output['shrtnrURL'] = $link;
			if ($customMsg !== null)	$output['message'] = $customMsg;
			echo json_encode($output);
			exit;
		}

		private function checkPassword() {
			$pwd = $this->getParameter("pwd");
			if ($pwd === false) $this->error("Password needed to perform the action");
		
			$hash = md5(PWD_HASH.$this->getParameter("url").$this->getParameter("link").$this->getParameter("customURL").$this->getParameter("timestamp"));
			return ($hash == $pwd);
		}
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
	class updatequery {
		private $table;
		private $where = array();
		private $values = array();
		private $valuesNoPrepare = array();
		private $args = array();
		private $stmt;
		
		public function setTable($table) {
			$this->table = $table;
		}
		public function addWhere($field, $condition, $value) {
			$this->where[] = array("field"=>$field, "condition"=>$condition, "value"=>$value);
		}
		public function clearWhere() {
			$this->where = array();
		}
		public function clearValues() {
			$this->values = array();
		}
		public function addNewValueNoPrepare($field, $value) {
			$this->valuesNoPrepare[] = array("field"=>$field, "value"=>$value);
		}
		public function addNewValue($field, $value) {
			$this->values[] = array("field"=>$field, "value"=>$value);
		}
		private function getFinalFields() {
			$output = "";
			foreach($this->values as $value) {
				$output .= $value['field']."=?, ";
				$this->args[] = $value['value'];
			}
			foreach($this->valuesNoPrepare as $value) {
				$output .= $value['field']."=".$value['value'].", ";
			}
			return substr($output,0,-2);	
		}
		private function getFinalWhere() {
			$output = "WHERE ";
			if (count($this->where) == 0) return null;
			foreach($this->where as $clause) {
				$output .= $clause['field'].$clause['condition']."? AND ";
				$this->args[] = $clause['value'];
			}
			return substr($output,0,-5);
		}
		private function getFinalQuery() {
			$this->args = array();
			$table = $this->table;
			$values = $this->getFinalFields();
			$where = $this->getFinalWhere();
			
			return "UPDATE $table SET $values $where";
		}
		public function getQuery() {
			return $this->getFinalQuery();
		}
		public function runQuery() {
			$query 			= $this->getQuery();
			return dbconnection::getResults($query, false, $this->args);
		}
		private function getBindTypes() {
			$types = '';
			foreach($this->args as $par) {
				if(is_int($par)) {
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
	}