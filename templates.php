<?php
/*

Module: templates class
Version: 1.2
Author: Richard Catto
Creation Date: 2009-08-13
Milestones:

Description:
This class handles all the template related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

*/

class templates {

	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;
    protected $ListName;
    protected $Domain;

	// template variables - must be public for forms
	public $templateexist;
	public $tid;
	public $tname;
	public $thtml;
	public $ttext;

	// holds template message parts with global placeholders merged in
	protected $htmlpart;
	protected $textpart;

	// holds template message parts with both global and subscriber specific placeholders merged in
	// i.e. holds the message parts to send
	public $shtml;
	public $stext;
	
	// create all necessary tables in constructor
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
        $this->ListName = $fat->get('ListName');
        $this->Domain = $fat->get('Domain');
	}

	function __destruct() {
	}

	// HTML output methods

	// To display a drop down select box in the edit messages form
	public function CreateTemplateHTMLDropDown($tid) {
		$html = "";
		// add selected to the option selected by $tid
		$sql = "select t_id, t_name from templates order by t_name";
		$result = $this->dbconn->query($sql);
		if ($tid == "0") {
			$html .= "<option selected value=\"0\">none</option>";
		}
		else {
			$html .= "<option value=\"0\">none</option>";
		}
		while($row = $result->fetch_array()) {
			$html .= "<option " . ($tid == $row["t_id"] ? "selected " : "") . "value=" . '"' . $row["t_id"] . '"' . ">" . $row["t_name"] . "</option>";
		}
		// $result->close();
		return $html;
	}

	// To display a paginated list of templates defined in the system
	public function CreateTemplatesHTMLList($pageno,$numrows) {
		$html = "";
		if ($this->user->uadmin == '1') {
			$html .= "<p><a href=\"{$this->BaseURL}template\">Create new template</a></p>";
		} else {
			$html .= "<p>Please login as administrator in order to view this page.</p>";
        	return $html;
		}

		$sql = "select count(*) from templates";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>There are no templates.</p>";
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
		
		$sql = "select t_id, t_name from templates order by t_name $limit";
		$result = $this->dbconn->query($sql);
		
		$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Name of template</th></tr></thead><tbody>";
		
		while($row = $result->fetch_array()) {
			$tid = $row["t_id"];
			$tname = $row["t_name"];
			$html .= "<tr><td><a href=\"{$this->BaseURL}template/{$tid}\">$tname</a></td></tr>";
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
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}templates/1\">First</a></li>";
		  $html .= "<li class=\"previous\"><a href=\"{$this->BaseURL}templates/{$prevpage}\">Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}templates/{$lastpage}\">Last</a></li>";
		  $html .= "<li class=\"next\"><a href=\"{$this->BaseURL}templates/{$nextpage}\">Next</a></li>";
		}
		$html .= "</ul>";

		return $html;
	}

	public function CreateTemplateHTMLform($tid = 0) {
		$html = "";
		if ($this->user->uadmin <> '1') {
			$html .= "<p>Access denied</p>";
			return $html;
		}
		if ($tid == 0) {
			$tname = "";
			$thtml = "";
			$ttext = "";
		} elseif (!$this->RetrieveTemplate($tid)) {
			$html .= "<p>Template does not exist</p>";
			return $html;
		}
		$html .= "<form name=\"templateform\" action=\"{$this->BaseURL}template\" method=\"post\" class=\"form-horizontal\" role=\"form\">";
		$html .= "<input name=\"tid\" type=\"hidden\" value=\"$tid\">";
		$html .= "<fieldset><legend>Template form</legend>";

		$input_text = array(
			0 => array("tname","Template name","text",$this->tname)
		);

		foreach($input_text as $icontrol) {
			$html .= "<div class=\"form-group\">";
			$html .= "<label class=\"col-md-1 control-label\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
			$html .= "<div class=\"col-md-6\">";
			$html .= "<input class=\"form-control\" type=\"{$icontrol[2]}\" size=\"100\" name=\"{$icontrol[0]}\" value=\"{$icontrol[3]}\">";
			$html .= "</div></div>";
		}

		$textarea_text = array(
			0 => array("thtml","HTML part",$this->thtml,"<script type=\"text/javascript\">CKEDITOR.replace( 'thtml', { width : '920px', height : '600px' });</script></p>"),
			1 => array("ttext","Text part",$this->ttext,"")
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

	public function save() {
		$html = "";
		$this->tname = $this->dbconn->real_escape_string($this->tname);
		$this->thtml = $this->dbconn->real_escape_string($this->thtml);
		$this->ttext = $this->dbconn->real_escape_string($this->ttext);
		if ($this->tid == 0) {
			$sql = "insert into templates (t_name, t_html, t_text) values (\"$this->tname\",\"$this->thtml\",\"$this->ttext\")";
		} else {
			$sql = "update templates set t_name = \"$this->tname\", t_html = \"$this->thtml\", t_text = \"$this->ttext\" where t_id = \"$this->tid\"";
		}
		$success = $this->dbconn->query($sql);
    	if ($success) {
    		$html .= "<p>Template saved</p>";
    	} else {
    		$html .= "<P>DB ERROR {$this->dbconn->error}</p>";
    	}
		return $html;
	}

  	// use to create a template from a file
	public function CreateTemplate($tname,$hfname,$tfname) {
		$fhcontents = addslashes(file_get_contents($hfname));
		$ftcontents = addslashes(file_get_contents($tfname));
		$sql = "insert into templates (t_name, t_html, t_text) values (\"$tname\",\"$fhcontents\",\"$ftcontents\")";
		return $this->dbconn->query($sql);
	}

	// create a new template
	public function CreateTemplateForm($tname,$thtml,$ttext) {
		$tname = $this->dbconn->real_escape_string($tname);
		$thtml = $this->dbconn->real_escape_string($thtml);
		$ttext = $this->dbconn->real_escape_string($ttext);

		$sql = "insert into templates (t_name, t_html, t_text) values (\"$tname\",\"$thtml\",\"$ttext\")";
		return $this->dbconn->query($sql);
	}
	
	// save changes to a template
	public function UpdateTemplate($tid,$tname,$thtml,$ttext) {
		$tname = $this->dbconn->real_escape_string($tname);
		$thtml = $this->dbconn->real_escape_string($thtml);
		$ttext = $this->dbconn->real_escape_string($ttext);

		$sql = "update templates set t_name = \"$tname\", t_html = \"$thtml\", t_text = \"$ttext\" where t_id = \"$tid\"";
		return $this->dbconn->query($sql);
	}

	public function RetrieveTemplate($tid) {
		// reset class template variables
		$this->templateexist = false;
		$this->tid = "0";
		$this->tname = "";
		$this->thtml = "";
		$this->ttext = "";

		$sql = "select t_name, t_html, t_text from templates where t_id = \"$tid\"";
		$result = $this->dbconn->query($sql);
		if ($this->dbconn->affected_rows == 1) {
			$row = $result->fetch_array();
			$this->templateexist = true;
			$this->tid = $tid;
			$this->tname = $row["t_name"];
			$this->thtml = $row["t_html"];
			$this->ttext = $row["t_text"];
			$result->close();
			return true;
		} else {
			$result->close();
			return false;
		}
	}

	// Replace the {content} {archive} {advertise} {subscribe} global placeholders
	// TODO {bannerads} {textads}
	// RetrieveTemplate must be called before using this method
	public function MergeTemplate($mhtml,$mtext,$maid) {
		if ($this->templateexist) {
			$this->htmlpart = str_ireplace("{content}",$mhtml,$this->thtml);
			$this->textpart = str_ireplace("{content}",$mtext,$this->ttext);
			
			// Archive must take a special snapshot of this email and store it in archives
			$this->htmlpart = str_ireplace("{archive}","<a href=\"{$this->BaseURL}archive/{$maid}\">ARCHIVE</a>",$this->htmlpart);
			$this->textpart = str_ireplace("{archive}","{$this->BaseURL}archive/$maid",$this->textpart);

			if ($this->fat->exists('AdvertiseURL')) {
				$AdvertiseURL = $this->fat->get('AdvertiseURL');
				$this->htmlpart = str_ireplace("{advertise}","<a href=\"{$AdvertiseURL}\">ADVERTISE</a>",$this->htmlpart);
				$this->textpart = str_ireplace("{advertise}",$AdvertiseURL,$this->textpart);
			}

			if ($this->fat->exists('FacebookPageURL')) {
				$FacebookPageURL = $this->fat->get('FacebookPageURL');
				$this->htmlpart = str_ireplace("{facebook}","<a href=\"{$FacebookPageURL}\">FACEBOOK</a>",$this->htmlpart);
				$this->textpart = str_ireplace("{facebook}",$FacebookPageURL,$this->textpart);
			}

			if ($this->fat->exists('TwitterURL')) {
				$TwitterURL = $this->fat->get('TwitterURL');
				$this->htmlpart = str_ireplace("{twitter}","<a href=\"{$TwitterURL}\">TWITTER</a>",$this->htmlpart);
				$this->textpart = str_ireplace("{twitter}",$TwitterURL,$this->textpart);
			}
			
			$this->htmlpart = str_ireplace("{subscribe}","<a href=\"{$this->BaseURL}subscribe\">SUBSCRIBE</a>",$this->htmlpart);
			$this->textpart = str_ireplace("{subscribe}","{$this->BaseURL}subscribe",$this->textpart);
          
  			$this->htmlpart = str_ireplace("{listname}",$this->ListName,$this->htmlpart);
			$this->textpart = str_ireplace("{listname}",$this->ListName,$this->textpart);

  			$this->htmlpart = str_ireplace("{domain}",$this->Domain,$this->htmlpart);
			$this->textpart = str_ireplace("{domain}",$this->Domain,$this->textpart);
		}
		else {
			// no template exists, so just use the message parts
			$this->shtml = $mhtml;
			$this->stext = $mtext;
		}
	}

	// For each individual subscriber replace the {unsubscribe} {forward} {preferences} {usertrack} placeholders
	// TODO {firstname} {lastname}
	public function MergeSubscriberDetail($suid,$mid,$fname,$lname,$emailsleft) {
		if ($this->templateexist) {
			if ($suid == "0") {
				$this->shtml = str_ireplace("{unsubscribe}","SAFE UNSUBSCRIBE",$this->htmlpart);
				$this->stext = str_ireplace("{unsubscribe}","SAFE UNSUBSCRIBE",$this->textpart);
			
				$this->shtml = str_ireplace("{forward}","FORWARD",$this->shtml);
				$this->stext = str_ireplace("{forward}","FORWARD",$this->stext);
			
				$this->shtml = str_ireplace("{preferences}","UPDATE PROFILE",$this->shtml);
				$this->stext = str_ireplace("{preferences}","UPDATE PROFILE",$this->stext);

				$this->shtml = str_ireplace("{firstname}","",$this->shtml);
				$this->stext = str_ireplace("{firstname}","",$this->stext);

				$this->shtml = str_ireplace("{lastname}","",$this->shtml);
				$this->stext = str_ireplace("{lastname}","",$this->stext);

				$this->shtml = str_ireplace("{subscription}","",$this->shtml);
				$this->stext = str_ireplace("{subscription}","",$this->stext);

				$this->shtml = str_ireplace("{emailsleft}","",$this->shtml);
				$this->stext = str_ireplace("{emailsleft}","",$this->stext);
				
				$this->shtml = str_ireplace("{confirm}","CONFIRM",$this->shtml);
				$this->stext = str_ireplace("{confirm}","CONFIRM",$this->stext);							

				$this->shtml = str_ireplace("{usertrack}","",$this->shtml);
			}
			else {
				$this->shtml = str_ireplace("{unsubscribe}","<a href=\"{$this->BaseURL}unsubscribe/{$suid}\">SAFE UNSUBSCRIBE</a>",$this->htmlpart);
				$this->stext = str_ireplace("{unsubscribe}","{$this->BaseURL}unsubscribe/{$suid}",$this->textpart);
			
				$this->shtml = str_ireplace("{forward}","<a href=\"{$this->BaseURL}forward/{$suid}/{$mid}\">FORWARD</a>",$this->shtml);
				$this->stext = str_ireplace("{forward}","{$this->BaseURL}forward/{$suid}/{$mid}",$this->stext);
			
				$this->shtml = str_ireplace("{preferences}","<a href=\"{$this->BaseURL}subscribe/{$suid}\">UPDATE PROFILE</a>",$this->shtml);
				$this->stext = str_ireplace("{preferences}","{$this->BaseURL}subscribe/{$suid}",$this->stext);

				$this->shtml = str_ireplace("{firstname}",$fname,$this->shtml);
				$this->stext = str_ireplace("{firstname}",$fname,$this->stext);

				$this->shtml = str_ireplace("{lastname}",$lname,$this->shtml);
				$this->stext = str_ireplace("{lastname}",$lname,$this->stext);
				
				if ($emailsleft <= $this->fat->get('SubscriptionConfirmLevel')) {
					$submsg = $this->fat->get('SubscriptionConfirmMessage');
				} else {
					$submsg = $this->fat->get('SubscriptionMessage');
				}
				
				$this->shtml = str_ireplace("{subscription}",$submsg,$this->shtml);
				$this->stext = str_ireplace("{subscription}",$submsg,$this->stext);

				$this->shtml = str_ireplace("{emailsleft}",$emailsleft,$this->shtml);
				$this->stext = str_ireplace("{emailsleft}",$emailsleft,$this->stext);

				$this->shtml = str_ireplace("{confirm}","<a href=\"{$this->BaseURL}confirm/{$suid}\">CONFIRM SUBSCRIPTION</a>",$this->shtml);
				$this->stext = str_ireplace("{confirm}","{$this->BaseURL}confirm/{$suid}",$this->stext);							

				$this->shtml = str_ireplace("{usertrack}","<img src=\"{$this->BaseURL}ut/{$suid}/{$mid}\" width=\"0\" height=\"0\">",$this->shtml);
			}
		}
	}
}
?>