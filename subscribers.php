<?php
/*

Module: subscribers class
Version: 1.2
Author: Richard Catto
Creation Date: 2009-07-25
Milestones:

Description:
This class handles all the subscriber related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

*/

class subscribers {

	protected $fat;
	protected $user;
	protected $dbconn;
    protected $dbPDO;
	protected $globaldb;
	protected $BaseURL;

	protected $API;
	protected $Domain;
	protected $ListName;
	protected $FromAddress;
	protected $SubscriptionConfirmAmount;
	protected $SubscriptionConfirmLevel;

	// subscriber variables - must be public for forms
	public $VALIDATE_EMAIL = true;
	public $sock;
	public $reply;
	
	public $save_error; // text string of error encountered when saving a subscriber
	public $save_result; // text string of result of save
	public $create_error;
	public $update_error;

 	public $sid;
	public $s_u_id; // profile user id
	public $suid;
	public $semail;
	public $semailuser;
	public $semaildomain;
	public $spriority;
	public $ssubscribedate;
	public $sconfirm;
	public $sconfirmdate;
	public $sunsubscribe;
	public $sunsubscribedate = "0000-00-00 00:00:00";
    public $sunsubscribereason;
    public $sunsubscribebounce;
    public $sunsubscribeadmin;
	public $sbounces;
	public $semailsleft;
	public $sfname;
	public $slname;
	public $sprovince;
	public $scountry;
	public $sgender;

	public $sglobalunsubscribe;
	
	// from regular-expressions.info to find a valid email address
	public $find_email_preg = "/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,6})\b/i";
	
	// $dbconn is a mysqli connection object to an open MySQL database
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
        $this->dbPDO = $fat->get('dbPDO');
		$this->BaseURL = $fat->get('BaseURL');

		$this->API = $fat->get('API');
		$this->Domain = $fat->get('Domain');
		$this->ListName = $fat->get('ListName');
		$this->FromAddress = $fat->get('FromAddress');
		$this->SubscriptionConfirmAmount = $fat->get('SubscriptionConfirmAmount');
		$this->SubscriptionConfirmLevel = $fat->get('SubscriptionConfirmLevel');

		$gdbhost = $fat->get('gdbhost');
		$gdbuser = $fat->get('gdbuser');
		$gdbpass = $fat->get('gdbpass');
		$gdbname = $fat->get('gdbname');

		// Connect to globalfilter database to globally unsubscribe subscribers
		$this->globaldb = new mysqli($gdbhost,$gdbuser,$gdbpass,$gdbname);
		if ($this->globaldb->connect_error) {
			die('Connect Error (' . $this->globaldb->connect_errno . ') ' . $this->globaldb->connect_error);
		}
		$this->Reset();
	}

	function __destruct() {
		$this->globaldb->close();
	}
	
	// temp function - splits subscriber email into user and domain
	public function GUFix() {
		// $sql1 = "SELECT gu_id, gu_email FROM globalunsubscribe WHERE gu_email_user = \"\" ORDER BY gu_email";
		$sql1 = "SELECT s_id, s_email FROM subscribers WHERE s_email_user = \"\" ORDER BY s_email";
		$result = $this->dbconn->query($sql1);
		$num_rows = $result->num_rows;
		// echo "<p>num_rows: $num_rows</p>";
		while ($row = $result->fetch_array()) {
			$sid = $row['s_id'];
			$semail = $row['s_email'];
			// echo "<p>gu_id: $guid</p>";
			// echo "<p>gu_email: $guemail</p>";
			$email_user = $this->GetEmailUser($semail);
			// echo "<p>email_user: $email_user</p>";
			$email_domain = $this->GetEmailDomain($semail);
			// echo "<p>email_domain: $email_domain</p>";
			// $sql2 = "update globalunsubscribe set gu_email_user = \"$email_user\", gu_email_domain = \"$email_domain\" where gu_id = \"$guid\"";
			$sql2 = "update subscribers set s_email_user = \"$email_user\", s_email_domain = \"$email_domain\" where s_id = \"$sid\"";
			$success = $this->dbconn->query($sql2);
			set_time_limit(0);
		}
		$result->close();
	}

