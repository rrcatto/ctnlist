<?php
/*

Module: views class
Author: Richard Catto
Creation Date: 2011-03-01
Updated: 2014-09-29 Fat Free Framework

*/

class views {

	protected $fat;
	protected $dbconn;

	function __construct($fat) {
		$this->fat = $fat;
		$this->dbconn = $fat->get('dbconn');
	}

	function __destruct() {
	}

	public function LogPageView() {
		// IP address of the person viewing the order form
		$ipaddr = $this->fat->get('IP');
		$xfwdfor = getenv('HTTP_X_FORWARDED_FOR');
		$referrer = $this->fat->get('SERVER.HTTP_REFERER');
		$url = $this->fat->get('URI');
		
		$sql = "insert into views (v_url, v_ip, v_xfwdfor, v_referrer, v_when) values (\"$url\",\"$ipaddr\",\"$xfwdfor\",\"$referrer\",now())";
		return $this->dbconn->query($sql);
	}
}
?>