<?php
/*

Module: messages class
Version: 1.2
Author: Richard Catto
Creation Date: 2009-08-05
Milestones:

Description:
This class handles all the message related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

*/

class messages {

	protected $fat;
	protected $user;
	protected $dbconn;
    protected $dbPDO;
	protected $globaldb;
	protected $BaseURL;
	protected $ListName;
	protected $FromAddress;
	protected $AdminEmail;
	protected $AdminName;

	protected $default_smtp_server;
	protected $SubscriptionConfirmAmount;
	
	// message variables - must be public for forms
	public $mid;
	public $muid;
	public $mtid;
	public $maid;
	public $mfrom;
	public $mpriority;
	public $msubject;
	public $mhtml;
	public $mtext;

	// $dbconn is a mysqli connection object to an open MySQL database
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
        $this->dbPDO = $fat->get('dbPDO');
		$this->BaseURL = $fat->get('BaseURL');
		$this->ListName = $fat->get('ListName');
		$this->FromAddress = $fat->get('FromAddress');
		$this->AdminEmail = $fat->get('AdminEmail');
		$this->AdminName = $fat->get('AdminName');

		$this->default_smtp_server = $fat->get('default_smtp_server');
		$this->SubscriptionConfirmAmount = $fat->get('SubscriptionConfirmAmount');

		$gdbhost = $fat->get('gdbhost');
		$gdbuser = $fat->get('gdbuser');
		$gdbpass = $fat->get('gdbpass');
		$gdbname = $fat->get('gdbname');