/*
  `gu_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gu_email` varchar(100) NOT NULL,
  `gu_type` enum('BOUNCE','BOUNCE-ADMIN','INVALID','SPAM','SPAM-ADMIN','USER') NOT NULL,
  `gu_active` tinyint(1) NOT NULL DEFAULT '1',
*/
	public function CheckGU() {
		set_time_limit(0);
		$invalidchanged = 0;
		$fp1 = fopen("checkgu.txt", "a");
		$sql1 = "SELECT gu_id, gu_email, gu_type FROM globalunsubscribe WHERE gu_active = \"1\" ORDER BY gu_email";
		$result = $this->globaldb->query($sql1);
		$num_rows = $result->num_rows;
		while ($row = $result->fetch_array()) {
			$guid = $row['gu_id'];
			$guemail = $row['gu_email'];
			$gutype = $row['gu_type'];
			
			$cansendto = $this->FixEmail($guemail);
			
			// change email status to inactive since it can be sent to
			if ($cansendto) {
				if (($gutype == "BOUNCE") || ($gutype == "BOUNCE-ADMIN") || ($gutype == "INVALID")) {
					$sql1 = "update globalsubscribe set gu_email = \"$this-semail\", gu_active = \"0\" WHERE gu_id = \"$guid\"";
					$success = $this->globaldb->query($sql1);
					if ($success) {
						$bdate = date("Y-m-d H:i:s");
						fwrite($fp1, $bdate . "$gu_type $guemail -> $this-semail INACTIVE\n" );
						$invalidchanged++;
					}
				}
			}
			set_time_limit(0);
		}
		$result->close();
		fwrite($fp1, "updated: $invalidchanged\n");
		fclose($fp1);
	}
	
	// function to recheck that all active non-existent domains are actually still non-existent
	public function CheckGDU() {
        set_time_limit(0);
		$fp1 = fopen("checkgdu.txt", "a"); 
		$bdate = date("Y-m-d H:i:s");
		fwrite($fp1, "--------------\n");
		fwrite($fp1, $bdate . "\n");
		fwrite($fp1, "--------------\n");

		// $sql = "SELECT gdu_id, gdu_domain_name, gdu_type FROM globaldomainunsubscribe WHERE (gdu_type = 'NOTEXIST') and (gdu_active = '1') ORDER BY gdu_domain_name";
		$sql = "SELECT gdu_id, gdu_domain_name, gdu_type FROM globaldomainunsubscribe WHERE (gdu_type = 'NOTEXIST') and (gdu_active = '1') ORDER BY gdu_id DESC";
		$result = $this->globaldb->query($sql);
		$num_rows = $result->num_rows;
		fwrite($fp1, "Domains to process: $num_rows\n");
		
		$notexistchanged = 0;

		while ($row = $result->fetch_array()) {
			$gduid = $row['gdu_id'];
			$gdudomainname = $row['gdu_domain_name'];
			$gdutype = $row['gdu_type'];
			
			// Check that MX records exist for the domain;
			$fqdn = $gdudomainname . '.';
			$cansendto = checkdnsrr($fqdn);

			fwrite($fp1, "$gdudomainname " . ($cansendto ? "YES - " : "NO - "));

			// change domain status to inactive since it now exists
		    if ($cansendto) {
              $sql1 = "update globaldomainunsubscribe set gdu_active = \"0\" WHERE gdu_id = \"$gduid\"";
              $success = $this->globaldb->query($sql1);
              if ($success) {
                fwrite($fp1, "MARKED INACTIVE");
                $notexistchanged++;
              } else {
                fwrite($fp1, "FAILED TO MARK INACTIVE");
              }
            } else {
              fwrite($fp1, "NOTEXIST --> NO CHANGE");
            }
				
			fwrite($fp1, "\n");

			set_time_limit(0);
		}
		$result->close();

		fwrite($fp1, "not exist updated: $notexistchanged\n\n");
		fclose($fp1);
	}

	// UPDATE subscribers SET s_unsubscribe =  "1", s_unsubscribedate = NOW( ), s_unsubscribereason =  "low priority", s_unsubscribe_admin =  "1" WHERE s_priority <  "45"
	public function PrioritiseSubscribers($priority = 45) {
		echo "<p>Prioritising subscribers</p>";
		$total = 0;
		$sql_update[0] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '\.za$') and (s_priority < \"$priority\")";
		$sql_update[1] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '\.za\.net$') and (s_priority < \"$priority\")";
		$sql_update[2] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@za\.') and (s_priority < \"$priority\")";
		$sql_update[3] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@gmail\.com$') and (s_priority < \"$priority\")";
		$sql_update[4] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@telkomsa\.net$') and (s_priority < \"$priority\")";
		$sql_update[5] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@iafrica\.com$') and (s_priority < \"$priority\")";
		$sql_update[6] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@mweb\.com$') and (s_priority < \"$priority\")";
		$sql_update[7] = "update subscribers set s_priority = \"$priority\" where (s_email regexp '@lantic\.net$') and (s_priority < \"$priority\")";
		$sql_update[8] = "update subscribers set s_unsubscribe = \"1\", s_unsubscribedate = now(), s_unsubscribereason = \"low priority\", s_unsubscribe_admin = \"1\" WHERE s_priority < \"$priority\"";
		
		foreach ($sql_update as $sql) {
			set_time_limit(0);
			$success = $this->dbconn->query($sql);
			$num_affected = $this->dbconn->affected_rows;
			$total += $num_affected;
			echo "<p>$sql $num_affected</p>";
		}
		echo "<p>Total prioritised: $total</p>";
	}

	public function SyncSubscribers() {
		set_time_limit(0);
		// $db_server = $this->fat->get('dbservers');
        $dbservers = $this->fat->get('dbservers');
		// $num_db_servers = count($db_server);
        $num_db_servers = count($dbservers);
		$total_added = 0;
		$this->VALIDATE_EMAIL = false;
		// echo "<p>DB servers: $num_db_servers</p>";
		$fp1 = fopen("sync.txt", "a"); 
		$bdate = date("Y-m-d H:i:s");
		fwrite($fp1, "--------------\n");
		fwrite($fp1, $bdate . "\n");
		fwrite($fp1, "--------------\n");
		// for ($i = 0; $i < $num_db_servers; $i++) {
        foreach ($dbservers as $db_server) {
			// if (($db_server[$i]['active'] == 1) && ($this->Domain <> $db_server[$i]['domain'])) {
            if (($db_server['active'] == 1) && ($this->Domain <> $db_server['domain'])) {
				// $domain = $db_server[$i]['domain'];
                $domain = $db_server['domain'];
				fwrite($fp1, "Domain: $domain\n");
				$domain_total = 0;
				// connect to database
				// $mysqli = new mysqli($db_server[$i]['host'],$db_server[$i]['user'],$db_server[$i]['pass'],$db_server[$i]['name']);
                $mysqli = new mysqli($db_server['host'],$db_server['user'],$db_server['pass'],$db_server['name']);
				if ($mysqli->connect_error) {
					die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
				}
				// $sql = "select s_email, s_priority from subscribers ORDER BY s_priority DESC, s_email ASC";
  				$sql = "select s_email, s_priority from subscribers WHERE s_priority > '0' ORDER BY s_priority DESC, s_email ASC";
				$result = $mysqli->query($sql);
				while ($row = $result->fetch_array()) {
					set_time_limit(0);
					$semail = trim(strtolower($row["s_email"]));
					$spriority = $row["s_priority"];
					// if ($semail == '') continue; // goes back to the top of the loop - does not add blank email addresses
					$subscribed = $this->SimpleSubscribeAndConfirm($semail,$spriority);
					if ($subscribed) {
						fwrite($fp1, "$this->semail\n");
						$total_added++;
						$domain_total++;
					}
					set_time_limit(0);
				}
				$result->close();
				$mysqli->close();
		-		fwrite($fp1, "Domain total subscribed: $domain_total\n");
			}
		}
		$this->VALIDATE_EMAIL = true;
		fwrite($fp1, "--------------\n");
-		fwrite($fp1, "Total subscribed: $total_added\n");
		fclose($fp1);
		return $total_added;
	}

	// Each subscriber gets assigned a unique random md5 hash ID
	// which is used to identify them in lieu of their email address for security purposes.
	protected function CreateSUID() {
		$suid = md5($this->semail);
		$result = $this->dbconn->query("select * from subscribers where s_uniqid = \"$suid\"");
		while ($result->num_rows > 0) {
			$result->close();
			$suid = md5($this->semail . uniqid('',true) .  mt_rand());
			$result = $this->dbconn->query("select * from subscribers where s_uniqid = \"$suid\"");
		}
		$result->close();
		return $suid;
	}

	// Additional data to capture when subscribing:
	// $_SERVER['HTTP_REFERER'],
	// $_SERVER['HTTP_USER_AGENT'],
	// $_SERVER['REMOTE_HOST'],
	// $_SERVER['REMOTE_ADDR']

	// Displays a paginated table of subscribers with related info in columns
	// Credit: Tony Marston for his excellent tutorial on pagination
	// http://www.tonymarston.net/php-mysql/pagination.html
	public function CreateSubscribersHTMLList($ssemail,$pageno,$numrows) {
		$html = "";
		if ($this->user->uadmin == '1') {
			$regularreaders = $this->NumberOfRegularReaders();
			$html .= "<p>Regular readers: {$regularreaders}</p>";
			// $html .= "<p><a href=\"{$this->BaseURL}check-subscribers\">Check Subscribers</a></p>";
			// $html .= "<p><a href=\"{$this->BaseURL}prioritise-subscribers\">Prioritise Subscribers</a></p>";
			$html .= "<form name=\"subscribersform\" action=\"{$this->BaseURL}subscribers\" method=\"get\" class=\"form-inline\" role=\"form\">";
			$html .= "<fieldset><legend>Search by email address</legend>";
			$html .= "<div class=\"form-group\"><div class=\"col-md-3\"><input class=\"form-control\" name=\"e\" type=\"search\" maxlength=\"255\" value=\"$ssemail\"></div></div>";
			$html .= "<div class=\"form-group\"><button class=\"btn btn-primary\" type=\"submit\">Email Search</button></div>";
			$html .= "</fieldset></form><hr>";
		} else {
			$html .= "<p>Access denied</p>";
			return $html;
		}

		$sql = "select count(*) from subscribers where s_email like \"%$ssemail%\"";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>No subscribers match that search.</p>";
			return $html;
		}

		$lastpage = ceil($totalmatches/$numrows);
		
		$pageno = (int)$pageno;
		if ($pageno > $lastpage) {
			$pageno = $lastpage;
		} elseif ($pageno < 1) {
			$pageno = 1;
		}
		
		$limit = "limit " . ($pageno - 1) * $numrows . ",$numrows";
		$sql = "SELECT s_id, s_email, s_uniqid, s_subscribedate, s_unsubscribe, s_confirm, (select count(*) from smsent where sms_s_id = s_id) as s_messages, (select count(distinct smo_m_id) from smopens where smo_s_uniqid = s_uniqid) as s_opened, s_bounces, s_emailsleft, s_priority, (select concat_ws(' ',m_subject,smo_dateopened) from smopens join messages on (m_id = smo_m_id) where smo_s_uniqid = s_uniqid order by smo_m_id desc limit 1) as s_lastmsg FROM subscribers where s_email like \"%$ssemail%\" order by s_unsubscribe ASC, s_priority DESC, s_email ASC $limit";
		$result = $this->dbconn->query($sql);

		$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Email</th><th>Subscription Date</th><th>Unsubscribed?</th><th>Confirmed?</th><th>Msgs sent</th><th>Msgs Opened</th><th>Bounces</th><th>Priority</th><th>Emails left</th><th>Last Msg Opened</th></tr></thead><tbody>";

		while ($row = $result->fetch_array()) {
			$sid = $row["s_id"];
			$suid = $row["s_uniqid"];
			$semail = $row["s_email"];
			$slemail = "<a href=\"{$this->BaseURL}subscribe/{$suid}\">{$semail}</a>";
			$ssubscribedate = $row['s_subscribedate'];
			$sunsubscribe = ($row["s_unsubscribe"] == "1" ? "Yes" : "<a href=\"{$this->BaseURL}unsubscribe/{$suid}\">unsubscribe</a>");
			$sconfirm = ($row["s_confirm"] == "1" ? "Yes" : "<a href=\"{$this->BaseURL}confirm/{$suid}\">confirm</a>");
			$smessages = $row["s_messages"]; // TODO make this clickable to allow the administrator to see which messages have been sent to this subscriber
			$sopened = $row["s_opened"]; // TODO make this clickable to see which messages were opened (read)
			$sbounces = $row["s_bounces"];
			$spriority = $row["s_priority"];
			$semailsleft = $row['s_emailsleft'];
			$slastmsg = (is_null($row["s_lastmsg"]) ? "&nbsp;-&nbsp;" : $row["s_lastmsg"]);
			$html .= "<tr><td>$slemail</td><td>$ssubscribedate</td><td>$sunsubscribe</td><td>$sconfirm</td><td>$smessages</td><td>$sopened</td><td>$sbounces</td><td>$spriority</td><td>$semailsleft</td><td>$slastmsg</td></tr>";
		}

		$html .= "</tbody></table>";
		
		$result->close();

		$html .= "<p>Page $pageno of $lastpage - $totalmatches records found</p>";

		// add in navigation to browse large results lists.
		$html .= "<ul class=\"pager\">";
		$qsemail = urlencode($ssemail);
		$querystring = ($ssemail == "") ? "" : "{$qsemail}";
		if ($pageno == 1) {
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">First</li>";
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">Previous</li>";
		} else {
		  $prevpage = $pageno - 1;
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}subscribers/1/{$querystring}\">First</a></li>";
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}subscribers/{$prevpage}/{$querystring}\">Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}subscribers/{$lastpage}/{$querystring}\">Last</a></li>";
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}subscribers/{$nextpage}/{$querystring}\">Next</a></li>";
		}
		$html .= "</ul>";
		return $html;
	}

	public function CreateSubscriberHTMLform($suid = "") {
		$html = "";
		if ($suid <> "") { // edit an existing subscriber
			if (!$this->Read($suid)) { // subscriber does not exist
				$html .= "<p>The subscriber does not exist.</p>";
				return $html;
			}
			$email_disabled = " readonly"; // disable the input for email of existing subscriber
		} else {
			$email_disabled = " required"; // require email to be input because new subscriber
		}

		$hh = $this->fat->get('htmlhelper');

		$html .= "<form name=\"subscribeform\" action=\"{$this->BaseURL}subscribe\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<input name=\"suid\" type=\"hidden\" value=\"{$suid}\">";
		$html .= "<input name=\"spriority\" type=\"hidden\" value=\"{$this->spriority}\">";
		$html .= "<fieldset><legend>Subscriber Information</legend>";

		$input_text = array(
			0 => array("semail","Email","email",$this->semail,$email_disabled,true),
			1 => array("spriority","Priority","number",$this->spriority,"",$this->user->uadmin == '1' ? true : false),
			2 => array("sfname","First Name","text",$this->sfname,"",true),
			3 => array("slname","Last Name","text",$this->slname,"",true)
		);

		foreach($input_text as $icontrol) {
			if ($icontrol[5]) {
				$html .= "<div class=\"form-group\">";
				$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
				$html .= "<div class=\"col-md-3\">";
				$html .= "<input class=\"form-control\" type=\"{$icontrol[2]}\" name=\"{$icontrol[0]}\" value=\"{$icontrol[3]}\"{$icontrol[4]}>";
				$html .= "</div></div>";
			}
		}

		$select_text = array(
			0 => array("sprovince","Province",$hh->CreateProvinceHTMLDropDown($this->sprovince)),
			1 => array("scountry","Country",$hh->CreateCountryHTMLDropDown($this->scountry)),
			2 => array("sgender","Gender",$hh->CreateGenderHTMLDropDown($this->sgender))
		);

		foreach($select_text as $scontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$scontrol[0]}\">{$scontrol[1]}</label>";
			$html .= "<div class=\"col-md-3\">";
			$html .= "<select class=\"form-control\" name=\"{$scontrol[0]}\">{$scontrol[2]}</select>";
			$html .= "</div></div>";
		}

		if (!$this->user->uloggedin) {
          $html .= "<div class=\"form-group\">";
          $html .= "<label class=\"col-md-1 control-label\" for=\"captcha\">Captcha Code</label>";
          $html .= "<div class=\"col-md-3\">";
          $html .= "<img src=\"/captcha\" title=\"refresh page for new captcha image. does not timeout.\" alt=\"captcha\"/>";
          $html .= "<input class=\"form-control\" type=\"text\" name=\"captcha\"/>";
		  $html .= "</div></div>";
		} 

		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-3\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Subscribe</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}

	// BulkSubscribe from a file
	public function BulkSubscribe($fname,$spriority = 0) {
		$fcontents = file($fname);
		$this->VALIDATE_EMAIL = false;
		$this->BulkSubscribeForm($fcontents,$spriority,false);
		$this->VALIDATE_EMAIL = true;
	}

	// bulk subscribes emails entered into a textarea, each on a separate line
	// uses regular expressions to extract email addresses from free form text
	public function BulkSubscribeForm($bemail,$spriority = 0,$prt_debug = true) {
		$html = "";
		set_time_limit(0);
		if (is_array($bemail)) {
			$fcontents = $bemail;
		} else {
			$txt = preg_replace('/\r\n|\r/', "\n", $bemail);
			$fcontents = explode("\n",trim($txt));
		}
		$num = 0;
		// Open the file and append to end of file
		$fp1 = fopen("emails_added.txt", "a"); 
		$fp2 = fopen("emails_rejected.txt", "a"); 
		// Write the data to the file
		$bdate = date("Y-m-d H:i:s");
		fwrite($fp1, "--------------\n");
		fwrite($fp1, $bdate . "\n");
		fwrite($fp2, "--------------\n");
		fwrite($fp2, $bdate . "\n");
		for($i = 0; $i < sizeof($fcontents); $i++) {
			$semail = strtolower(trim($fcontents[$i]));
			// Extract all the email addresses in $semail into an array
			$EmailAddresses = $this->find_email_addresses($semail);
			if (is_array($EmailAddresses)) {
				foreach ($EmailAddresses as $email) {
					$subscribed = $this->SimpleSubscribeAndConfirm($email,$spriority);
					if ($subscribed) {
						if ($prt_debug) $html .= "<p>$this->semail</p>";
						fwrite($fp1, "$this->semail\n");
						$num++;
					} else {
						if ($prt_debug) $html .= "<p>NS $semail --> $this->semail $this->reply</p>";
					}
				}
			} else { // did not find at least one valid email address
				fwrite($fp2, "$semail\n");
			}
			// script is allowed to run for 10 mins
			set_time_limit(0);
		}
		if ($prt_debug) $html .= "<p>Number subscribed: $num</p>";
		fwrite($fp1, "Number subscribed: $num\n");
		fclose($fp1);
		fclose($fp2);
		return $html;
	}

	// bulk unsubscribes emails entered into a textarea, each on a separate line
	public function BulkUnsubscribeForm($bemail,$bounce = "") {
		$html = "";
		set_time_limit(0);
		if ($bounce == "b") {
			$gutype = 'BOUNCE-ADMIN';
			$html .= "<p>BOUNCE-ADMIN</p>";	
			$globalunsub = true;
		} elseif ($bounce == "s") {
			$gutype = 'SPAM-ADMIN';
			$html .= "<p>SPAM-ADMIN</p>";	
			$globalunsub = true;
		} elseif ($bounce == "d") {
			$gutype = '';
			$globalunsub = true;
		} else {
			$gutype = '';
			$globalunsub = false;
		}
		$reason = "bulk unsubscribed";
		$txt = preg_replace('/\r\n|\r/', "\n", $bemail);
		$fcontents = explode("\n",trim($txt));
		$num = 0;
		for($i = 0; $i < sizeof($fcontents); $i++) {
			$semail = strtolower(trim($fcontents[$i]));
			// Extract all the email addresses in $semail into an array
			$EmailAddresses = $this->find_email_addresses($semail);
			if (is_array($EmailAddresses)) {
				foreach ($EmailAddresses as $email) {
					$email_user = $this->GetEmailUser($email);
					$email_domain = $this->GetEmailDomain($email);
					if ($email <> '') {
						if ($globalunsub) {
							if ($bounce == "d") {
								$success = $this->SaveGlobalDomainUnsubscribe($email_domain,"SPAM");
								if ($success) {
									$html .= "<p>GDU $email_domain</p>";
									$num++;
								} else {
									$html .= "<p>FFF $email_domain</p>";
									$html .= "<p>" . $this->save_error . "</p>";
								}
							} elseif ($email <> '') {
								$success = $this->SaveGlobalUnsubscribe($email,$email_user,$email_domain,$gutype,$reason);
								if ($success) {
									$html .= "<p>GU $email</p>";
									$num++;
								} else {
									$html .= "<p>FF $email</p>";
									$html .= "<p>" . $this->save_error . "</p>";
								}
							}
						} else {
							$added = $this->SimpleSubscribeAndConfirm($email);
							$this->sunsubscribe = 1;
							$this->sunsubscribedate = date("Y-m-d H:i:s");
							$this->sunsubscribereason = $reason;
							$updated = $this->Update();
							if ($added && $updated) {
								$html .= "<p>AU ";
								$num++;
							} else {
								$html .= "<p>FU ";
							}
							$html .= "$email --> $this->semail</p>";
						}
					}
				}
			}
			// script is allowed to run forever
			set_time_limit(0);
		}
		$html .= "<p>Number unsubscribed: $num</p>";
		return $html;
	}

	// This finds all email addresses
	function find_email_addresses($semail) {
		if (preg_match_all($this->find_email_preg,$semail,$matches)) {
			return $matches[1];
		}	else {
			return "";
		}
	}

	// checks if an email address is subscribed. returns true if subscribed and sets $this->suid and $this->spriority else returns false
	public function IsSubscribed($semail) {
		$subscribed = false;
		// check if email address is subscribed
		$sql ="select s_uniqid, s_priority from subscribers where s_email = \"$semail\"";
		$result = $this->dbconn->query($sql);
		if ($result->num_rows == 1) {
			// email address is subscribed. return true
			$row = $result->fetch_array();
			$this->suid = $row["s_uniqid"];
			$this->spriority = $row["s_priority"];
			$subscribed = true;
		}
		$result->close();
		return $subscribed;
	}

	// this is a quick function to subscribe and confirm a single email address
	// returns true if address was added else false. $this->suid contains the suid
	// updates the subscriber priority to the higher value if suppplied priority is greater
	public function SimpleSubscribeAndConfirm($semail,$spriority = 0) {
		$semail = strtolower(trim($semail));
		if ($semail == '') return false;

		$this->Reset(); // reset subscriber variables
		$subscribed = $this->IsSubscribed($semail);
		$this->semail = $semail;

		if ($subscribed) {
			if ($spriority > $this->spriority) { // priority is set in IsSubscribed
				$cansendto = $this->Read($this->suid);
				if ($cansendto) {
					$this->spriority = max($spriority,$this->spriority);
					$this->Update();
				}
			}
		} else {
			$this->spriority = max($spriority,$this->spriority);
			return $this->Create();
		}
		return false;
	}

  // saves subscriber to mysql. returns true if successful, false if not. $this->save_error contains the text error msg
  public function save() {
    $this->semail = $this->dbconn->real_escape_string(strtolower(trim($this->semail)));
    if ($this->semail == "") {
      $this->save_error = "email is blank";
      return false;
    }

    $this->save_error = "";
    $this->save_result = "";

    $this->suid = $this->dbconn->real_escape_string(trim($this->suid));
    $this->spriority = $this->dbconn->real_escape_string($this->spriority);
    $this->sfname = $this->dbconn->real_escape_string(trim($this->sfname));
    $this->slname = $this->dbconn->real_escape_string(trim($this->slname));
    $this->sprovince = $this->dbconn->real_escape_string($this->sprovince);
    $this->scountry = $this->dbconn->real_escape_string($this->scountry);
    $this->sgender = $this->dbconn->real_escape_string($this->sgender);

    // Checks if the email address is already in our database
    // $old_suid = $this->suid;
    $spriority = $this->spriority;
    $subscribed = $this->IsSubscribed($this->semail);
    $this->spriority = $spriority;

    if ($subscribed) {
      $success = $this->UpdateSubscriber();
      if ($success) {
        $this->save_result = "$this->semail subscriber info updated.";
        return true;
      } else {
        $this->save_result = "$this->semail subscriber info NOT updated due to an error.";
        $this->save_error = $this->update_error;
        return false;
      }
    } else {
      $success = $this->Subscribe();
      if ($success) {
        $this->save_result = "$this->semail has been subscribed to " . $this->ListName;
        return true;
      } else {
        $this->save_result = "$this->semail was NOT subscribed to " . $this->ListName . " due to an error.";
        $this->save_error = $this->create_error;
        return false;
      }
    }
  }
	
	// Add a new subscriber
	public function Subscribe() {
		$added = $this->Create();
		if ($added) {
			// send notification email to subscriber and admin
			$sname = $this->sfname . " " . $this->slname;
			$subject = $this->ListName . " subscription notification";
			$mhtml = "<p>{$this->semail} has been subscribed to {$this->ListName}.</p><p>Please confirm your subscription by clicking this link:<br /><a href=\"{$this->BaseURL}confirm/{$this->suid}\">CONFIRM SUBSCRIPTION</a></p>";
			$mtext = "{$this->semail} has been subscribed to {$this->ListName}.\nPlease confirm your subscription by clicking this link:\n{$this->BaseURL}confirm/{$this->suid}";
			$mailer = $this->fat->get('mailer');
			$mailer->OpenSMTP();
			$mailer->SendMessage($this->suid,"0","S",$this->FromAddress,$this->semail,$sname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
			$mailer->CloseSMTP();
		}
		// returns whether the subscription succeeded or not
		return $added;
	}

	public function UpdateSubscriber() {
		$this->sunsubscribe = 0;
        $this->sunsubscribedate = NULL;
		$this->sbounces = 0;
		$this->semailsleft = $this->SubscriptionConfirmAmount;
		$this->spriority = $this->spriority + 10;

		$updated = $this->Update();
		if ($updated) {
			// send subscriber update notification email to subscriber and admin
			$sname = $this->sfname . " " . $this->slname;
			$subject = $this->ListName . " subscriber info updated";
			$mhtml = "<p>Subscriber info for {$this->semail} on {$this->ListName} has been updated.</p><p><a href=\"{$this->BaseURL}subscribe/{$this->suid}\">UPDATE YOUR PROFILE</a></p>";
			$mtext = "Subscriber info for {$this->semail} on {$this->ListName} has been updated.\nUpdate your profile: {$this->BaseURL}subscribe/{$this->suid}";
			$mailer = $this->fat->get('mailer');
			$mailer->OpenSMTP();
			$mailer->SendMessage($this->suid,"0","P",$this->FromAddress,$this->semail,$sname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
			$mailer->CloseSMTP();
		}
		return $updated;
	}

	// Bulk subscribes without confirmation, sends email to each email with confirmation link
	public function ClaimEmail($bemail,$uid) {
		$html = "";
		$txt = preg_replace('/\r\n|\r/', "\n", $bemail);
		$fcontents = explode("\n",trim($txt));
		$num = 0;
		for($i = 0; $i < sizeof($fcontents); $i++) {
			$email = strtolower(trim($fcontents[$i]));
			$EmailAddresses = $this->find_email_addresses($email);
			if (is_array($EmailAddresses)) {
				foreach ($EmailAddresses as $semail) {
					$html .= "<p>$semail ";
					$added = $this->SimpleSubscribeAndConfirm($semail);
					if ($added) {
						$html .= "subscribed and claimed: ADDED</p>";
						$num++;
					}
					if ($this->suid <> '') {
						// subscriber exists
						if ($this->s_u_id == 0) {
							// send a confirmation email
							$subject = $this->ListName . " subscription notification";
							$mhtml = "<p>{$this->semail} has been claimed by {$uid} on {$this->ListName}.</p><p>Please confirm your claim by clicking this link:<br /><a href=\"{$this->BaseURL}confirm/{$this->suid}/{$uid}\">CONFIRM SUBSCRIPTION</a></p>";
							$mtext = "{$this->semail} has been claimed by {$uid} on {$this->ListName}.\nPlease confirm your claim by clicking this link:\n{$this->BaseURL}confirm/{$this->suid}/{$uid}";
							$mailer = $this->fat->get('mailer');
							$mailer->OpenSMTP();
							$mailer->SendMessage($this->suid,"0","K",$this->FromAddress,$this->semail,'',$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
							$mailer->CloseSMTP();
							$num++;
						} elseif ($this->s_u_id == $uid) {
							// email is already claimed by user
							$html .= " is already claimed by you</p>";
						} else {
							// email is already claimed by someone else
							$html .= " is already claimed by someone else</p>";
						}
					}
				}
			}
		}
		$html .= "<p>Number claimed: $num</p>";
		return $html;
	}
	
	public function UnClaimEmail($suid,$uid) {
		$exists = $this->Read($suid);
		if ($exists) {
			$this->s_u_id = 0;
			$this->Update();
			return $this->semail;
		}
		return '';
	}
	
	public function ConfirmSubscription($suid,$uid = 0) {
		$html = "";
		$exists = $this->Read($suid);
		if ($exists) {
			$html .= "<p>Subscriber exists</p>";
			$this->s_u_id = $uid;
			$this->sconfirm = 1;
			$this->sconfirmdate = date("Y-m-d H:i:s");
			$this->sbounces = 0;
			$this->spriority = $this->spriority + 10;
			$this->semailsleft = $this->SubscriptionConfirmAmount;
			$updated = $this->Update();
			if ($updated) {
				$html .= "<p>Subscriber updated: $this->semail</p>";
				// confirmation suceeded - send notification email to subscriber and admin
				$sname = "{$this->sfname} {$this->slname}";
				$subject = "{$this->ListName} confirmation of subscription notification";
				$mhtml = "<p>{$this->semail} has confirmed their subscription to {$this->ListName}.</p>";
				$mtext = "{$this->semail} has confirmed their subscription to {$this->ListName}.\n";
				$mailer = $this->fat->get('mailer');
				$mailer->OpenSMTP();
				$mailer->SendMessage($suid,"0","C",$this->FromAddress,$this->semail,$sname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
				$mailer->CloseSMTP();
				$html .= "<p>{$this->semail} has confirmed their subscription to {$this->ListName}.</p>";
				return $html;
			}
		} else {
			$html .= "<p>Confirmation code is incorrect.</p>";
		}
		return $html;
	}

	public function CreateBulkSubscribeHTMLform() {
		$html = "";
		if ($this->user->uadmin <> '1') {
			$html = "<p>Access denied</p>";
			return $html;
		}

		$html .= "<form name=\"bsubscribeform\" action=\"{$this->BaseURL}bulk-subscribe\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<fieldset><legend>Subscribe multiple emails</legend>";

		$input_text = array(
			0 => array("spriority","Priority","number",100)
		);

		foreach($input_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<input class=\"form-control\" type=\"{$icontrol[2]}\" size=\"10\" name=\"{$icontrol[0]}\" value=\"{$icontrol[3]}\">";
			$html .= "</div></div>";
		}

		$textarea_text = array(
			0 => array("bemail","Emails to subscribe"),
		);

		foreach($textarea_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<textarea class=\"form-control\" rows=\"20\" cols=\"90\" name=\"{$icontrol[0]}\"></textarea>";
			$html .= "</div></div>";
		}

		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-6\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Subscribe</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}

	public function CreateBulkUnsubscribeHTMLform() {
		$html = "";
		if ($this->user->uadmin <> '1') {
			$html = "<p>Access denied</p>";
			return $html;
		}

		$html .= "<form name=\"bunsubscribeform\" action=\"{$this->BaseURL}bulk-unsubscribe\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<fieldset><legend>Unsubscribe multiple emails</legend>";

		$radio_text = array(
			0 => array("l","Remove from this list only",true),
			1 => array("d","Filter entire domains",false),
			2 => array("b","These are bounces",false),
			3 => array("s","These are spam complainers",false)
		);

		foreach($radio_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<div class=\"radio\">";
			$html .= "<label class=\"col-md-offset-1\">";
			$html .= "<div class=\"|col-md-6\">";
			$checked = $icontrol[2] ? "checked" : "";
			$html .= "<input type=\"radio\" name=\"bbounce\" value=\"{$icontrol[0]}\" {$checked}>{$icontrol[1]}";
			$html .= "</div></label>";
			$html .= "</div></div>";
		}

		$textarea_text = array(
			0 => array("bemail","Emails to unsubscribe"),
		);

		foreach($textarea_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<textarea class=\"form-control\" rows=\"20\" cols=\"90\" name=\"{$icontrol[0]}\"></textarea>";
			$html .= "</div></div>";
		}

		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-6\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Unsubscribe</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}

	public function CreateUnsubscribeHTMLform($suid) {
		$html = "";
		if (!$this->Read($suid)) {
			$html .= "<p>The subscriber has already been removed from {$this->ListName}.</p>";
			return $html;
		}
		if ($this->sunsubscribe == "1") {
			$html .= "<p>$this->semail has already been unsubscribed from {$this->ListName}.</p>";
			return $html;
		}
		$html .= "<p>Email address to be unsubscribed: <b>$this->semail</b></p>";
		$html .= "<form name=\"unsubscribeform\" action=\"{$this->BaseURL}unsubscribe\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<input name=\"suid\" type=\"hidden\" value=\"{$suid}\">";
		$html .= "<fieldset><legend>Unsubscribe form</legend>";
		$radio_text = array(
			0 => array("b","Address does not exist",false),
			1 => array("s","Stop sending me SPAM",false),
			2 => array("g","Remove me from ALL mailing lists",false),
			3 => array("o","Take me off this list only",true)
		);
		foreach($radio_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<div class=\"radio\">";
			$html .= "<label class=\"col-md-offset-1\">";
			$html .= "<div class=\"|col-md-6\">";
			$checked = $icontrol[2] ? "checked" : "";
			$html .= "<input type=\"radio\" name=\"sglobalunsubscribe\" value=\"{$icontrol[0]}\" {$checked}>{$icontrol[1]}";
			$html .= "</div></label>";
			$html .= "</div></div>";
		}
		$textarea_text = array(
			0 => array("sunsubscribereason","Reason/comments")
		);
		foreach($textarea_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<textarea class=\"form-control\" name=\"{$icontrol[0]}\"></textarea>";
			$html .= "</div></div>";
		}
		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-6\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Unsubscribe</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}
	
	// check that the unsubscribe succeeded
	public function Unsubscribe() {
		$html = "";
		if ($this->user->uadmin == "1") {
			$admin = true;
		} else {
			$admin = false;
		}
		$exists = $this->Read($this->suid);
		if (!$exists) {
			$html .= "<p>The subscriber has already been removed from {$this->ListName}.</p>";
			return $html;
		}
		if ($this->sunsubscribe == "1") {
			$html .= "<p>{$this->semail} has already been unsubscribed from {$this->ListName}.</p>";
		 	return $html;
		}
		if ($this->sglobalunsubscribe == "b") {
			$global = true;
			$subject_tag = "BOUNCE ";
			$gutype = ($admin ? 'BOUNCE-ADMIN' : 'BOUNCE');
		} elseif ($this->sglobalunsubscribe == "s") {
			$global = true;
			$subject_tag = "SPAM ";
			$gutype = ($admin ? 'SPAM-ADMIN' : 'SPAM');
		} elseif ($this->sglobalunsubscribe == "g") {
			$global = true;
			$subject_tag = "";
			$gutype = ($admin ? 'ADMIN' : 'USER');
		} else { // unsubscribe only from this list
			$global = false;
			$subject_tag = "";
			$gutype = "";
		}
		$this->sunsubscribe = 1;
		$this->sunsubscribedate = date("Y-m-d H:i:s");
		$this->sunsubscribeadmin = $admin;
		$updated = $this->Update();
		if ($updated) {
			// send notification email to unsubscriber and admin
			$sname = $this->sfname . " " . $this->slname;
			$subject = $subject_tag . $this->ListName . " unsubscription notification";
			$mhtml = "<p>{$this->semail} has been unsubscribed from {$this->ListName}.</p><p>Reason: {$this->sunsubscribereason}</p><p><a href=\"{$this->BaseURL}subscribe/{$this->suid}\">RE-SUBSCRIBE</a></p>";
			$mtext = "{$this->semail} has been unsubscribed from {$this->ListName}.\nReason: {$this->sunsubscribereason}\nRe-subscribe: {$this->BaseURL}subscribe/{$this->suid}";
			$mailer = $this->fat->get('mailer');
			$mailer->OpenSMTP();
			$mailer->SendMessage($this->suid,"0","U",$this->FromAddress,$this->semail,$sname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
			$mailer->CloseSMTP();
			$html .= "<p>{$this->semail} has been unsubscribed from {$this->ListName}.</p>";
		}
		if ($global) {
			$success = $this->SaveGlobalUnsubscribe($this->semail,$this->semailuser,$this->semaildomain,$gutype,$this->sunsubscribereason);
			if ($success) $html .= "<p>{$this->semail} was removed from ALL mailing lists.</p>";
		}
		return $html;
	}

    // priority > 45 indicates a regular reader
	public function NumberOfRegularReaders() {
		$regularreaders = 0;
		$emailsleft = $this->SubscriptionConfirmLevel;
		// $sql = "select count(*) from subscribers where (s_emailsleft > \"$emailsleft\") and (s_unsubscribe = \"0\")";
		$sql = "SELECT COUNT(*) FROM `subscribers` WHERE s_unsubscribe = '0' AND s_priority > '45'";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$regularreaders = $row[0];
		return $regularreaders;
	}
	
	public function GloballyUnsubscribed($email) {
		$sql = "select count(*) from globalunsubscribe where (gu_email = \"$email\") and (gu_active = \"1\")";
		$result = $this->globaldb->query($sql);
		$row = $result->fetch_row();
		$match = $row[0];
		return $match;
	}

	// returns ""	
	public function DomainGloballyUnsubscribed($domain) {
		$sql = "select count(*) from globaldomainunsubscribe where (gdu_domain_name = \"$domain\") and (gdu_active = \"1\")";
		$result = $this->globaldb->query($sql);
		// if ($result->num_rows == 0) return false;
		$row = $result->fetch_row();
		$match = $row[0];
		return $match;
	}

	public function GetEmailUser($email) {
		$user = preg_replace("/@.*$/","",$email);
		return $user;
	}
	
	public function GetEmailDomain($email) {
		$domain = preg_replace("/^.*@/","",$email);
		return $domain;
	}

	public function FixUser($user) {
		// terms to invalidate
		$nemail = preg_replace("/^postmaster/","@@",$user);
		$nemail = preg_replace("/^abuse/","@@",$nemail);
		// $nemail = preg_replace("/^accounts/","@@",$nemail);
		$nemail = preg_replace("/^billing/","@@",$nemail);
		$nemail = preg_replace("/^comments/","@@",$nemail);
		$nemail = preg_replace("/^media/","@@",$nemail);
		$nemail = preg_replace("/^example/","@@",$nemail);
		$nemail = preg_replace("/^jobs/","@@",$nemail);
		// $nemail = preg_replace("/^sales/","@@",$nemail);
		$nemail = preg_replace("/^support/","@@",$nemail);
		$nemail = preg_replace("/^sysadmin/","@@",$nemail);
		$nemail = preg_replace("/^sysadm/","@@",$nemail);
		$nemail = preg_replace("/^complaints/","@@",$nemail);
		$nemail = preg_replace("/^dns-admin/","@@",$nemail);
		$nemail = preg_replace("/^dns/","@@",$nemail);
		$nemail = preg_replace("/^absa/","@@",$nemail);
		$nemail = preg_replace("/^e-mail[-_\.]/","",$nemail);
		$nemail = preg_replace("/^email-list/","@@",$nemail);
		$nemail = preg_replace("/^e-mail/","",$nemail);
		$nemail = preg_replace("/^email/","",$nemail);
		$nemail = preg_replace("/^e-mai/","",$nemail);
		$nemail = preg_replace("/^letters/","@@",$nemail);
		$nemail = preg_replace("/^mail/","@@",$nemail);
		$nemail = preg_replace("/^assignments/","@@",$nemail);
		$nemail = preg_replace("/^pftfrh/","@@",$nemail);
		$nemail = preg_replace("/^listserv/","@@",$nemail);
		$nemail = preg_replace("/^listserver/","@@",$nemail);
		$nemail = preg_replace("/^webmaster/","@@",$nemail);
		
		$nemail = str_ireplace("newsroom","@@",$nemail);
		$nemail = str_ireplace("newsletter","@@",$nemail);
		$nemail = str_ireplace("request","@@",$nemail);
		$nemail = str_ireplace("mailman","@@",$nemail);
		$nemail = str_ireplace("helpdesk","@@",$nemail);
		$nemail = str_ireplace("ozemail.com","@",$nemail);
		$nemail = str_ireplace("eastyorkrifles","@@",$nemail);
		$nemail = str_ireplace("celeste.sneed","@@",$nemail);
		$nemail = str_ireplace("celestesneed","@@",$nemail);
		$nemail = str_ireplace(".co.za","@@",$nemail);
		$nemail = str_ireplace("-removethis-","",$nemail);
		$nemail = str_ireplace("-removethis","",$nemail);
		$nemail = str_ireplace("removethis-","",$nemail);
		$nemail = str_ireplace("removethis","",$nemail);
		$nemail = str_ireplace("noreply","@",$nemail);
		$nemail = str_ireplace("no_reply","@",$nemail);
		$nemail = str_ireplace("no-reply","@",$nemail);
		$nemail = str_ireplace("no.reply","@",$nemail);
		$nemail = str_ireplace("bounce","@@",$nemail);
		$nemail = str_ireplace("disclaimer@","@@",$nemail);
		$nemail = str_ireplace("listme","@@",$nemail);
		$nemail = str_ireplace("randomhouse","@@",$nemail);
		$nemail = str_ireplace("duplicate","@@",$nemail);
		$nemail = str_ireplace("majordomo","@@",$nemail);
		$nemail = str_ireplace("hostforweb","@@",$nemail);
		$nemail = str_ireplace("google","@@",$nemail);
		$nemail = str_ireplace("hostgator","@@",$nemail);	
		$nemail = str_ireplace("listmaster","@@",$nemail);
		$nemail = str_ireplace("mailer-daemon","@@",$nemail);
		$nemail = str_ireplace("samaleprostitute","@@",$nemail);		
		$nemail = str_ireplace("copyright","@",$nemail);
		$nemail = str_ireplace("callcentre","@",$nemail);
		$nemail = str_ireplace("eccmngmtescalations","@@",$nemail);
		$nemail = str_ireplace("customerservice","@",$nemail);
		$nemail = str_ireplace("customer_service","@",$nemail);
		$nemail = str_ireplace("customer-service","@",$nemail);
		$nemail = str_ireplace("customer.service","@",$nemail);
		$nemail = str_ireplace("cutomer.service","@",$nemail);
		$nemail = str_ireplace("customer-support","@",$nemail);
		$nemail = str_ireplace("customer.support","@",$nemail);
		$nemail = str_ireplace("customersupport","@",$nemail);
		$nemail = str_ireplace("customer-care","@",$nemail);
		$nemail = str_ireplace("customer.care","@",$nemail);
		$nemail = str_ireplace("customercare","@",$nemail);
		$nemail = str_ireplace("customer-","@",$nemail);
		$nemail = str_ireplace("customer.","@",$nemail);
		$nemail = str_ireplace("customer","@",$nemail);
		$nemail = str_ireplace("mailabuse","@",$nemail);
		$nemail = str_ireplace("catchall","@",$nemail);
		$nemail = str_ireplace("unsubscribe","@",$nemail);
		$nemail = str_ireplace("subscribe","@",$nemail);
		$nemail = str_ireplace("nospam","@",$nemail);
		$nemail = str_ireplace(".nospam","",$nemail);
		$nemail = str_ireplace("nospam.","",$nemail);
		$nemail = str_ireplace("nospam-","",$nemail);
		$nemail = str_ireplace("nospam","",$nemail);
		$nemail = str_ireplace("spam","@",$nemail);
		$nemail = str_ireplace("submit","@@",$nemail);
		$nemail = str_ireplace("www.","",$nemail);
		
		return $nemail;
	}
	
	public function FixDomain($domain) {
		// fix co.za misspellings
		$nemail = preg_replace("/\-co\.za/",".co.za",$domain);
		$nemail = preg_replace("/\.co\.za.+$/",".co.za",$nemail);

		$nemail = preg_replace("/\.c\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.xa$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.z[a-z]$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.z[a-z][a-z]$/",".co.za",$nemail);
		$nemail = preg_replace("/\.com\.za$/",".co.za",$domain);
		$nemail = preg_replace("/\-com\.za$/",".co.za",$domain);
		$nemail = preg_replace("/\.oc\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.oc\.za.+$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.az$/",".co.za",$domain);
		$nemail = preg_replace("/\.xo\.za$/",".co.za",$domain);
		$nemail = preg_replace("/\.net\.co\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.xco\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.c0\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.z$/",".co.za",$nemail);
		$nemail = preg_replace("/\.ca\.za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.ca\.za\.com$/",".co.za",$nemail);
		$nemail = preg_replace("/\.coza$/",".co.za",$nemail);
		$nemail = preg_replace("/\.coza.+$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\/za$/",".co.za",$nemail);
		$nemail = preg_replace("/\.co\.co\.za$/",".co.za",$nemail);

		// fix org.za misspellings
		$nemail = preg_replace("/\.org\.za.+$/",".org.za",$nemail);

		$nemail = preg_replace("/\.org\.co\.za$/",".org.za",$nemail);

		// fix ac.za misspellings
		$nemail = preg_replace("/\.ac\.za.+$/",".ac.za",$nemail);

		$nemail = preg_replace("/\.ac\.zay$/",".ac.za",$nemail);

		// fix gov.za misspellings
		$nemail = preg_replace("/\.gov\.za.+$/",".gov.za",$nemail);
		

		$nemail = preg_replace("/\.gov\.co\.za$/",".gov.za",$nemail);
		$nemail = preg_replace("/\.gv\.za$/",".gov.za",$nemail);
		$nemail = preg_replace("/\.goz\.za$/",".gov.za",$nemail);

		// fix co.uk misspellings
		$nemail = preg_replace("/\.co\.uk.+$/",".co.uk",$nemail);

		$nemail = preg_replace("/\.co\.ukco\.ukz$/",".co.uk",$nemail);
		$nemail = preg_replace("/\.com\.uk$/",".co.uk",$nemail);
		
		// fix com misspellings
		$nemail = preg_replace("/\.com.+$/",".com",$nemail);
		$nemail = preg_replace("/\.coom$/",".com",$nemail);
		$nemail = preg_replace("/\.c[a-z]m$/",".com",$nemail);
		$nemail = preg_replace("/\.co[a-z]$/",".com",$nemail);
		$nemail = preg_replace("/\.can$/",".com",$nemail);
		$nemail = preg_replace("/\.cm$/",".com",$nemail);
		$nemail = preg_replace("/\.caom$/",".com",$nemail);
		$nemail = preg_replace("/\.[a-z]om$/",".com",$nemail);
		$nemail = preg_replace("/\.doc\.com$/",".com",$nemail);
		$nemail = preg_replace("/\.co\.com$/",".com",$nemail);

		// fix org misspellings
		$nemail = preg_replace("/\.doc\.org$/",".org",$nemail);

		// fix net misspellings
		$nemail = preg_replace("/\.net.+$/",".net",$nemail);
		
		$nemail = preg_replace("/\.ne$/",".net",$nemail);
		$nemail = preg_replace("/\.n[a-z]t$/",".net",$nemail);	

		// fix biz misspellings
		$nemail = preg_replace("/\.biz.+$/",".biz",$nemail);

		$nemail = preg_replace("/\.bjz$/",".biz",$nemail);

		// fix info misspellings
		$nemail = preg_replace("/\.info.+$/",".info",$nemail);

		// fix uncommon domain misspellings
		$nemail = preg_replace("/capfspan\./","capespan.",$nemail);
		$nemail = preg_replace("/livf\./","live.",$nemail);
		$nemail = preg_replace("/codf\./","code.",$nemail);

		// fix aol misspellings
		$nemail = preg_replace("/aol\.co\.za$/","aol.com",$nemail);
		$nemail = preg_replace("/aol\.uk$/","aol.com",$nemail);
		$nemail = preg_replace("/aolc\.co\.za$/","aol.com",$nemail);
		$nemail = preg_replace("/aol\.co\.za$/","aol.com",$nemail);
		$nemail = preg_replace("/aol\.comaol\.com$/","aol.com",$nemail);

        // fix compuserv
  		$nemail = preg_replace("/compuserve/","",$nemail);

		// fix earthlink.net misspellings
		$nemail = preg_replace("/earthlink\.com$/","earthlink.net",$nemail);
		$nemail = preg_replace("/earhtlink\.net$/","earthlink.net",$nemail);
		
		// fix absamail misspellings
		$nemail = preg_replace("/^.?absamail.+$/","absamail.co.za",$nemail);

		$nemail = preg_replace("/freemail\.absa\.co\.za$/","absamail.co.za",$nemail);
		$nemail = preg_replace("/freemal\.absa\.co\.za$/","absamail.co.za",$nemail);
		$nemail = preg_replace("/absamial\.co\.za$/","absamail.co.za",$nemail);
		$nemail = preg_replace("/absameil\.co\.za$/","absamail.co.za",$nemail);
		$nemail = preg_replace("/freemail\.co\.za$/","absamail.co.za",$nemail);
		$nemail = preg_replace("/free-mail\.co\.za$/","absamail.co.za",$nemail);
		
		$nemail = str_ireplace("freemail.abasa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("fre.abasa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("freemail.bsa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("fre.bsa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("absa.freemail.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("absa.fre.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("absafreemail.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("absafre.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("abfreemail.absa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("abfre.absa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("freemail.absa.org.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("fre.absa.org.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("1freemail.absa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("1fre.absa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("abasamail.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("frggmail.absa.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("acsamail.co.za","absamail.co.za",$nemail);
		$nemail = str_ireplace("acsamail.com","absamail.co.za",$nemail);	
		
		// fix hotmail misspellings
		$nemail = preg_replace("/^.?hotmail.+$/","hotmail.com",$nemail);

		$nemail = preg_replace("/^otmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^htmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hoymail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotamil\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^homail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hormail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmai\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmia\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotnail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmil\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotamail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmial\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^gotmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hitmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hptmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmal\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmsil\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hotmeil\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^fotmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^hoptmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^holtmail\.com/","hotmail.com",$nemail);
		$nemail = preg_replace("/^notmail\.com/","hotmail.com",$nemail);

		// fix webmail misspellings
		$nemail = preg_replace("/^.?webmail.+$/","webmail.co.za",$nemail);

		$nemail = str_ireplace("webmail.com","webmail.co.za",$nemail);
		$nemail = str_ireplace("webail.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("wembail.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("webmai.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("webamil.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("webmal.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("wabmail.com","webmail.co.za",$nemail);
		$nemail = str_ireplace("wemail.co.za","webmail.co.za",$nemail);
		$nemail = str_ireplace("wcbmail.co.za","webmail.co.za",$nemail);

		// fix postnet.co.za misspellings
		$nemail = str_ireplace("posnet.co.za","postnet.co.za",$nemail);

		// fix newhorizons.co.za misspellings
		$nemail = str_ireplace("newhorizaons.co.za","newhorizons.co.za",$nemail);

		// fix telkomsa.net misspellings
		$nemail = preg_replace("/telkom\.s\.a\./","telkomsa.",$nemail);
		$nemail = preg_replace("/telkom\.sa\./","telkomsa.",$nemail);
		$nemail = preg_replace("/teljomsa\./","telkomsa.",$nemail);

		$nemail = preg_replace("/^.?telkomsa.+$/","telkomsa.net",$nemail);
		
		$nemail = str_ireplace("telkon.net","telkomsa.net",$nemail);
		$nemail = str_ireplace("tepkomsa.net","telkomsa.net",$nemail);
		$nemail = str_ireplace("telkomnet.com","telkomsa.net",$nemail);
		$nemail = str_ireplace("telomsa.net","telkomsa.net",$nemail);
		
		// fix mweb misspellings
		$nemail = preg_replace("/^.?mweb.+$/","mweb.co.za",$nemail);
		
		$nemail = str_ireplace("m.web.co.za","mweb.co.za",$nemail);
		$nemail = str_ireplace("m-web.co.za","mweb.co.za",$nemail);
		$nemail = str_ireplace("mwed.co.za","mweb.co.za",$nemail);
		$nemail = str_ireplace("1mweb.co.za","mweb.co.za",$nemail);

		// fix standardbank.co.za misspellings
		$nemail = str_ireplace("standardbank.com.net","standardbank.co.za",$nemail);
		$nemail = str_ireplace("standardcank.co.za","standardbank.co.za",$nemail);

		// fix gmail misspellings
		$nemail = preg_replace("/^gmai\./","gmail",$nemail);
		$nemail = preg_replace("/^gmial\./","gmail.",$nemail);
		$nemail = preg_replace("/^gmaik\./","gmail.",$nemail);
		$nemail = preg_replace("/^gmaial\./","gmail.",$nemail);

		$nemail = preg_replace("/^.?gmail.+$/","gmail.com",$nemail);
		
		// fix intekom.co.za misspellings
		$nemail = preg_replace("/^.?intekom.+$/","intekom.co.za",$nemail);
		$nemail = str_ireplace("intelkom.co.za","intekom.co.za",$nemail);

		// fix netactive.co.za misspellings
		$nemail = preg_replace("/^.?netactive.+$/","netactive.co.za",$nemail);
		
		// fix yahoo misspellings
		$nemail = str_ireplace("yaqhoo","yahoo",$nemail);
		$nemail = preg_replace("/yahoo\.co\.za$/","yahoo.com",$nemail);

		$nemail = preg_replace("/^.?yahoo/","yahoo",$nemail);
		$nemail = preg_replace("/^.?yahooco\..+$/","yahoo.co.uk",$nemail);
		
		// fix ananzi.co.za misspellings
		$nemail = str_ireplace("ananzi.com","ananzi.co.za",$nemail);
		
		// fix iafrica.com misspellings
		$nemail = str_ireplace("iafria.com","iafrica.com",$nemail);
		$nemail = str_ireplace("aifrica.com","iafrica.com",$nemail);
		$nemail = str_ireplace("ifrica.ca.com","iafrica.com",$nemail);
		$nemail = str_ireplace("ifric.com","iafrica.com",$nemail);
		$nemail = str_ireplace("iafirca.com","iafrica.com",$nemail);
		$nemail = str_ireplace("iarfrica.com","iafrica.com",$nemail);
		$nemail = str_ireplace("idfrica.com","iafrica.com",$nemail);
		$nemail = str_ireplace("iafriva.co.za","iafrica.com",$nemail);
		$nemail = str_ireplace("iafrica.co.za","iafrica.com",$nemail);

		$nemail = preg_replace("/^iafrica.+$/","iafrica.com",$nemail);	
	
		// transunion
		$nemail = str_ireplace("transunionitc.co.za","transunion.co.za",$nemail);
		
		// fix rocketmail misspellings
		$nemail = preg_replace("/rockftmail/","rocketmail",$nemail);

		// fix worldonline.co.za misspellings
		$nemail = preg_replace("/^.?worldonline.+$/","worldonline.co.za",$nemail);

		$nemail = str_ireplace("worlonline.co.za","worldonline.co.za",$nemail);
		$nemail = str_ireplace("worlconlinc.co.za","worldonline.co.za",$nemail);
		
		// fix new.co.za misspellings
		$nemail = str_ireplace("mbury.new.co.za","new.co.za",$nemail);

		// fix clicks.co.za misspellings
		$nemail = str_ireplace("clics.co.za","clicks.co.za",$nemail);

		// fix deloitte.co.za misspellings
		$nemail = str_ireplace("dfloittf.co.za","deloitte.co.za",$nemail);
		
		// fix neotel.co.za misspellings
		$nemail = str_ireplace("onetel.com","neotel.co.za",$nemail);
		
		// fix mighty.co.za misspellings
		$nemail = str_ireplace("mjghty.co.za","mighty.co.za",$nemail);
		
		// fix polka.co.za misspellings
		$nemail = preg_replace("/^.?polka.+$/","intekom.co.za",$nemail);

		// fix nashuamobile.com misspellings
		$nemail = str_ireplace("nasuamobile.com","nashuamobile.com",$nemail);
		
		$nemail = str_ireplace("netconnfct.com","netconnect.com",$nemail);
		$nemail = str_ireplace("xsinct.co.za","xsinet.co.za",$nemail);

		// terms to invalidate
		$nemail = preg_replace("/^lists\./","@@",$nemail);
		$nemail = preg_replace("/^list\./","@@",$nemail);
		$nemail = preg_replace("/^listserv\./","@@",$nemail);
		$nemail = preg_replace("/^listserver\./","@@",$nemail);
		
		// domain changes
		$nemail = str_ireplace("kkdisplay.co.za","storequip.co.za",$nemail);
		$nemail = str_ireplace("mpsa.co.za","mpact.co.za",$nemail);
		$nemail = str_ireplace("versapak.co.za","mpact.co.za",$nemail);
		$nemail = str_ireplace("lionpackaging.co.za","mpact.co.za",$nemail);
		$nemail = str_ireplace("sacks-online.com","sacks.za.net",$nemail);
		$nemail = str_ireplace("corobrick.co.za","corobrik.co.za",$nemail);
		
		// domain spaces to invalidate
		$nemail = preg_replace("/\.ac\.uk$/","@@",$nemail);
		// $nemail = preg_replace("/\.ac\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.edu$/","@@",$nemail);
		$nemail = preg_replace("/\.edu\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.mil$/","@@",$nemail);
		$nemail = preg_replace("/\.gov$/","@@",$nemail);
		// $nemail = preg_replace("/\.gov\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.gov\.uk$/","@@",$nemail);
		$nemail = preg_replace("/\.gov\.sg$/","@@",$nemail);
		$nemail = preg_replace("/\.gnu\.org$/","@@",$nemail);
		$nemail = preg_replace("/\.rr\.com$/","@@",$nemail);
		$nemail = preg_replace("/\.qld$/","@@",$nemail);
		$nemail = preg_replace("/\.nct$/","@@",$nemail);
		$nemail = preg_replace("/\.brandt$/","@@",$nemail);
		$nemail = preg_replace("/\.horn$/","@@",$nemail);
		$nemail = preg_replace("/\.orh$/","@@",$nemail);
		$nemail = preg_replace("/\.int$/","@@",$nemail);
		$nemail = preg_replace("/\.hov$/","@@",$nemail);

		// country codes to invalidate
		$nemail = preg_replace("/\.ac$/","@@",$nemail);
		$nemail = preg_replace("/\.ae$/","@@",$nemail);
		$nemail = preg_replace("/\.ar$/","@@",$nemail);
		$nemail = preg_replace("/\.at$/","@@",$nemail);
		$nemail = preg_replace("/\.au$/","@@",$nemail);
		$nemail = preg_replace("/\.be$/","@@",$nemail);
		$nemail = preg_replace("/\.bj$/","@@",$nemail);
		$nemail = preg_replace("/\.bo$/","@@",$nemail);
		$nemail = preg_replace("/\.br$/","@@",$nemail);
		$nemail = preg_replace("/\.bt$/","@@",$nemail);
		$nemail = preg_replace("/\.bw$/","@@",$nemail);
		$nemail = preg_replace("/\.ca$/","@@",$nemail);
		$nemail = preg_replace("/\.cc$/","@@",$nemail);
		$nemail = preg_replace("/\.ch$/","@@",$nemail);
		$nemail = preg_replace("/\.cl$/","@@",$nemail);
		$nemail = preg_replace("/\.cm$/","@@",$nemail);
		$nemail = preg_replace("/\.cn$/","@@",$nemail);
		$nemail = preg_replace("/\.cu$/","@@",$nemail);
		$nemail = preg_replace("/\.cy$/","@@",$nemail);
		$nemail = preg_replace("/\.cz$/","@@",$nemail);
		$nemail = preg_replace("/\.de$/","@@",$nemail);
		$nemail = preg_replace("/\.dk$/","@@",$nemail);
		$nemail = preg_replace("/\.do$/","@@",$nemail);
		$nemail = preg_replace("/\.ec$/","@@",$nemail);
		$nemail = preg_replace("/\.ed$/","@@",$nemail);
		$nemail = preg_replace("/\.ee$/","@@",$nemail);
		$nemail = preg_replace("/\.eg$/","@@",$nemail);
		$nemail = preg_replace("/\.er$/","@@",$nemail);
		$nemail = preg_replace("/\.es$/","@@",$nemail);
		$nemail = preg_replace("/\.eu$/","@@",$nemail);
		$nemail = preg_replace("/\.fi$/","@@",$nemail);
		$nemail = preg_replace("/\.fj$/","@@",$nemail);
		$nemail = preg_replace("/\.fr$/","@@",$nemail);
		$nemail = preg_replace("/\.gh$/","@@",$nemail);
		$nemail = preg_replace("/\.gr$/","@@",$nemail);
		$nemail = preg_replace("/\.hk$/","@@",$nemail);
		$nemail = preg_replace("/\.hr$/","@@",$nemail);
		$nemail = preg_replace("/\.hu$/","@@",$nemail);
		$nemail = preg_replace("/\.id$/","@@",$nemail);
		$nemail = preg_replace("/\.ie$/","@@",$nemail);
		$nemail = preg_replace("/\.il$/","@@",$nemail);
		$nemail = preg_replace("/\.in$/","@@",$nemail);
		$nemail = preg_replace("/\.ir$/","@@",$nemail);
		$nemail = preg_replace("/\.is$/","@@",$nemail);
		$nemail = preg_replace("/\.it$/","@@",$nemail);
		$nemail = preg_replace("/\.je$/","@@",$nemail);
		$nemail = preg_replace("/\.jo$/","@@",$nemail);
		$nemail = preg_replace("/\.jp$/","@@",$nemail);
		$nemail = preg_replace("/\.ke$/","@@",$nemail);
		$nemail = preg_replace("/\.kr$/","@@",$nemail);
		$nemail = preg_replace("/\.kw$/","@@",$nemail);
		$nemail = preg_replace("/\.lb$/","@@",$nemail);
		$nemail = preg_replace("/\.lc$/","@@",$nemail);
		$nemail = preg_replace("/\.lk$/","@@",$nemail);
		$nemail = preg_replace("/\.ls$/","@@",$nemail);
		$nemail = preg_replace("/\.lu$/","@@",$nemail);
		$nemail = preg_replace("/\.lv$/","@@",$nemail);
		$nemail = preg_replace("/\.ma$/","@@",$nemail);
		$nemail = preg_replace("/\.mk$/","@@",$nemail);
		$nemail = preg_replace("/\.mm$/","@@",$nemail);
		$nemail = preg_replace("/\.mt$/","@@",$nemail);
		$nemail = preg_replace("/\.mu$/","@@",$nemail);
		$nemail = preg_replace("/\.mx$/","@@",$nemail);
		$nemail = preg_replace("/\.my$/","@@",$nemail);
		$nemail = preg_replace("/\.mz$/","@@",$nemail);
		$nemail = preg_replace("/\.ng$/","@@",$nemail);
		$nemail = preg_replace("/\.nl$/","@@",$nemail);
		$nemail = preg_replace("/\.no$/","@@",$nemail);
		$nemail = preg_replace("/\.np$/","@@",$nemail);
		$nemail = preg_replace("/\.nu$/","@@",$nemail);
		$nemail = preg_replace("/\.nz$/","@@",$nemail);
		$nemail = preg_replace("/\.om$/","@@",$nemail);
		$nemail = preg_replace("/\.pe$/","@@",$nemail);
		$nemail = preg_replace("/\.pg$/","@@",$nemail);
		$nemail = preg_replace("/\.ph$/","@@",$nemail);
		$nemail = preg_replace("/\.pk$/","@@",$nemail);
		$nemail = preg_replace("/\.pl$/","@@",$nemail);
		$nemail = preg_replace("/\.pt$/","@@",$nemail);
		$nemail = preg_replace("/\.py$/","@@",$nemail);
		$nemail = preg_replace("/\.qa$/","@@",$nemail);
		$nemail = preg_replace("/\.ro$/","@@",$nemail);
		$nemail = preg_replace("/\.ru$/","@@",$nemail);
		$nemail = preg_replace("/\.sa$/","@@",$nemail);
		$nemail = preg_replace("/\.se$/","@@",$nemail);
		$nemail = preg_replace("/\.sg$/","@@",$nemail);
		$nemail = preg_replace("/\.si$/","@@",$nemail);
		$nemail = preg_replace("/\.sk$/","@@",$nemail);
		$nemail = preg_replace("/\.sy$/","@@",$nemail);
		$nemail = preg_replace("/\.sz$/","@@",$nemail);
		$nemail = preg_replace("/\.th$/","@@",$nemail);
		$nemail = preg_replace("/\.tn$/","@@",$nemail);
		$nemail = preg_replace("/\.tr$/","@@",$nemail);
		$nemail = preg_replace("/\.tv$/","@@",$nemail);
		$nemail = preg_replace("/\.tz$/","@@",$nemail);
		$nemail = preg_replace("/\.tw$/","@@",$nemail);
		$nemail = preg_replace("/\.ua$/","@@",$nemail);
		$nemail = preg_replace("/\.ug$/","@@",$nemail);
		$nemail = preg_replace("/\.us$/","@@",$nemail);
		$nemail = preg_replace("/\.uy$/","@@",$nemail);
		$nemail = preg_replace("/\.uz$/","@@",$nemail);
		$nemail = preg_replace("/\.vn$/","@@",$nemail);
		$nemail = preg_replace("/\.ye$/","@@",$nemail);
		$nemail = preg_replace("/\.yu$/","@@",$nemail);
		$nemail = preg_replace("/\.zm$/","@@",$nemail);
		$nemail = preg_replace("/\.zw$/","@@",$nemail);
		
		return $nemail;
	}
	
	// replacement rules to fix addresses or remove undesireable addresses
	public function FixEmail($semail) {
		$nemail = trim(strtolower($semail));
		
		// fix common errors		
		$nemail = preg_replace("/^-{1,10}/","",$nemail);
		$nemail = preg_replace("/^_{1,10}/","",$nemail);
		$nemail = preg_replace("/-{2,10}/","-",$nemail);
		$nemail = preg_replace("/\.{2,10}/",".",$nemail);
		// $nemail = preg_replace("/^20/","",$nemail);
		// $nemail = preg_replace("/%20/","",$nemail);
		// $nemail = preg_replace("/^3[a-d]/","",$nemail);
		$nemail = preg_replace("/^mailto\./","",$nemail);
		$nemail = preg_replace("/^mailto/","",$nemail);
		// $nemail = preg_replace("/^[0-9]/","@@",$nemail);
		$nemail = preg_replace("/\.-|-\./",".",$nemail);
		$nemail = preg_replace("/\.@|@\./","@",$nemail);
		$nemail = preg_replace("/-@|@-/","@",$nemail);
		$nemail = preg_replace("/_@|@_/","@",$nemail);		

		// address changes
		$nemail = str_ireplace("thecotmpanyofwinepeople","thecompanyofwinepeople",$nemail);
		$nemail = str_ireplace("busybean@themugg.com","busybean@muggandbean.co.za",$nemail);
		$nemail = str_ireplace("dawie@inetcom.co.za","dawiec@telkomsa.net",$nemail);
		$nemail = str_ireplace("candice@gavinmostert.co.za","admin@gavinmostert.co.za",$nemail);
		$nemail = str_ireplace("charlene@eagleteam.co.za","info@eagleteam.co.za",$nemail);
		$nemail = str_ireplace("chris@thembalitsha.org.za","grant@thembalitsha.org.za",$nemail);
		$nemail = str_ireplace("darkwing@tiscali.co.za","trevorwbp@hotmail.com",$nemail);
		$nemail = str_ireplace("cate@moneytalk.co.za","cate.hannocks@consolidatedec.co.za",$nemail);
		$nemail = str_ireplace("david@moneytalk.co.za","david.szuhanyi@consolidatedec.co.za",$nemail);
		$nemail = str_ireplace("andrew@southernkitchens.co.za","wayne@homeconcept.co.za",$nemail);
		$nemail = str_ireplace("kscp@vaal.net","capot@claydisposal.com",$nemail);
		$nemail = str_ireplace("mfest@metmissions.org.za","info@mfestpretoria.org",$nemail);
		$nemail = str_ireplace("mkahn@tppsa.co.za","janieb@tppsa.co.za",$nemail);
		$nemail = str_ireplace("mike@khfreightgroup.com","clint.hendrickse@khfreightgroup.com",$nemail);
		$nemail = str_ireplace("mark@sstream.co.za","markgelman.ct@gmail.com",$nemail);
		$nemail = str_ireplace("martin@principia.za.net","martin@m2skills.co.za",$nemail);
		$nemail = str_ireplace("rasheed@afripile.co.za","afripilerasheed@gmail.com",$nemail);
		$nemail = str_ireplace("info@lifenergy.co.za","sabinethomas@tiscali.co.za",$nemail);
		$nemail = str_ireplace("jacocoetzee@wesconstruction.co.za","info@wesconstruction.co.za",$nemail);
		$nemail = str_ireplace("stiaandreyer@boshoffvisser.co.za","stiaan@bvfd.co.za",$nemail);
		$nemail = str_ireplace("info@chengineering.co.za","brandt@philor.co.za",$nemail);
		$nemail = str_ireplace("warren@jumbozw.com","warrenzw@gmail.com",$nemail);
		$nemail = str_ireplace("arethavdmerwe@potential-unlimited.co.za","aretha@potential-unlimited.co.za",$nemail);
		$nemail = str_ireplace("vorster@calicom.co.za","vorster@bekkergauche.co.za",$nemail);
		$nemail = str_ireplace("alon@iwi.co.za","alon@togsa.co.za",$nemail);
		$nemail = str_ireplace("cobusvv@freys.co.za","gails@freys.co.za",$nemail);
		$nemail = str_ireplace("jeremy@thebutchery.co.za","jeremy@clubweb.co.za",$nemail);
		$nemail = str_ireplace("ursula.scott@ceu.co.za","ursulas@mirabilisafrica.com",$nemail);
		$nemail = str_ireplace("janine@iwi.co.za","janine@togsa.co.za",$nemail);
		$nemail = str_ireplace("info@workshop.co.za","annecswart@gmail.com",$nemail);
		$nemail = str_ireplace("anne@ysa-lapin.com","anne@ysa.co.za",$nemail);
		$nemail = str_ireplace("jr@debt-therapy.net","jorgen@rosvall.co.za",$nemail);
		$nemail = str_ireplace("mdutoit@dectrust.co.za","ceo@dectrust.co.za",$nemail);
		$nemail = str_ireplace("ipcplumbing@my.co.za","office@ipcplumbing.co.za",$nemail);
		$nemail = str_ireplace("capenat@new.co.za","info@rooibostea.co.za",$nemail);
		$nemail = str_ireplace("petros@leighgroup.co.za","leigh@leighgroup.co.za",$nemail);
		$nemail = str_ireplace("tmotsoane@wmsgaming.co.za","potto@wmsgaming.co.za",$nemail);
		$nemail = str_ireplace("loukie7@gmail.com","loudine@puredelight.co.za",$nemail);
		$nemail = str_ireplace("mike@kiabrokers.co.za","michael.olivier@telkomsa.net",$nemail);
		$nemail = str_ireplace("piemichelle@piemanspantry.co.za","michelles@foodcorp.co.za",$nemail);
		$nemail = str_ireplace("michelle@nisc.co.za","michelle@itbsoftware.co.za",$nemail);
		$nemail = str_ireplace("maor@balkanology.co.za","maor@thebeanstalk.co.za",$nemail);
		$nemail = str_ireplace("moutonwj@netline.co.za","admin@drmouton.co.za",$nemail);
		$nemail = str_ireplace("duffuel@vaal.net","enviro@claydisposal.com",$nemail);

		$cansendto = false;
				
		$this->semailuser = $this->GetEmailUser($nemail);
		$this->semaildomain = $this->GetEmailDomain($nemail);
		
		$this->semailuser = $this->FixUser($this->semailuser);
		$this->semaildomain = $this->FixDomain($this->semaildomain);
		$this->semail = $this->semailuser . "@" . $this->semaildomain;

		$fp1 = fopen("deleted.txt", "a"); 
		$bdate = date("Y-m-d H:i:s");

		$valid = $this->CheckEmail($this->semail);
		if (!$valid) { // bad format
			fwrite($fp1, $bdate . " FORMAT $semail --> $this->semail $this->spriority\n");
			fclose($fp1);
			return false;
		}

		$nemail = $this->semail;
		if (preg_match('/\.za$/',$nemail) || preg_match('/\.za\.net$/',$nemail) || preg_match('/@za\./',$nemail) || preg_match('/@gmail\.com$/',$nemail) || preg_match('/@telkomsa\.net$/',$nemail) || preg_match('/@iafrica\.com$/',$nemail) || preg_match('/@mweb\.com$/',$nemail) || preg_match('/@lantic\.net$/',$nemail)) {
			$this->spriority = max($this->spriority, 45);
		}
		
		if ($this->spriority == 0) { // low priority
			fwrite($fp1, $bdate . " PRIORITY $semail --> $this->semail $this->spriority\n");
			fclose($fp1);
			return false;
		}

		$domain_exists = $this->CheckDomain($this->semaildomain);
		if (!$domain_exists) { // bad domain
			fwrite($fp1, $bdate . " DOMAIN $semail --> $this->semail $this->spriority\n");
			fclose($fp1);
			return false;
		}
		
		$email_unsubbed = $this->GloballyUnsubscribed($this->semail);
		if ($email_unsubbed) { // bad email
			fwrite($fp1, $bdate . " EMAIL $semail --> $this->semail $this->spriority\n");
			fclose($fp1);
			return false;
		}
		
		$cansendto = $this->validate($this->semailuser,$this->semaildomain);
		if (!$cansendto) { // bad send
			fwrite($fp1, $bdate . " SEND $semail --> $this->semail $this->spriority $this->reply\n");
		}
		
		fclose($fp1);
		return $cansendto;
	}

	/*
	update subscribers set s_confirm = '1' where s_confirm = '0'
	update subscribers set s_unsubscribe = '0' where s_unsubscribe = '1'
	update subscribers set s_emailsleft = '20' where s_emailsleft < '20'
	*/
	public function CheckSubscribers() {
		set_time_limit(0);
		// $sql = "SELECT s_uniqid, s_email FROM subscribers WHERE s_priority < \"50\" ORDER BY s_email";
		// $sql = "SELECT s_uniqid, s_email FROM subscribers ORDER BY s_priority DESC, s_email ASC";
		$sql = "SELECT s_uniqid FROM subscribers ORDER BY s_priority DESC, s_email ASC";
		$result = $this->dbconn->query($sql);
		if (!$result) {
			// echo "<p>" . $this->dbconn->error . "</p>";
			return false;
		}
		while ($row = $result->fetch_array()) {
			set_time_limit(0);
			$suid = $row["s_uniqid"];
			// $semail = $row["s_email"];
			$this->Read($suid);
		}
		$result->close();
		return true;
	}

		// this will check an email address for correct format using the swiftmailer library
	public function CheckEmail($email) {
		$message = Swift_Message::newInstance("no subject","no body");
		try {
			$message->addTo($email,"no name");
			return true;
		} catch (Exception $e) {
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return false;
		}
	}
	
public function CheckDomain($domain_name) {
		if ($domain_name == "") return false;

        $domain_name = strtolower(trim($domain_name));

		// check if domain is already globally unsubscribed
		if ($this->DomainGloballyUnsubscribed($domain_name)) return false;

        // do a domain name check only for these domains
        if ($this->Domain <> "biztrainer.xyz") return true; // HACK: skip domain check

		// Check that MX records exist for the domain;
		$fqdn = $domain_name . '.';
		$cansendto = checkdnsrr($fqdn);
		
		// Add domain to the global filter if it does not have MX records
		if (!$cansendto) {
			$success = $this->SaveGlobalDomainUnsubscribe($domain_name, "NOTEXIST");
		}
		return $cansendto;
	}

	private function validate($user,$domain) {
		return true; // skips validation - temporary hack

		// class variable turns off email validation
		if (!$this->VALIDATE_EMAIL) return true;

		$this->reply = "";
		if (($user == '') || ($domain == '')) return false;

		$email = $user . '@' . $domain;
		
		$result = false;
		
		$mxs = array();
	 
		// retrieve SMTP Server via MX query on domain
		list($hosts, $mxweights) = $this->queryMX($domain);

		// retrieve MX priorities
		for($n = 0; $n < count($hosts); $n++) {
			$mxs[$hosts[$n]] = $mxweights[$n];
		}
		asort($mxs);
	
		// last fallback is the original domain
		$mxs[domain] = 0;
	  
		$timeout = 30;
		$port = 25; // SMTP port
	   
		// try each host
		while(list($host) = each($mxs)) {
			// connect to SMTP server
			// $ServerIP = $_SERVER['SERVER_ADDR'];
			$ServerIP = $this->fat->get('SERVER.SERVER_ADDR');
			// 78.47.223.228
			$socket_options = array('socket' => array('bindto' => "{$ServerIP}:0"));
			$socket_context = stream_context_create($socket_options);
			
			// if ($this->sock = fsockopen($host, $port, $errno, $errstr, (float) $timeout)) {
			if ($this->sock = stream_socket_client($host . ":". $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $socket_context)) {
				stream_set_timeout($this->sock, 5);
				break;
			}
		}
	 
		// did we get a TCP socket
		if ($this->sock) {
			$this->reply = fread($this->sock, 2082);
			preg_match('/^([0-9]{3}) /ims', $this->reply, $matches);
			$code = isset($matches[1]) ? $matches[1] : '';
	
			if($code != '220') {
				// MTA gave an error...
				$result = false;
			} else {
				// say helo
				$this->send("HELO " . $this->Domain);
				// email of sender
				$this->send("MAIL FROM: <{$this->FromAddress}>");
	   
				// ask of recipient
				$this->reply = $this->send("RCPT TO: <" . $email . ">");
				// get code and msg from response
				preg_match('/^([0-9]{3}) /ims', $this->reply, $matches);
				$code = isset($matches[1]) ? $matches[1] : '';
				if ($code == '250') {
					// you received 250 so the email address was accepted
					$result = true;
				} elseif ($code == '451' || $code == '452') {
					// you received 451 so the email address was greylisted (or some temporary error occured on the MTA) - so assume is ok
					$result = false;
				} else {
					$result = false;
					// insert bad address into globalunsubscribe table
					$gutype = "INVALID";
					$success = $this->SaveGlobalUnsubscribe($email,$user,$domain,$gutype,$this->reply);
				}
	   
				// reset before quit
				$this->send("RSET");
	   
				// quit
				$this->send("quit");
				
				// close socket
				fclose($this->sock);
			}
		}
		
 		return $result;
	}

	private function send($msg) {
		fwrite($this->sock, $msg."\r\n");
		$reply = fread($this->sock, 2082);
		return $reply;
	}
 
 /**
  * Query DNS server for MX entries
  * @return 
  */
	private function queryMX($domain) {
		$hosts = array();
		$mxweights = array();
		getmxrr($domain, $hosts, $mxweights);
		return array($hosts, $mxweights);
	}

  // 2014-08-08 save entry into globalunsubscribe table
  public function SaveGlobalUnsubscribe($email,$email_user,$email_domain,$gutype,$reason) {
  	$success = true;
    $sql = "select gu_active from globalunsubscribe where gu_email = '$email'";
    $result = $this->globaldb->query($sql);
    $num_rows = $result->num_rows;
    if ($num_rows == 0) {
      // no entry exists, so insert into table
      $sql = "insert into globalunsubscribe (gu_api, gu_domain, gu_email, gu_email_user, gu_email_domain, gu_type, gu_reason) values (\"$this->API\",\"$this->Domain\",\"$email\",\"$email_user\",\"$email_domain\",\"$gutype\",\"$reason\");";
      $success = $this->globaldb->query($sql);
      if (!$success) {
        $this->save_error = "CREATE ERROR: " . (string)$this->globaldb->error;
      }
    } else {
      // an entry exists, so update table
      $sql = "update globalunsubscribe set gu_type = \"$gutype\", gu_active = \"1\" where gu_email = \"$email\"";
      $success = $this->globaldb->query($sql);
      if (!$success) {
        $this->save_error = "UPDATE ERROR: " . (string)$this->globaldb->error;
      }
    }
    $result->close();
    return $success;
  }

  // 2015-02-21 save entry into globaldomainunsubscribe table
  public function SaveGlobalDomainUnsubscribe($email_domain,$gdutype) {
  	$success = true;
    $sql = "select gdu_active from globaldomainunsubscribe where gdu_domain_name = '$email_domain'";
    $result = $this->globaldb->query($sql);
    $num_rows = $result->num_rows;
    if ($num_rows == 0) {
      // no entry exists, so insert into table
      $sql = "insert into globaldomainunsubscribe (gdu_api, gdu_domain,gdu_domain_name, gdu_type) values (\"$this->API\",\"$this->Domain\",\"$email_domain\",\"$gdutype\");";
      $success = $this->globaldb->query($sql);
      if (!$success) {
        $this->save_error = "CREATE ERROR: " . (string)$this->globaldb->error;
      }
    } else {
      // an entry exists, so update table
      $sql = "update globaldomainunsubscribe set gdu_type = \"$gdutype\", gdu_active = \"1\" where gdu_domain_name = \"$email_domain\"";
      $success = $this->globaldb->query($sql);
      if (!$success) {
        $this->save_error = "UPDATE ERROR: " . (string)$this->globaldb->error;
      }
    }
    $result->close();
    return $success;
  }

	// reset subscriber class variables to default values
	public function Reset() {
		$this->sid = 0;
		$this->s_u_id = 0;
		$this->suid = "";
		$this->semail = "";
		$this->semailuser = "";
		$this->semaildomain = "";
		$this->spriority = 0;
		$this->ssubscribedate = NULL;
		$this->sconfirm = 0;
		$this->sconfirmdate = NULL;
		$this->sunsubscribe = 0;
		$this->sunsubscribedate = NULL;
		$this->sunsubscribereason = "";
		$this->sunsubscribebounce = 0;
  		$this->sunsubscribeadmin = 0;
		$this->sbounces = 0;
		$this->semailsleft = 0;
		$this->sfname = "";
		$this->slname = "";
		$this->sprovince = "";
		$this->scountry = "";
		$this->sgender = "";
	}

	// create a new subscriber, if possible
	public function Create() {
        $this->create_error = "";
		$oemail = $this->semail;
		$cansendto = $this->FixEmail($this->semail);	
	
		// email cannot be sent to, so return false, could not create subscriber
		if (!$cansendto) return false;
	
		$this->suid = $this->CreateSUID();
		$this->semailsleft = $this->SubscriptionConfirmLevel;
		$sql = "insert into subscribers (s_uniqid, s_email, s_email_user, s_email_domain, s_priority, s_confirm, s_emailsleft, s_fname, s_lname, s_province, s_country, s_gender) values (\"$this->suid\",\"$this->semail\",\"$this->semailuser\",\"$this->semaildomain\",\"$this->spriority\",\"1\",\"$this->semailsleft\",\"$this->sfname\",\"$this->slname\",\"$this->sprovince\",\"$this->scountry\",\"$this->sgender\")";
		$created = $this->dbconn->query($sql);
		if (!$created) {
			$this->create_error = "CREATE ERROR: " . (string)$this->dbconn->error;
			// printf("<p>%s</p>", $this->create_error);
		}
		return $created;
	}

	public function Read($suid) {
		$this->Reset();
		$suid = $this->dbconn->real_escape_string($suid);
		if (($suid == '') || (is_null($suid))) return false;
		$sql = "select * from subscribers where s_uniqid = \"$suid\"";
		$result = $this->dbconn->query($sql);
		// check that the subscriber exists
		if ($result->num_rows == 0) {
			$result->close();
			return false;
		}
		$row = $result->fetch_array();
		$this->sid = $row["s_id"];
		$this->s_u_id = $row["s_u_id"];
		$this->suid = $row["s_uniqid"];
		$this->semail = strtolower($row["s_email"]);
		$this->semailuser = strtolower($row["s_email_user"]);
		$this->semaildomain = strtolower($row["s_email_domain"]);
		$this->spriority = $row["s_priority"];
		$this->ssubscribedate = $row["s_subscribedate"];
		$this->sconfirm = $row["s_confirm"];
		$this->sconfirmdate = $row["s_confirmdate"];
		$this->sunsubscribe = $row["s_unsubscribe"];
		$this->sunsubscribedate = $row["s_unsubscribedate"];
		$this->sunsubscribereason = $row["s_unsubscribereason"];
		$this->sunsubscribebounce = $row["s_unsubscribe_bounce"];
		$this->sunsubscribeadmin = $row["s_unsubscribe_admin"];
		$this->sbounces = $row["s_bounces"];
		$this->semailsleft = $row["s_emailsleft"];
		$this->sfname = $row["s_fname"];
		$this->slname = $row["s_lname"];
		$this->sprovince = $row["s_province"];
		$this->scountry = $row["s_country"];
		$this->sgender = $row["s_gender"];
		$result->close();

		$oemail = $this->semail;
		$cansendto = $this->FixEmail($this->semail);	
			
		if (!$cansendto) {
			// email is invalid, delete it and related child records
			$this->Delete($this->sid,$this->suid);
			return false;
		} elseif ($oemail <> $this->semail) {
			// email has been changed, so save changes to database
			$updated = $this->Update();
			if ($updated) {
				$fp1 = fopen("changed.txt", "a");
				$bdate = date("Y-m-d H:i:s");
				fwrite($fp1, $bdate . " $oemail --> $this->semail $this->spriority\n");
				fclose($fp1);
				return true;
			} else {
				return false;
			}
		}
		return true;
	}

	// Sets class variables for the subscriber. returns true if they exist else false
	public function RetrieveSubscriber($suid) {
		return $this->Read($suid);
	}

	// saves subscriber changes to the database
	public function Update() {
    $this->update_error = "";
		// $oemail = $this->semail;
		$cansendto = $this->FixEmail($this->semail);	
	
		// email cannot be sent to, so return false
		if (!$cansendto) return false;

		if (is_null($this->ssubscribedate) or ($this->ssubscribedate == "0000-00-00 00:00:00")) {
			$this->ssubscribedate = date("Y-m-d H:i:s");
		}

		$this->sunsubscribedate = NULL;

		// $sql ="update subscribers set s_u_id = \"$this->s_u_id\", s_email = \"$this->semail\", s_email_user = \"$this->semailuser\", s_email_domain = \"$this->semaildomain\", s_priority = \"$this->spriority\", s_subscribedate = \"$this->ssubscribedate\", s_confirm = \"$this->sconfirm\", s_confirmdate = \"$this->sconfirmdate\", s_unsubscribe = \"$this->sunsubscribe\", s_unsubscribedate = \"$this->sunsubscribedate\", s_unsubscribereason = \"$this->sunsubscribereason\", s_unsubscribe_bounce = \"$this->sunsubscribebounce\", s_unsubscribe_admin = \"$this->sunsubscribeadmin\", s_bounces = \"$this->sbounces\", s_emailsleft = \"$this->semailsleft\", s_fname = \"$this->sfname\", s_lname = \"$this->slname\", s_province = \"$this->sprovince\", s_country = \"$this->scountry\", s_gender = \"$this->sgender\" where s_uniqid = \"$this->suid\"";
		$sql ="update subscribers set s_u_id = \"$this->s_u_id\", s_email = \"$this->semail\", s_email_user = \"$this->semailuser\", s_email_domain = \"$this->semaildomain\", s_priority = \"$this->spriority\", s_subscribedate = \"$this->ssubscribedate\", s_confirm = \"$this->sconfirm\", s_confirmdate = \"$this->sconfirmdate\", s_unsubscribe = \"$this->sunsubscribe\", s_unsubscribedate = \"$this->sunsubscribedate\", s_unsubscribereason = \"$this->sunsubscribereason\", s_bounces = \"$this->sbounces\", s_emailsleft = \"$this->semailsleft\", s_fname = \"$this->sfname\", s_lname = \"$this->slname\", s_province = \"$this->sprovince\", s_country = \"$this->scountry\", s_gender = \"$this->sgender\" where s_uniqid = \"$this->suid\"";
		// echo "<p>UPDATE: $sql</p>";
		$updated = $this->dbconn->query($sql);
		if (!$updated) {
			$this->update_error = "UPDATE ERROR: " . (string)$this->dbconn->error;
			// printf("<p>%s</p>", $this->update_error);
		}
		return $updated;
	}

	public function Delete($sid,$suid) {
		$sql = "delete from subscribers where s_id = \"$sid\"";
		$success1 = $this->dbconn->query($sql);
		$sql = "delete from smsent where sms_s_id = \"$sid\"";
		$success2 = $this->dbconn->query($sql);
		$sql = "delete from smopens where smo_s_uniqid = \"$suid\"";
		$success3 = $this->dbconn->query($sql);
		$success = $success1 && $success2 && $success3;
		return $success;
	}
	
	public function DeleteSubscriber($sid,$suid) {
		return $this->Delete($sid,$suid);
	}
}
?>