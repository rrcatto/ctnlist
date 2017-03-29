<?php
/*

Module: ctnlist initialisation class to open a connection to the database
Short Description: returns a db connection
Author: Richard Catto
Creation Date: 2010-01-16
Last updated: 2011-06-12

Requires: PHP version 5.x

Long Description:
opens a connection to the ctnlist mysql database.

Creates tables if not present

Creates a global pointer to a mysqli object used for accessing the mailing list database in the entire application.

*/

define('CTNLIST_DESIGNS_DIRECTORY', '/usr/local/lib/php/ctnlist4/designs/');

class start {
  public $dbconn;
  public $dbPDO;
  public $oldversion;
  public $version;

  // create all necessary tables if they do not already exist
  // having this here, means there is no need for a separate installation script
  function __construct($fat) {
    $dbhost = $fat->get('dbhost');
    $dbuser = $fat->get('dbuser');
    $dbpass = $fat->get('dbpass');
    $dbname = $fat->get('dbname');
    
    $this->dbPDO = new \DB\SQL("mysql:host={$dbhost};dbname={$dbname}",$dbuser,$dbpass);
    
    // Connect to MySQL database using the mysqli PHP extension
    $this->dbconn = new mysqli($dbhost,$dbuser,$dbpass,$dbname);

    if ($this->dbconn->connect_error) {
      die('Connect Error (' . $this->dbconn->connect_errno . ') ' . $this->dbconn->connect_error);
    }

    // Ensure that database tables are present - create them if they do not exist
    $fat->set('dbconn',$this->dbconn);
    $fat->set('dbPDO',$this->dbPDO);
    $fat->set('user',""); // dummy value for user

    /*
    $sql = "CREATE TABLE `globalunsubscribe` (
  `gu_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gu_api` varchar(40) NOT NULL,
  `gu_domain` varchar(100) NOT NULL,
  `gu_dateunsubscribed` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `gu_email` varchar(100) NOT NULL,
  `gu_email_correction` varchar(100) NOT NULL,
  `gu_email_user` varchar(100) NOT NULL,
  `gu_email_domain` varchar(100) NOT NULL,
  `gu_type` enum('ADMIN','BOUNCE','BOUNCE-ADMIN','INVALID','SPAM','SPAM-ADMIN','USER') NOT NULL,
  `gu_reason` varchar(255) NOT NULL,
  `gu_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`gu_id`),
  UNIQUE KEY `gu_email` (`gu_email`),
  KEY `gu_reason` (`gu_type`),
  KEY `gu_email_user` (`gu_email_user`,`gu_email_domain`),
  KEY `gu_active` (`gu_active`),
  KEY `gu_type` (`gu_type`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

    $sql = "CREATE TABLE IF NOT EXISTS `globaldomainunsubscribe` (
    `gdu_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `gdu_api` varchar(40) NOT NULL,
    `gdu_domain` varchar(100) NOT NULL,
    `gdu_dateunsubscribed` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `gdu_domain_name` varchar(100) NOT NULL,
    `gdu_type` enum('NOTEXIST','SPAM') NOT NULL,
    `gdu_active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`gdu_id`),
    UNIQUE KEY `gdu_domain_name` (`gdu_domain_name`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";
    */
		
    // local sendlog table for this domain only
		
    $sql = "CREATE TABLE IF NOT EXISTS `sendlog` (
    `sl_id` serial,
    `sl_datesent` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sl_type` varchar(1) NOT NULL,
    `sl_method` varchar(10) NOT NULL DEFAULT '',
    `sl_numsent` int(11) NOT NULL DEFAULT '0',
    `sl_toemail` varchar(100) NOT NULL DEFAULT '',
    `sl_subject` varchar(200) NOT NULL DEFAULT '',
    PRIMARY KEY (`sl_id`),
    KEY `sl_toemail` (`sl_datesent`,`sl_toemail`),
    KEY `sl_datesent` (`sl_datesent`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

    if (!$this->dbconn->query($sql)) {
      echo "<p>SendLog table not created.</p>";
    }

		$sql = "CREATE TABLE IF NOT EXISTS `queue` (
  `q_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `q_muid` varchar(32) NOT NULL,
  `q_suid` varchar(32) NOT NULL,
  `q_dateadded` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `q_datelasttried` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `q_hold` tinyint(1) NOT NULL DEFAULT '0',
  `q_status` varchar(1) NOT NULL DEFAULT '-',
  `q_priority` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`q_id`),
  KEY `q_priority` (`q_priority`),
  KEY `q_priority_2` (`q_priority`,`q_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Queue table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `archives` (
  `a_id` int(11) NOT NULL auto_increment,
  `a_subject` varchar(200) default NULL,
  `a_html` longtext,
  `a_datecreated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `a_viewed` int(11) NOT NULL default '0',
  PRIMARY KEY  (`a_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Archives table not created.</p>";
		}

 		$sql = "CREATE TABLE IF NOT EXISTS `forwards` (
  `f_id` int(11) NOT NULL auto_increment,
  `f_s_uniqid` varchar(32) default NULL,
  `f_u_id` int(11) default '0',
  `f_m_uniqid` varchar(32) NOT NULL,
  `f_when` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `f_email` varchar(100) NOT NULL,
  PRIMARY KEY  (`f_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Forwards table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `messages` (
  `m_id` int(11) NOT NULL AUTO_INCREMENT,
  `m_uniqid` varchar(32) NOT NULL DEFAULT '0',
  `m_t_id` int(11) NOT NULL DEFAULT '0',
  `m_from` varchar(100) DEFAULT '',
  `m_subject` varchar(200) DEFAULT NULL,
  `m_priority` int(11) NOT NULL DEFAULT '0',
  `m_html` longtext,
  `m_text` longtext,
  `m_datesentbegin` datetime DEFAULT NULL,
  `m_datesentfinish` datetime DEFAULT NULL,
  `m_sent` int(11) NOT NULL DEFAULT '0',
  `m_viewed` int(11) NOT NULL DEFAULT '0',
  `m_bounces` int(11) DEFAULT '0',
  `m_a_id` int(11) NOT NULL DEFAULT '0',
  `m_interests` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`m_id`),
  UNIQUE KEY `m_uniqid` (`m_uniqid`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Messages table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `smopens` (
  `smo_s_uniqid` varchar(32) NOT NULL default '0',
  `smo_m_id` int(11) NOT NULL default '0',
  `smo_dateopened` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (`smo_s_uniqid`,`smo_m_id`,`smo_dateopened`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Smopens table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `smsent` (
  `sms_s_id` int(11) NOT NULL default '0',
  `sms_m_id` int(11) NOT NULL default '0',
  `sms_datesent` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (`sms_s_id`,`sms_m_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Smsent table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `subscribers` (
  `s_id` int(11) NOT NULL AUTO_INCREMENT,
  `s_u_id` int(11) DEFAULT '0',
  `s_uniqid` varchar(32) NOT NULL,
  `s_email` varchar(100) NOT NULL,
  `s_email_user` varchar(100) NOT NULL,
  `s_email_domain` varchar(100) NOT NULL,
  `s_priority` int(11) NOT NULL DEFAULT '0',
  `s_subscribedate` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `s_confirm` tinyint(1) NOT NULL DEFAULT '0',
  `s_confirmdate` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `s_unsubscribe` tinyint(1) NOT NULL DEFAULT '0',
  `s_unsubscribedate` datetime DEFAULT NULL,
  `s_unsubscribereason` varchar(100) DEFAULT NULL,
  `s_unsubscribe_bounce` tinyint(1) NOT NULL DEFAULT '0',
  `s_unsubscribe_admin` tinyint(1) NOT NULL DEFAULT '0',
  `s_bounces` int(11) DEFAULT '0',
  `s_emailsleft` int(11) NOT NULL DEFAULT '0',
  `s_fname` varchar(50) DEFAULT NULL,
  `s_lname` varchar(50) DEFAULT NULL,
  `s_province` varchar(30) DEFAULT NULL,
  `s_country` varchar(30) DEFAULT NULL,
  `s_gender` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`s_id`),
  UNIQUE KEY `s_uniqid` (`s_uniqid`),
  UNIQUE KEY `s_email` (`s_email`),
  KEY `userid` (`s_u_id`),
  KEY `s_unsubscribe` (`s_unsubscribe`),
  KEY `s_confirm` (`s_confirm`,`s_unsubscribe`),
  KEY `s_bounces` (`s_bounces`),
  KEY `s_unsubscribe_2` (`s_unsubscribe`,`s_bounces`),
  KEY `s_confirm_2` (`s_confirm`),
  KEY `s_confirmdate` (`s_confirmdate`),
  KEY `s_email_user` (`s_email_user`),
  KEY `s_email_domain` (`s_email_domain`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Subscribers table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `templates` (
  `t_id` int(11) NOT NULL auto_increment,
  `t_name` varchar(100) default NULL,
  `t_html` longtext,
  `t_text` longtext,
  PRIMARY KEY  (`t_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Templates table not created.</p>";
		} else {
			$sql = "select count(*) as t_count from templates";
			$result = $this->dbconn->query($sql);
			$row = $result->fetch_array();
			$count = $row['t_count'];
			if ($count == 0) {
				$template = new templates($fat);
				$today = date('Y-m-d');
				$template->CreateTemplate($today . ' dedicated mailer', CTNLIST_DESIGNS_DIRECTORY . 'html.div.template.txt', CTNLIST_DESIGNS_DIRECTORY . 'txt.basic.template.txt');
			}
		}
	
		$sql = "CREATE TABLE IF NOT EXISTS `users` (
  `u_id` int(11) NOT NULL AUTO_INCREMENT,
  `u_uniqid` varchar(32) NOT NULL,
  `u_identifier` varchar(100) NOT NULL,
  `u_referrer` varchar(100) DEFAULT NULL,
  `u_affiliate_id` varchar(100) DEFAULT NULL,
  `u_session_token` varchar(100) DEFAULT NULL,
  `u_last_login` datetime DEFAULT NULL,
  `u_ip` varchar(100) DEFAULT NULL,
  `u_xfwdfor` varchar(100) DEFAULT NULL,
  `u_admin` int(11) NOT NULL DEFAULT '0',
  `u_provider` varchar(100) DEFAULT NULL,
  `u_fname` varchar(100) DEFAULT NULL,
  `u_lname` varchar(100) DEFAULT NULL,
  `u_gender` varchar(30) DEFAULT NULL,
  `u_birthday` varchar(10) DEFAULT NULL,
  `u_email` varchar(100) DEFAULT NULL,
  `u_username` varchar(100) DEFAULT NULL,
  `u_url` varchar(100) DEFAULT NULL,
  `u_phone` varchar(20) DEFAULT NULL,
  `u_photo` varchar(100) DEFAULT NULL,
  `u_business` varchar(100) DEFAULT NULL,
  `u_address` varchar(100) DEFAULT NULL,
  `u_suburb` varchar(100) DEFAULT NULL,
  `u_province` varchar(100) DEFAULT '',
  `u_country` varchar(100) DEFAULT NULL,
  `u_postalcode` varchar(100) DEFAULT NULL,
  `u_occupation` varchar(100) DEFAULT NULL,
  `u_income` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`u_id`),
  UNIQUE KEY `u_identifier` (`u_identifier`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Users table not created.</p>";
		}

		$sql = "CREATE TABLE IF NOT EXISTS `options` (
  `o_id` int(11) NOT NULL auto_increment,
  `o_key` varchar(100) NOT NULL,
  `o_value` varchar(255) default NULL,
  PRIMARY KEY  (`o_id`),
  UNIQUE KEY `o_key` (`o_key`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;";

		if (!$this->dbconn->query($sql)) {
			echo "<p>Options table not created.</p>";
		} else {
			$this->version = '4.2';
			$sql = "select o_value from options where o_key = 'version'";
			$result = $this->dbconn->query($sql);
			$count = $result->num_rows;
			$row = $result->fetch_array();
			if ($count == 1) {
				$this->oldversion = $row['o_value'];
				// if older version, upgrade database
				if ($this->version <> $this->oldversion) {
					// echo "<p>Upgrading from $this->oldversion to $this->version</p>";
					// $sql = "update options set o_value = \"$this->version\" where o_key = 'version'";
					// $this->dbconn->query($sql);
					// alter statements
                    $msql = array(
                      "update options set o_value = :version where o_key = 'version'",
                      "drop table customers",
                      "drop table views",
                      "alter table archives modify column a_datecreated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                      "alter table forwards modify column f_when datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                      "alter table messages drop column m_interests",
                      "alter table queue modify column q_dateadded datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, modify column q_datelasttried datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
                      "alter table sendlog modify column sl_datesent datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                      "alter table smopens modify column smo_dateopened datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                      "alter table smsent modify column sms_datesent datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                      "alter table subscribers modify column s_subscribedate datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, modify column s_confirmdate datetime NOT NULL DEFAULT CURRENT_TIMESTAMP"
                    );
                    $args = array(
                      ':version' => $this->version
                    );
                    $this->dbPDO->exec($msql,$args);
				}
			} else {
				$sql = "INSERT INTO `options` (`o_key`, `o_value`) VALUES('version', \"$this->version\")";
				$this->dbconn->query($sql);
			}
		}
    $fat->set('version',$this->version);
  }

  function __destruct() {
    // $this->dbconn->close();
  }
}
?>