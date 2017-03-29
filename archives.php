<?php
/*

Module: archives class
Version: 1.2
Author: Richard Catto
Creation Date: 2009-08-10

Description:
This class handles all the archives related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

*/

class archives {

	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;

	// archive variables
	public $aid;
	public $asubject;
	public $adatecreated;
	public $ahtml;
	
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
	}

	function __destruct() {
	}

	// create a browseable list of available archives
	public function CreateArchivesHTMLList($pageno,$numrows) {
		$html = "";
		$sql = "select count(*) from archives";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>There are no archives.</p>";
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
		
		$sql = "select a_id, a_subject, a_datecreated, a_viewed from archives order by a_id desc $limit";
		$result = $this->dbconn->query($sql);
		
		if ($this->user->uadmin == '1') {
			$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Subject</th><th>Created On</th><th>Viewed</th></tr></thead><tbody>";
		} else {
			$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Subject</th><th>Created On</th></tr></thead><tbody>";
		}
		
		while($row = $result->fetch_array()) {
			$aid = $row["a_id"];
			$asubject = $row["a_subject"];
			$adatecreated = $row['a_datecreated'];
			$aviewed = $row['a_viewed'];
			if ($this->user->uadmin == '1') {
				$html .= "<tr><td><a href=\"{$this->BaseURL}archive/$aid\">$asubject</a></td><td>$adatecreated</td><td>$aviewed</td></tr>";
			} else {
				$html .= "<tr><td><a href=\"{$this->BaseURL}archive/$aid\">$asubject</a></td><td>$adatecreated</td></tr>";
			}
		}

		$html .= "</tbody></table>";
		
		$result->close();

		$html .= "<p>Page $pageno of $lastpage - $totalmatches records found</p>";

		// add in navigation to browse large results lists.
		$html .= "<ul class=\"pager\">";
		if ($pageno == 1) {
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">First</li>";
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">Previous</li>";
		} else {
		  $prevpage = $pageno - 1;
		  $html .= "<li class=\"previous\"><a href='{$this->BaseURL}archives/1'>First</a></li>";
		  $html .= "<li class=\"previous\"><a href='{$this->BaseURL}archives/$prevpage'>Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href='{$this->BaseURL}archives/$lastpage'>Last</a></li>";
		  $html .= "<li class=\"next\"><a href='{$this->BaseURL}archives/$nextpage'>Next</a></li>";
		}
		$html .= "</ul>";

		return $html;
	}

	// Create a new entry in archives. This is called once per message, the first time it is sent to the list
	// An archive is not editable, so changes made after it being sent are not reflected in the archive
	public function CreateArchive($muid,$msubject,$mhtml,$mtext,$template) {
		$subject = $this->dbconn->real_escape_string($msubject);
		$sql = "insert into archives (a_subject, a_datecreated, a_viewed) values (\"$subject\",now(),\"0\")";
		$this->dbconn->query($sql);
		
		// get the a_id (the insert_id property contains the last auto generated id)
		$this->aid = $this->dbconn->insert_id;

		$template->MergeTemplate($mhtml,$mtext,$this->aid);
		$template->MergeSubscriberDetail("0","0","","",0);
		$this->ahtml = $this->dbconn->real_escape_string($template->shtml);

		// update archives with ahtml
		$sql = "update archives set a_html = '$this->ahtml' where a_id = '$this->aid'";
		$this->dbconn->query($sql);
		
		// update the message record with the corresponding a_id
		$sql = "update messages set m_a_id = \"$this->aid\" where m_uniqid = \"$muid\"";
		$this->dbconn->query($sql);
		
		return $this->aid;
	}

	public function RetrieveArchive($aid) {
		$this->aid = "0";
		$this->auid = "0";
		$this->asubject = "";
		$this->adatecreated = "";
		$this->ahtml = "";

		$sql = "select a_id, a_subject, a_html, a_datecreated from archives where a_id = \"$aid\"";
		$result = $this->dbconn->query($sql);
		if ($this->dbconn->affected_rows == 1) {
			$row = $result->fetch_array();
			$this->aid = $row["a_id"];
			$this->asubject = stripslashes($row["a_subject"]);
			$this->ahtml = $row["a_html"];
			$this->adatecreated = $row["a_datecreated"];
			$result->close();
			return true;
		} else {
			$result->close();
			return false;
		}
	}

	public function ShowArchive($aid) {
		$html = "";
		if ($this->user->uloggedin) {
			$html .= "<form name=\"forwardarchiveform\" action=\"{$this->BaseURL}forward-archive\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
			$html .= "<input name=\"aid\" type=\"hidden\" value=\"{$aid}\">";
			$html .= "<fieldset><legend>Forward Archive Message</legend>";

			$textarea_text = array(
				0 => array("bemail","Forward to these emails"),
			);

			foreach($textarea_text as $icontrol) {
				$html .= "<div class=\"form-group\">";
				$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
				$html .= "<div class=\"col-md-6\">";
				$html .= "<textarea class=\"form-control\" rows=\"5\" cols=\"90\" name=\"{$icontrol[0]}\"></textarea>";
				$html .= "</div></div>";
			}

			$html .= "<div class=\"form-group\">";
			$html .= "<div class=\"col-md-offset-1 col-md-6\">";
			$html .= "<button class=\"btn btn-primary\" type=\"submit\">Forward</button>";
			$html .= "</div></div>";
			$html .= "</fieldset></form>";
		} else {
			$html .= "<p><b>If you were logged in, you could forward this message on to others.</b></p>";
		}

		$archiveexist = $this->RetrieveArchive($aid);
		if ($archiveexist) {
			$html .= "<p>Date created: $this->adatecreated</p>";
			$html .= "<p>Subject: $this->asubject</p>";
			$html .= $this->ahtml;
			// increment a_viewed
			$sql = "update archives set a_viewed = a_viewed + 1 where a_id = \"$aid\"";
			$this->dbconn->query($sql);
			
			// TODO update saopens table (which currently does not exist) with $suid $aid dateviewed if we wish to track who viewed an archive and when
			// I would also need to pass the $suid to this function for that to work
		}
		else {
			$html .= "<p>Archive does not exist.</p>";
		}
		return $html;
	}

}
?>