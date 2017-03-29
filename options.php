<?php
/*

Module: optiona class
Author: Richard Catto
Creation Date: 2016-07-20

*/

class options {
	
	protected $fat;
	protected $dbconn;
	
	// $dbconn is a mysqli connection object to an open MySQL database
	function __construct($fat) {
		$this->fat = $fat;
		$this->dbconn = $fat->get('dbconn');
	}

	function __destruct() {
	}

    public function SetOption($opt,$val) {
        if ($this->ExistsOption($opt)) {
            $sql = "update options set o_value = \"$val\" where o_key = \"$opt\"";
        } else {
            $sql = "INSERT INTO `options` (`o_key`, `o_value`) VALUES(\"$opt\",\"$val\")";
        }
        return $this->dbconn->query($sql);
    }
    
    public function GetOption($opt) {
        $sql = "select o_value from options where o_key = \"$opt\"";
        $result = $this->dbconn->query($sql);
        $row = $result->fetch_array();
        $val = $row["o_value"];
        return $val;
    }
    
    public function ExistsOption($opt) {
        $sql = "select count(*) from options where o_key = \"$opt\"";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			return false;
		} else {
            return true;
        }
    }
}
?>