		// Connect to globalfilter database to globally unsubscribe subscribers
		$this->globaldb = new mysqli($gdbhost,$gdbuser,$gdbpass,$gdbname);
		if ($this->globaldb->connect_error) {
			die('Connect Error (' . $this->globaldb->connect_errno . ') ' . $this->globaldb->connect_error);
		}
	}

	function __destruct() {
		// $this->globaldb->close();
	}

	// Each message gets assigned a unique random md5 hash ID
	// which is used to identify them in lieu of their m_id for security purposes.
	protected function CreateMUID() {
		$muid = md5(uniqid(mt_rand(),true));
		$result = $this->dbconn->query("select * from messages where m_uniqid = \"$muid\"");
		while ($this->dbconn->affected_rows > 0) {
			$result->close();
			$muid = md5(uniqid(mt_rand(),true));
			$this->dbconn->query("select * from messages where m_uniqid = \"$muid\"");
		}
		// $result->close();
		return $muid;
	}

  	// HTML output methods

	// display paginated list of messages in system
	public function CreateMessagesHTMLList($pageno,$numrows) {
        $html = "";
        if ($this->user->uadmin == '1') {
            $html .= "<p><a href=\"{$this->BaseURL}queue\">View the Queue</a></p>";
            $html .= "<p><a href=\"{$this->BaseURL}message\">Create new message</a></p>";
        }
        if (!$this->user->uloggedin) {
        	$html .= "<p>Please login in order to view this page.</p>";
        	return $html;
        }

		$sql = "select count(*) from messages";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		// $result->close();
		if ($totalmatches == 0) {
			$html .= "<p>There are no messages.</p>";
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
		
		$sql = "select m_id, m_uniqid, m_subject, m_datesentbegin, m_sent, m_bounces, m_viewed from messages order by m_id desc $limit";
		$result = $this->dbconn->query($sql);

		if ($this->user->uadmin == '1') {
			$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Subject</th><th>Sent on</th><th>Number sent</th><th>Bounces</th><th>Viewed</th><th>Queue to List</th><th>Send to Admin</th></tr></thead><tbody>";
		} else {
			$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Subject</th><th>Sent on</th><th>Number sent</th><th>Bounces</th><th>Viewed</th></tr></thead><tbody>";
		}

		while ($row = $result->fetch_array()) {
			$mid = $row["m_id"];
			$muid = $row["m_uniqid"];
			$msubject = stripslashes($row["m_subject"]);
			$mdatesentbegin = (is_null($row["m_datesentbegin"]) ? "&nbsp;" : $row["m_datesentbegin"]);
			$mbounces = $row["m_bounces"];
			$msent = $row["m_sent"];
			$mviewed = $row["m_viewed"];

			if ($this->user->uadmin == '1') {
			  $html .= "<tr><td><a href=\"{$this->BaseURL}message/{$muid}\">$msubject</a></td><td>$mdatesentbegin</td><td>$msent</td><td>$mbounces</td><td><a href=\"{$this->BaseURL}message-views/{$mid}\">$mviewed</a></td><td><a href=\"{$this->BaseURL}queuelist/{$muid}\">SendToQueue</a></td><td><a href=\"{$this->BaseURL}sendtome/{$muid}\">Test Send</a></td></tr>";
			} else {
			  $html .= "<tr><td>$msubject</td><td>$mdatesentbegin</td><td>$msent</td><td>$mbounces</td><td>$mviewed</td></tr>";
			}
		}
		
		$html .= "</tbody></table>";
		
		// $result->close();

		$html .= "<p>Page $pageno of $lastpage - $totalmatches records found</p>";

		// add in navigation to browse large results lists.
		$html .= "<ul class=\"pager\">";
		if ($pageno == 1) {
			$html .= "<li class=\"previous disabled\"><a href=\"#\">Previous</li>";
		} else {
			$prevpage = $pageno - 1;
			$html .= "<li class=\"previous\"><a href='{$this->BaseURL}messages/$prevpage'>Previous</a></li>";
		}
		if ($pageno == $lastpage) {
			$html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
			$nextpage = $pageno + 1;
			$html .= "<li class=\"next\"><a href='{$this->BaseURL}messages/$nextpage'>Next</a></li>";
		}
		$html .= "</ul>";

		return $html;
	}

	// shows a paginated list of every subscriber who viewed a particular message
	public function CreateMessageViewsHTMLList($ssemail,$mid,$pageno,$numrows) {
		$html = "";
		if ($mid == 0) {
			$html .= "<p>No such message.</p>";
			return $html;
		}
		if ($this->user->uadmin == '1') {
			$html .= "<form name=\"messageviewsform\" action=\"{$this->BaseURL}message-views/$mid\" method=\"get\" class=\"form-inline\" role=\"form\">";
			$html .= "<fieldset><legend>Search by email address</legend>";
			$html .= "<div class=\"form-group\"><div class=\"col-md-3\"><input class=\"form-control\" name=\"e\" type=\"search\" maxlength=\"255\" value=\"$ssemail\"></div></div>";
			$html .= "<div class=\"form-group\"><button class=\"btn btn-primary\" type=\"submit\">Email Search</button></div>";
			$html .= "</fieldset></form><hr>";
		} else {
			$html .= "<p>Access denied</p>";
			return $html;
		}

		$sql = "select count(*) from smopens join subscribers on (s_uniqid = smo_s_uniqid) where (smo_m_id = \"$mid\") and (s_email like \"%$ssemail%\")";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		// $result->close();
		if ($totalmatches == 0) {
			if ($ssemail == "") {
				$html .= "<p>No-one has viewed this message yet.</p>";
			} else {
				$html .= "<p>No email like that has viewed this message yet.</p>";
			}
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
		
		$sql = "select s_email, smo_dateopened from smopens join subscribers on (s_uniqid = smo_s_uniqid) where (smo_m_id = \"$mid\") and (s_email like \"%$ssemail%\") order by smo_dateopened $limit";
		$result = $this->dbconn->query($sql);
		
		$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Email</th><th>Date Time</th></tr></thead><tbody>";
		
		while($row = $result->fetch_array()) {
			$semail = $row["s_email"];
			$smodateopened = $row["smo_dateopened"];
			$html .= "<tr><td>$semail</td><td>$smodateopened</td></tr>";
		}

		$html .= "</tbody></table>";
		
		// $result->close();

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
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}message-views/{$mid}/1/{$querystring}\">First</a></li>";
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}message-views/{$mid}/{$prevpage}/{$querystring}\">Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}message-views/{$mid}/{$lastpage}/{$querystring}\">Last</a></li>";
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}message-views/{$mid}/{$nextpage}/{$querystring}\">Next</a></li>";
		}
		$html .= "</ul>";
		return $html;
	}

	public function CreateMessageHTMLform($muid = "") {
		$html = "";
		if ($this->user->uadmin <> '1') {
			$html .= "<p>Access denied</p>";
			return $html;
		}
		$hh = $this->fat->get('htmlhelper');
		$template = $this->fat->get('templates');
		if ($muid == "") {
			$this->mtid = "0";
			$this->mfrom = $this->FromAddress;
			$this->mpriority = 0;
			$this->msubject = "";
			$this->mhtml = "";
			$this->mtext = "";
		} else {
			$msgexists = $this->RetrieveMessage($muid);
			if (!$msgexists) {
				$html .= "<p>Message does not exist</p>";
				return $html;
			}
		}
		$html .= "<form name=\"messageform\" action=\"{$this->BaseURL}message\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<input name=\"muid\" type=\"hidden\" value=\"{$muid}\">";
		$html .= "<fieldset><legend>Message form</legend>";

		$select_text = array(
			0 => array("mtid","Message Template",$template->CreateTemplateHTMLDropDown($this->mtid)),
			1 => array("mfrom","From Address",$hh->CreateFromHTMLDropDown($this->mfrom))
		);

		foreach($select_text as $scontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$scontrol[0]}\">{$scontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<select class=\"form-control\" name=\"{$scontrol[0]}\">{$scontrol[2]}</select>";
			$html .= "</div></div>";
		}

		$input_text = array(
			0 => array("mpriority","Priority","number",$this->mpriority),
			1 => array("msubject","Subject","text",htmlspecialchars($this->msubject))
		);

		foreach($input_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<input class=\"form-control\" type=\"{$icontrol[2]}\" size=\"100\" name=\"{$icontrol[0]}\" value=\"{$icontrol[3]}\">";
			$html .= "</div></div>";
		}

		$textarea_text = array(
			0 => array("mhtml","HTML part",$this->mhtml,"<script type=\"text/javascript\">CKEDITOR.replace( 'mhtml', { width : '920px', height : '600px' });</script></p>"),
			1 => array("mtext","Text part",$this->mtext,"")
		);

		foreach($textarea_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<textarea class=\"form-control\" rows=\"20\" cols=\"90\" name=\"{$icontrol[0]}\">{$icontrol[2]}</textarea>";
			$html .= $icontrol[3];
			$html .= "</div></div>";
		}

		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-6\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Save</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}

	// To display a drop down select box of all available messages in the Advanced Queue form
	public function CreateMessageHTMLDropDown() {
		$html = "";
        $sql = "select m_uniqid, m_subject from messages order by m_id";
        $result = $this->dbconn->query($sql);
		$html .= "<option selected value=\"\">no message</option>";
		while($row = $result->fetch_array()) {
			$html .= "<option value=\"{$row['m_uniqid']}\">". htmlspecialchars($row['m_subject']) . "</option>";
		}
		$result->close();
		return $html;
	}

    // form has 4 message select fields, total volume of emails to queue, queue button
    public function CreateAdvancedQueueHTMLform() {
		$html = "";
		if ($this->user->uadmin <> '1') {
			$html .= "<p>Access denied</p>";
			return $html;
		}
		$html .= "<form name=\"advancedqueueform\" action=\"{$this->BaseURL}advanced-queue\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<fieldset><legend>Advanced Message Queue form</legend>";

		$msgDropDown = $this->CreateMessageHTMLDropDown();
		$select_text = array(
			0 => array("muid1","Select Message 1",$msgDropDown),
			1 => array("muid2","Select Message 2",$msgDropDown),
			2 => array("muid3","Select Message 3",$msgDropDown),
			3 => array("muid4","Select Message 4",$msgDropDown)
		);

		foreach($select_text as $scontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$scontrol[0]}\">{$scontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<select class=\"form-control\" name=\"{$scontrol[0]}\">{$scontrol[2]}</select>";
			$html .= "</div></div>";
		}

		$input_text = array(
			0 => array("mvolume","# of emails to send","number","200000")
		);

		foreach($input_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<input class=\"form-control\" type=\"{$icontrol[2]}\" size=\"100\" name=\"{$icontrol[0]}\" value=\"{$icontrol[3]}\">";
			$html .= "</div></div>";
		}

		$html .= "<div class=\"form-group\">";
		$html .= "<div class=\"col-md-offset-1 col-md-6\">";
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Advanced Queue</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
    }

	public function save() {
		$html = "";
		if ($this->msubject == "") {
			$this->msubject = "No subject";
		} else {
			$this->msubject = $this->dbconn->real_escape_string($this->msubject);
		}
		if ($this->mtid == 0) $this->mtid = 1;
		$this->mhtml = $this->dbconn->real_escape_string($this->mhtml);
		$this->mtext = $this->dbconn->real_escape_string($this->mtext);
		if ($this->muid == "") {
			$this->muid = $this->CreateMUID();
			$html .= "<p>tid: $this->mtid<br />subject: $this->msubject<br />muid: $this->muid</p>";
			$sql = "insert into messages (m_uniqid, m_t_id, m_from, m_subject, m_priority, m_html, m_text) values (\"$this->muid\",\"$this->mtid\",\"$this->mfrom\",\"$this->msubject\",\"$this->mpriority\",\"$this->mhtml\",\"$this->mtext\")";
    	} else {
			$sql = "update messages set m_t_id = \"$this->mtid\", m_from = \"$this->mfrom\", m_subject = \"$this->msubject\", m_priority = \"$this->mpriority\", m_html = \"$this->mhtml\", m_text = \"$this->mtext\" where m_uniqid = \"$this->muid\"";
    	}
    	$success = $this->dbconn->query($sql);
    	if ($success) {
    		$html .= "<p>message saved</p>";
    	} else {
    		$html .= "<P>DB ERROR {$this->dbconn->error}</p>";
    	}
    	return $html;
	}

	public function RetrieveMessage($muid) {
		// reset class message variables
		$this->mid = "0";
		$this->muid = "";
		$this->mtid = "0";
		$this->maid = "0";
		$this->mfrom = "";
		$this->mpriority = 0;
		$this->msubject = "";
		$this->mhtml = "";
		$this->mtext = "";

		// check that muid exists
		$sql = "select * from messages where m_uniqid = \"$muid\"";
		$result = $this->dbconn->query($sql);
		
		if ($this->dbconn->affected_rows == 0) {
			// $result->close();
			return false;
		}
		
		// Get the message
		$row = $result->fetch_array();
		$this->mid = $row["m_id"];
		$this->muid = $row["m_uniqid"];
		$this->mtid = $row["m_t_id"];
		$this->maid = $row["m_a_id"];
		$this->mfrom = $row["m_from"];
		$this->mpriority = $row["m_priority"];
		$this->msubject = stripslashes($row["m_subject"]);
		$this->mhtml = $row["m_html"];
		$this->mtext = $row["m_text"];
		
		// $result->close();
		return true;
	}

	// if m_datesentbegin is null then set to now()
	protected function SetMessageBeginDate($muid) {
		// select to check if m_datesentbegin is null
		$sql = "select m_datesentbegin from messages where m_uniqid = \"$muid\"";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_array();
		$mdatesentbegin = $row["m_datesentbegin"];
		// $result->close();
		
		// if null, update m_datesentbegin to now()
		if (is_null($mdatesentbegin) or ($mdatesentbegin == "0000-00-00 00:00:00")) {
			$sql = "update messages set m_datesentbegin = now() where m_uniqid = \"$muid\"";
			return $this->dbconn->query($sql);
		}
		
		return false;
	}

	// set m_datesentfinish to now()
	protected function SetMessageFinishDate($muid) {
		$sql = "update messages set m_datesentfinish = now() where m_uniqid = \"$muid\"";
		return $this->dbconn->query($sql);
	}

	public function GetMessageUID($mid) {
		$sql = "select m_uniqid from messages where m_id = \"$mid\"";
		$result = $this->dbconn->query($sql);
		if ($result->num_rows == 0) {
			$muid = '';
		} else {
			$row = $result->fetch_array();
			$muid = $row["m_uniqid"];
		}

		// $result->close();
		
		return $muid;
	}

	public function GetMessageUIDA($aid) {
		$sql = "select m_uniqid from messages where m_a_id = \"$aid\"";
		$result = $this->dbconn->query($sql);
		if ($result->num_rows == 0) {
			$muid = '';
		} else {
			$row = $result->fetch_array();
			$muid = $row["m_uniqid"];
		}

		// $result->close();
		
		return $muid;
	}

	public function CreateForwardHTMLform($suid,$mid) {
		$html = "";
		$subscriber = $this->fat->get('subscribers');
		if (!$subscriber->Read($suid)) {
			$html .= "<p>The subscriber does not exist.</p>";
			return $html;
		}
		$muid = $this->GetMessageUID($mid);
		if ($muid == '') {
			$html .= "<p>The message does not exist.</p>";
			return $html;
		}

		$html .= "<form name=\"forwardform\" action=\"{$this->BaseURL}forward\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<input name=\"suid\" type=\"hidden\" value=\"{$suid}\">";
		$html .= "<input name=\"mid\" type=\"hidden\" value=\"{$mid}\">";
		$html .= "<fieldset><legend>Forward Message</legend>";

		$textarea_text = array(
			0 => array("bemail","Forward to these emails"),
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
		$html .= "<button class=\"btn btn-primary\" type=\"submit\">Forward</button>";
		$html .= "</div></div>";
		$html .= "</fieldset></form>";
		return $html;
	}

	// Forwards the message to user entered email addresses and subscribes the emails. Must be a valid subscriber
	public function ForwardSubscribeMessage($suid,$mid,$bemail) {
		$html = "";
		$subscriber = $this->fat->get('subscribers');
		if (!$subscriber->Read($suid)) {
			$html .= "<p>The subscriber does not exist.</p>";
			return $html;
		}
        $femail = $subscriber->semail;  // store email address of the subscriber who is forwarding message
		$muid = $this->GetMessageUID($mid);
		if ($muid == '') {
			$html .= "<p>The message does not exist.</p>";
			return $html;
		}
		$txt = preg_replace('/\r\n|\r/', "\n", $bemail);
		$fcontents = explode("\n",trim($txt));
		$num = 0;
		for($i = 0; $i < sizeof($fcontents); $i++) {
			$semail = trim($fcontents[$i]);
			if ($semail <> '') {
				$subscriber->SimpleSubscribeAndConfirm($semail);
				$html .= "<p>$semail forwarded</p>";
				// logs which (new) email address the user forwarded the archive to
				$sql = "insert into forwards (f_s_uniqid, f_u_id, f_m_uniqid, f_when, f_email) values (\"$suid\",\"0\",\"$muid\",now(),\"$semail\")";
				$this->dbconn->query($sql);
				$this->SendToAddress($muid,$semail);
				// send notification message to admin
				$subject = "{$this->ListName} forward notification";
				$mhtml = "<p>{$this->ListName} message {$muid} has been forwarded to {$semail} by {$femail}</p>";
				$mtext = "{$this->ListName} message {$muid} has been forwarded to {$semail} by {$femail}";
				// $mailer = new mailer($this->dbconn);
				$mailer = $this->fat->get('mailer');
				$mailer->OpenSMTP();
				$mailer->SendMessage($subscriber->suid,"0","F",$this->FromAddress,$this->AdminEmail,$this->AdminName,$subject,$mhtml,$mtext,false);
				$mailer->CloseSMTP();
				$num++;
			}
		}
		$html .= "<p>Number forwarded: {$num}</p>";
		return $html;
	}

	public function ForwardSubscribeArchive($aid,$bemail) {
		$html = "";
		$uid = $this->user->uid;
		if ($uid == '0') {
			$html .= "<p>You are not logged in.</p>";
			return $html;
		}
		$muid = $this->GetMessageUIDA($aid);
		if ($muid == '') {
			$html .= "<p>The message does not exist.</p>";
			return $html;
		}
		$subscriber = $this->fat->get('subscribers');
		$txt = preg_replace('/\r\n|\r/', "\n", $bemail);
		$fcontents = explode("\n",trim($txt));
		$num = 0;
		for($i = 0; $i < sizeof($fcontents); $i++) {
			$semail = trim($fcontents[$i]);
			if ($semail <> '') {
				$subscriber->SimpleSubscribeAndConfirm($semail);
				$html .= "<p>$semail forwarded</p>";
				// logs which (new) email address the user forwarded the archive to
				$sql = "insert into forwards (f_s_uniqid, f_u_id, f_m_uniqid, f_when, f_email) values (\"\",\"$uid\",\"$muid\",now(),\"$semail\")";
				$this->dbconn->query($sql);
				$this->SendToAddress($muid,$semail);
				// send notification message to admin
				$subject = "{$this->ListName} forward notification";
				$mhtml = "<p>{$this->ListName} Message {$muid} has been forwarded to {$semail} by uid {$uid}.</p>";
				$mtext = "{$this->ListName} Message {$muid} has been forwarded to {$semail} by uid {$uid}.";
				// $mailer = new mailer($this->dbconn);
				$mailer = $this->fat->get('mailer');
				$mailer->OpenSMTP();
				$mailer->SendMessage($subscriber->suid,"0","F",$this->FromAddress,$this->AdminEmail,$this->AdminName,$subject,$mhtml,$mtext,false);
				$mailer->CloseSMTP();
				$num++;
			}
		}
		$html .= "<p>Number forwarded: {$num}</p>";
		return $html;
	}

	public function SendToAddress($muid,$semail) {
		$html = "";
		// Retrieve the message we wish to send
		$msgexist = $this->RetrieveMessage($muid);
		if (!$msgexist) {
			$html .= "<p>Message not found.</p>";
			return $html;
		}
		if ($semail == "") {
			$html .= "<p>BLANK address. aborting.</p>";
			return $html;
		}
		// $subscriber = new subscribers($this->dbconn);
		$subscriber = $this->fat->get('subscribers');
		$subscribed = $subscriber->SimpleSubscribeAndConfirm($semail);
		$suid = $subscriber->suid;
		$success = $subscriber->RetrieveSubscriber($suid);
		if (!$success) {
			$html .= "<p>invalid address</p>";
			return $html;
		}
		$semail = $subscriber->semail;
		// $template = new templates($this->dbconn);
		$template = $this->fat->get('templates');
		$template->RetrieveTemplate($this->mtid);
		// Replace the {content} {archive} {advertise} {subscribe} global template placeholders for every recipient of this message
		// or if the template does not exist, just use the message parts.
		// this function MUST be called after RetrieveMessage or a blank message will be sent
		$template->MergeTemplate($this->mhtml,$this->mtext,$this->maid);
		$template->MergeSubscriberDetail($suid,$this->mid,$subscriber->sfname,$subscriber->slname,$subscriber->semailsleft);
		// Open the Swift SMTP connection
		// $mailer = new mailer($this->dbconn);
		$mailer = $this->fat->get('mailer');
		$html .= "<p>Opening SMTP connection to ". (string)$this->default_smtp_server['host'] . "</p>";
		$success = $mailer->OpenSMTP();
		if (!$success) {
			$html .= "<p>FAIL: could not open connection to SMTP server!</p>";
			return $html;
		} else {
			$html .= "<p>SUCCESS: opened connection to SMTP server!</p>";
		}
		$sname = trim($subscriber->sfname . ' ' . $subscriber->slname);
		$html .= "<p>Sending to $sname $semail ";
		$sent = $mailer->SendMessage($suid,$this->mid,"M",$this->mfrom,$semail,$sname,$this->msubject,$template->shtml,$template->stext,false);
		// if success, then write entry to screen to show the subscriber was sent the message
		if ($sent == 1) {
			$html .= "SUCCESS</p>";
		} else {
			$html .= "FAIL</p>";
		}
		// Close the Swift SMTP connection
		$mailer->CloseSMTP();
		return $html;
	}

	// this is called when a subscriber opens a message. in order for this to work, they need to be online and be displaying images
	// when a message is viewed, I log which subscriber viewed which message at what date time.
	// the number of message views is incremented in the messages table
	// the subscriber gets their bounce count reset to 0 and their subscription is renewed
	public function TrackOpen($suid,$mid) {
		if (($suid <> '') && ($mid <> 0)) {
			// escape these variables to prevent injection attacks
			$suid = $this->dbconn->real_escape_string($suid);
			$mid = $this->dbconn->real_escape_string($mid);
			
			// write entry into smopens
			$sql = "insert into smopens (smo_s_uniqid, smo_m_id, smo_dateopened) values (\"$suid\",\"$mid\",now())";
			$this->dbconn->query($sql);
		
			// update m_viewed in messages
			$sql = "update messages set m_viewed = m_viewed + 1 where m_id = \"$mid\"";
			$this->dbconn->query($sql);
			
			// reset subscriber bounce count to 0 and reset s_emailsleft to SubscriptionConfirmAmount
			$bounces = 0;
			$emailsleft = $this->SubscriptionConfirmAmount;
			// $sql = "update subscribers set s_unsubscribe = \"0\", s_bounces = \"$bounces\", s_emailsleft = \"$emailsleft\", s_priority = s_priority + 10 where s_uniqid = \"$suid\"";
            $sql = "update subscribers set s_bounces = \"$bounces\", s_emailsleft = \"$emailsleft\", s_priority = s_priority + 5 where s_uniqid = \"$suid\"";
			$this->dbconn->query($sql);
	
			/* 
			 * this code no longer works due to immediate deletion of the subscriber from the local table when globally unsubscribing. we can no longer ascertain which email address the suid resolves to.
			 *
			$subscriber = new subscribers($this->dbconn);
			$subscriber->RetrieveSubscriber($suid);
			$semail = $subscriber->semail;
			
			$sql = "delete from globalunsubscribe where gu_email = \"$semail\";";
			$this->globaldb->query($sql);
			*/
		}
	}
	
	// Queues a message for sending
	public function SendListToQueue($muid) {
		$html = "";
		set_time_limit(0);
		// Retrieve the message we wish to send
		$msgexist = $this->RetrieveMessage($muid);
		if (!$msgexist) {
			$html .= "<p>Message not found.</p>";
			return $html;
		}
		$mpriority = $this->mpriority;
		// $template = new templates($this->dbconn);
		$template = $this->fat->get('templates');
		$template->RetrieveTemplate($this->mtid);
		// Creating the archive is part of Sending to the queue because it is the only place that archive creation occurs
		// Check if archive for message exists. If not, create it.
		if (($this->maid == "0") or (is_null($this->maid))) {
			// $archive = new archives($this->dbconn);
			$archive = $this->fat->get('archives');
			$this->maid = $archive->CreateArchive($muid,$this->msubject,$this->mhtml,$this->mtext,$template);
		}
		// get all the subscribers who are not unsubscribed, confirmed and not blacklisted and who have not been sent the message previously
		// bitwise AND m_interests and s_interests, if > 0, send message to the subscriber
		// $sql = "select s_id, s_uniqid, s_priority, s_bounces, s_emailsleft, sms_m_id from subscribers left join smsent on (s_id = sms_s_id) and (sms_m_id = \"$this->mid\") where (s_unsubscribe = \"0\") and (s_confirm = \"1\") and (s_blacklist = \"0\") and (s_emailsleft > \"0\") and (sms_m_id is null) and ((s_interests & $this->minterests) > \"0\") order by s_priority DESC, s_email ASC";
		$sql = "select s_id, s_uniqid, s_priority, s_bounces, s_emailsleft, sms_m_id from subscribers left join smsent on (s_id = sms_s_id) and (sms_m_id = \"$this->mid\") where (s_unsubscribe = \"0\") and (sms_m_id is null) ORDER BY s_priority DESC, s_email ASC LIMIT 1000000";
		$result = $this->dbconn->query($sql);
		$numsubscribers = $this->dbconn->affected_rows;
		$html .= "<p>Queuing message to $numsubscribers subscribers</p><p>";
		// variable holds the number of messages sent
		$numsent = 0;
		// if m_datesentbegin is null then set to now()
		$this->SetMessageBeginDate($muid);
        // $queue = new queue($this->dbconn);
		$queue = $this->fat->get('queue');
		while ($row = $result->fetch_array()) {
			$sid = $row["s_id"];
			$suid = $row["s_uniqid"];
			$sbounces = (int)$row["s_bounces"];
			$spriority = (int)$row["s_priority"];
			$semailsleft = (int)$row["s_emailsleft"];
			// add the message priority and the subscriber priority to get the queue entry priority
			$priority = $mpriority + $spriority;
			// add to queue
			$success = $queue->AddToQueue($muid,$suid,$priority);
			// if success, then write entry to smsent to show the subscriber was sent the message
			if ($success) {
				// start a transaction
				$starttransaction = $this->dbconn->autocommit(false);
				// ensure that all these queries succeed
				// $sql = "insert into smsent (sms_s_id, sms_m_id, sms_datesent) values (\"$sid\",\"$this->mid\",now())";
                $sql = "insert into smsent (sms_s_id, sms_m_id) values (\"$sid\",\"$this->mid\")";
				$this->dbconn->query($sql);
				// also need to update the number sent to in the messages table
				$sql = "update messages set m_sent = m_sent + 1 where m_uniqid = \"$muid\"";
				$this->dbconn->query($sql);
				// decrement subscriber bounce count by 1 whenever a new message is sent
				// decrement the number of emails the subscriber has left to receive before they have to re-confirm. opening a message resets this to the full subscription amount
				$sbounces = max(0,$sbounces - 1);
				$spriority = max(0,$spriority - 1);
				$semailsleft = max(0,$semailsleft - 1);
				$sql = "update subscribers set s_bounces = \"$sbounces\", s_priority = \"$spriority\", s_emailsleft = \"$semailsleft\" where s_uniqid = \"$suid\"";
				$this->dbconn->query($sql);
				$committransaction = $this->dbconn->commit();
				$endtransaction = $this->dbconn->autocommit(true);
			}	else {
				$html .= "<p>Error queueing last message. Aborting script. This is normal. Please re-queue this message to continue, until you get a 'Finished queueing' message.</p>";
				// $result->close();
				return $html;
			}
			// script is allowed to run for unlimited amount of time.
			set_time_limit(0);
			// increment the variable containing the number of messages sent
			$numsent++;
			/*
			if ($numsent % 1000 == 0) echo ".";
			if ($numsent % 10000 == 0) echo "</p><p>";
			*/
		}
		// $result->close();
		// set m_datesentfinish to now()
		if ($numsent > 0) {
			$this->SetMessageFinishDate($muid);
		}
		$html .= "<p>Finished queueing: $numsent</p>";
		return $html;
	}

  public function AdvancedSendListToQueue($muids,$mvolume) {
	set_time_limit(0);
	$i = 0;
	$mids = array();
	foreach($muids as $muid) {
      $this->RetrieveMessage($muid);
      $mids[$i++] = $this->mid;
      $this->SetMessageBeginDate($muid);
    }
    $queue = $this->fat->get('queue');
    $sql = "select s_id, s_uniqid from subscribers where s_unsubscribe = \"0\" ORDER BY s_priority DESC, s_email ASC limit $mvolume";
    // echo "<p>" . $sql . "</p>";
    $result = $this->dbconn->query($sql);
    if (!$result) {
    	// echo "<p>SELECT ERROR: " . (string)$this->dbconn->error . "</p>";
    	return false;
    }
    $i = 0;
    $nummsgs = count($muids);
    while ($row = $result->fetch_array()) {
      set_time_limit(0);
      $sid = $row["s_id"];
      $suid = $row["s_uniqid"];
      $queue->AddToQueue($muids[$i],$suid,100);
      $sql = "update messages set m_sent = m_sent + 1 where m_uniqid = \"$muids[$i]\"";
      $this->dbconn->query($sql);
      foreach($mids as $mid) {
        $sql = "insert into smsent (sms_s_id, sms_m_id, sms_datesent) values (\"$sid\",\"$mid\",now())";
        $this->dbconn->query($sql);
      }
      $i++;
      $i = $i % $nummsgs; // next msg, wrap around to start at end
    }
    $result->close();
    foreach($muids as $muid) {
      $this->SetMessageFinishDate($muid);
    }
    return true;
  }

}
?>