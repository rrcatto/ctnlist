<?php
/*

Module: sendlog class
Author: Richard Catto
Creation Date: 2011-10-25

*/

class sendlog {
	
	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;
	
	// $dbconn is a mysqli connection object to an open MySQL database
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
	}

	function __destruct() {
	}

	// message stats
	public function ShowStats() {
		$html = "";
		// $sql = "SELECT YEAR(sl_datesent) as sl_year, MONTH(sl_datesent) as sl_month, sl_method, SUM(sl_numsent) AS sl_totalsent FROM sendlog GROUP BY YEAR(sl_datesent), MONTH(sl_datesent), sl_method";
		// $sql = "SELECT YEAR(sl_datesent) as sl_year, MONTH(sl_datesent) as sl_month, sl_method, SUM(sl_numsent) AS sl_totalsent FROM sendlog GROUP BY sl_year, sl_month, sl_method";
		$sql = "SELECT YEAR(sl_datesent) as sl_year, MONTH(sl_datesent) as sl_month, sl_type, SUM(sl_numsent) AS sl_totalsent FROM sendlog GROUP BY sl_year, sl_month, sl_type";
		$result = $this->dbconn->query($sql);
		$html .= "<h3>Method: Total sent</h3>";
		while ($row = $result->fetch_array()) {
			$slyear = $row["sl_year"];
			$slmonth = $row["sl_month"];
			// $slmethod = $row["sl_method"];
			$sltype = $row["sl_type"];
			$sltotalsent = $row["sl_totalsent"];
            // $dstring = sprintf("<p>%04d-%02d %s: %d</p>",$slyear,$slmonth,$slmethod,$sltotalsent);
            $dstring = sprintf("<p>%04d-%02d %s: %d</p>",$slyear,$slmonth,$sltype,$sltotalsent);
            $html .= $dstring;
			// $html .= "<p>{$slyear}-{$slmonth} {$slmethod}: {$sltotalsent}</p>";
		}
		$result->close();
		return $html;
	}
	
	public function CreateSendlogHTMLList($ssemail,$pageno,$numrows) {
		$html = "";
		if ($this->user->uadmin == '1') {
			$html .= $this->ShowStats();
			$html .= "<form name=\"sendlogform\" action=\"{$this->BaseURL}sendlog\" method=\"get\" class=\"form-inline\" role=\"form\">";
			$html .= "<fieldset><legend>Search by email address</legend>";
			$html .= "<div class=\"form-group\"><div class=\"col-md-3\"><input class=\"form-control\" name=\"e\" type=\"search\" maxlength=\"255\" value=\"$ssemail\"></div></div>";
			$html .= "<div class=\"form-group\"><button class=\"btn btn-primary\" type=\"submit\">Email Search</button></div>";
			$html .= "</fieldset></form><hr>";
		} else {
			$html .= "<p>Access denied</p>";
			return $html;
		}

		$sql = "select count(*) from sendlog where sl_toemail like \"%$ssemail%\"";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>No emails match that search.</p>";
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
		$sql = "SELECT sl_id, sl_datesent, sl_type, sl_method, sl_numsent, sl_toemail, sl_subject FROM sendlog where sl_toemail like \"%$ssemail%\" order by sl_datesent DESC $limit";
		$result = $this->dbconn->query($sql);

		$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Date sent</th><th>Type</th><th>Method</th><th>Num sent</th><th>TO email</th><th>Subject</th></tr></thead><tbody>";

		while ($row = $result->fetch_array()) {
			$slid = $row["sl_id"];
			$sldatesent = $row["sl_datesent"];
			$sltype = $row["sl_type"];
			$slmethod = $row["sl_method"];
			$slnumsent = $row['sl_numsent'];
			$sltoemail = $row["sl_toemail"];
			$slsubject = stripslashes($row["sl_subject"]);
			$html .= "<tr><td>$sldatesent</td><td>$sltype</td><td>$slmethod</td><td>$slnumsent</td><td>$sltoemail</td><td>$slsubject</td></tr>";
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
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}sendlog/1/{$querystring}\">First</a></li>";
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}sendlog/{$prevpage}/{$querystring}\">Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}sendlog/{$lastpage}/{$querystring}\">Last</a></li>";
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}sendlog/{$nextpage}/{$querystring}\">Next</a></li>";
		}
		$html .= "</ul>";
		return $html;	
	}
}
?>