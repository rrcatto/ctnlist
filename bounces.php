<?php
/*
 * BOUNCE HANDLER Class, Version 6.x
 * Description: "chops up the bounce into associative arrays"
 *     ~ http://www.phpclasses.org/browse/file/11665.html
 */

class bounces {

	protected $dbconn;
	public $strEmail;
	public $bounce;
	public $head;
	public $body;

	public $head_hash = array();
	public $fbl_hash = array();
	public $body_hash = array();

	public $looks_like_a_bounce = false;
	public $looks_like_an_FBL = false;
	public $is_hotmail_fbl = false;
    
	// these are for feedback reports, so you can extract uids from the emails
	// eg X-my-custom-header: userId12345
	// eg <img src="http://mysite.com/track.php?u=userId12345">

	// from regular-expressions.info to find a valid email address
	public $find_email_preg = "/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4})\b/i";

	// from http://fightingforalostcause.net/misc/2006/compare-email-regex.php
	// public $find_email_preg = "/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD";

	
	public $subject_beacon_preg = "/[0-9a-fA-F]{32}/";
	public $web_beacon_preg_1 = "/u=([0-9a-fA-F]{32})/";
	public $web_beacon_preg_2 = "/m=(\d*)/";
	public $header_preg_1 = "/X-ctnlist-suid: ([0-9a-fA-F]{32})/";
	public $header_preg_2 = "/X-ctnlist-mid: (\d*)/";
	public $x_header_search_1 = "X-ctnlist-suid";
	public $x_header_search_2 = "X-ctnlist-mid";

	// accessors
	public $type = "";
	public $web_beacon_1 = "";
	public $web_beacon_2 = "";
	public $feedback_type = "";
	public $x_header_beacon_1 = "";
	public $x_header_beacon_2 = "";
    
	// these accessors are useful only for FBL's
	// or if the output array has only one index
	public $action = "";
	public $status = "";
	public $subject = "";
	public $recipient = "";

	// the raw data set, a multiArray
	public $output = array();

	public function __construct($dbconn,$strEmail) {
		$this->dbconn = $dbconn;
		$this->strEmail = $strEmail;

		$this->bounce = $this->init_bouncehandler($this->strEmail);
		list($head, $body) = preg_split("/\r\n\r\n/", $this->bounce, 2);
		$this->head = $head;
		$this->body = $body;

		$this->output[0]['action']  = "";
		$this->output[0]['status']  = "";
		$this->output[0]['recipient'] = "";
	}

	function init_bouncehandler($blob){
		$strEmail = str_replace("\r\n", "\n", $blob);    // line returns 1
		$strEmail = str_replace("\n", "\r\n", $strEmail);// line returns 2
		$strEmail = str_replace("=\r\n", "", $strEmail); // remove MIME line breaks
		$strEmail = str_replace("=3D", "=", $strEmail);  // equals sign =
		$strEmail = str_replace("=09", "  ", $strEmail); // tabs
		return $strEmail;
	}

	// Get suid, mid if present. If not discard
	public function ProcessBounce() {
		$html = "";
		// get the header fields into a 2 column array
		$this->head_hash = $this->parse_head($this->head);
		
		// search for suid (unique subscriber id)
		$this->web_beacon_1 = $this->find_web_beacon($this->body,$this->web_beacon_preg_1);
		// search for mid (message id)
		$this->web_beacon_2 = $this->find_web_beacon($this->body,$this->web_beacon_preg_2);
		
		// find suid in the header or body
		// $this->x_header_beacon_1 = $this->find_x_header($this->x_header_search_1);
		$this->x_header_beacon_1 = $this->find_web_beacon($this->bounce,$this->header_preg_1);
		// find mid in the header or body
		// $this->x_header_beacon_2 = $this->find_x_header($this->x_header_search_2);
		$this->x_header_beacon_2 = $this->find_web_beacon($this->bounce,$this->header_preg_2);

		$suid = $this->x_header_beacon_1;
		if ($suid == "") {
			$suid = $this->web_beacon_1;
		}
		$mid = $this->x_header_beacon_2;
		if ($mid == "") {
			$mid = $this->web_beacon_2;
		}

		$subscriber = new subscribers($this->dbconn);
		// $subscriber = $fat->get('subscribers');
		$message = new messages($this->dbconn);
		$subscriber_exists = $subscriber->RetrieveSubscriber($suid);
		$muid = $message->GetMessageUID($mid);
		$message_exists = $message->RetrieveMessage($muid);
		// log the bounce into the bounces table and increment the s_bounces field in the subscibers table
		// if (($emailtype == "bounce") and $subscriber_exists and $message_exists and (($this->action == "failed") or ($this->action == "transient"))) {
		// if (($emailtype == "bounce") and $subscriber_exists and $message_exists) {
		if ($subscriber_exists and $message_exists) {
			// $sql = "insert into bounces (b_datereceived, b_m_id, b_s_uniqid, b_action, b_status) values (now(),\"$mid\",\"$suid\",\"$this->action\",\"$this->status\")";
			// $this->dbconn->query($sql);
			$sql = "update messages set m_bounces = m_bounces + 1 where m_id = \"$mid\"";
			$this->dbconn->query($sql);
			$sql = "update subscribers set s_bounces = s_bounces + 2 where s_uniqid = \"$suid\"";
			$this->dbconn->query($sql);
					
			// Read s_bounces back in and unsubscribe the subscriber if the bounce count is equal to or greater than our limit
			$subscriber->RetrieveSubscriber($suid);
			if ((int)$subscriber->sbounces >= ctnlist::BounceLimit) {
				$subscriber->suid = $suid;
				$subscriber->sglobalunsubscribe = "b";
				$subscriber->sunsubscribereason = "Bounce limit reached or exceeded. If this is an error, please contact the list administrator at " . ctnlist::AdminEmail;
  				$html = $subscriber->Unsubscribe();
			}
			return $html;
		}

		// Open the file and append to end of file
		$fp = fopen("bounce.txt", "a"); 
		// Write the data to the file
		$bdate = date("Y-m-d H:i:s");
		fwrite($fp, "--------------\n");
		fwrite($fp, $bdate . "\n");
		fwrite($fp, "hb1: $this->x_header_beacon_1\n");
		fwrite($fp, "hb2: $this->x_header_beacon_2\n");
		fwrite($fp, "wb1: $this->web_beacon_1\n");
		fwrite($fp, "wb2: $this->web_beacon_2\n");
		fwrite($fp, "suid: $suid\n");
		fwrite($fp, "mid: $mid\n");
		fwrite($fp, "subscriber exists: $subscriber_exists\n");
		fwrite($fp, "message exists: $message_exists\n");
		fwrite($fp, "email: $subscriber->semail\n");
		fwrite($fp, "bounces: $subscriber->sbounces\n");
		fwrite($fp, "unsubscribed? $unsubscribed\n");
		fwrite($fp, "type: $emailtype\n");
		fwrite($fp, "action: $this->action\n");
		fwrite($fp, "status: $this->status\n");
		fwrite($fp, "head:\n$this->head\n");
		// fwrite($fp, "body:\n$this->body\n");
		fclose($fp);
	}

	public function ProcessUnsubscribe() {
		// get the header fields into a 2 column array
		$this->head_hash = $this->parse_head($this->head);
		
		// search for suid (unique subscriber id)
		$this->web_beacon_1 = $this->find_web_beacon($this->body,$this->web_beacon_preg_1);
		
		// find suid in the header or body
		// $this->x_header_beacon_1 = $this->find_x_header($this->x_header_search_1);
		$this->x_header_beacon_1 = $this->find_web_beacon($this->bounce,$this->header_preg_1);

		$suid = "";
    $this->subject = trim(str_ireplace("Fw:", "", $this->head_hash['Subject']));

		if (preg_match($this->subject_beacon_preg,$this->subject,$matches)) {
			$suid = $matches[0];
		}
		if ($suid == "") {
			$suid = $this->x_header_beacon_1;
			if ($suid == "") {
				$suid = $this->web_beacon_1;
			}
		}

		$subscriber = new subscribers($this->dbconn);
		$subscriber_exists = $subscriber->RetrieveSubscriber($suid);
		$unsubscribed = $subscriber->Unsubscribe($suid,"Feedback Loop received and processed");
		
		// Open the file and append to end of file
		$fp = fopen("unsubscribe.txt", "a"); 
		// Write the data to the file
		$bdate = date("Y-m-d H:i:s");
		fwrite($fp, "--------------\n");
		fwrite($fp, $bdate . "\n");
		// fwrite($fp, "matches: $matches\n");
		fwrite($fp, "suid: $suid\n");
		fwrite($fp, "hb: $this->x_header_beacon_1\n");
		fwrite($fp, "wb: $this->web_beacon_1\n");
		fwrite($fp, "subscriber exists: $subscriber_exists\n");
		fwrite($fp, "email: $subscriber->semail\n");
		fwrite($fp, "unsubscribed? $unsubscribed\n");
		fwrite($fp, "head:\n$this->head\n");
		fwrite($fp, "body:\n$this->body\n");
		fclose($fp);
	}

	public function ExtractEmails() {
		// Extract all the email addresses in the body into an array
		$EmailAddresses = $this->find_email_addresses($this->body);

		if (is_array($EmailAddresses)) {
			// Open the file and append to end of file
			$fp = fopen("emails.txt", "a"); 
			// Write the data to the file
			$bdate = date("Y-m-d H:i:s");
			fwrite($fp, "--------------\n");
			fwrite($fp, $bdate . "\n");
			foreach ($EmailAddresses as $email) {
				fwrite($fp, "$email\n");
			}
			fclose($fp);
		}
	}
	
	// useage: $emailtype = $this->get_the_facts($strEmail);
	public function get_the_facts($eml){
		$this->head_hash = $this->parse_head($this->head);

		// sanity check.
    $this->looks_like_a_bounce = $this->is_a_bounce();
    $this->looks_like_an_FBL = $this->is_an_ARF();

		// parse the email into data structures
		$boundary = $this->head_hash['Content-type']['boundary'];
		$mime_sections = $this->parse_body_into_mime_sections($this->body, $boundary);
		$this->body_hash = split("\r\n", $this->body);

		// now we try all our weird text parsing methods

        // is it a Feedback Loop, in Abuse Feedback Reporting Format (ARF)?
        // http://en.wikipedia.org/wiki/Abuse_Reporting_Format#Abuse_Feedback_Reporting_Format_.28ARF.29
        if($this->looks_like_an_FBL){
            $this->output[0]['action'] = 'failed';
            $this->output[0]['status'] = "5.7.1";
            $this->subject = trim(str_ireplace("Fw:", "", $this->head_hash['Subject']));

            if($this->is_hotmail_fbl === true){
                $this->output[0]['recipient'] = $this->head_hash['X-hmxmroriginalrecipient'];
            }
            else{
                $fbl = $this->standard_parser($mime_sections['machine_parsable_body_part']);
                $this->output[0]['recipient'] = $this->find_fbl_recipients($fbl);
                $this->fbl_hash = $fbl;
            }
        }
		else if (preg_match("/auto.{0,20}reply|vacation|(out|away|on holiday).*office/i", $this->head_hash['Subject'])){
		    // looks like a vacation autoreply, ignoring
			$this->output[0]['action'] = 'autoreply';
		}
		else if ($this->is_RFC1892_multipart_report() === TRUE){
			$rpt_hash = $this->parse_machine_parsable_body_part($mime_sections['machine_parsable_body_part']);
			for($i=0; $i<count($rpt_hash['per_recipient']); $i++){
				$this->output[$i]['recipient'] = $this->find_recipient($rpt_hash['per_recipient'][$i]);
				$this->output[$i]['status'] = $rpt_hash['per_recipient'][$i]['Status'];
				$this->output[$i]['action'] = $rpt_hash['per_recipient'][$i]['Action'];
			}
		}
		else if(isset($this->head_hash['X-failed-recipients'])) {
			//  Busted Exim MTA
			//  Up to 50 email addresses can be listed on each header.
			//  There can be multiple X-Failed-Recipients: headers. - (not supported)
			$arrFailed = split(',', $this->head_hash['X-failed-recipients']);
			for($j=0; $j<count($arrFailed); $j++){
				$this->output[$j]['recipient'] = trim($arrFailed[$j]);
				$this->output[$j]['status'] = $this->get_status_code_from_text($this->output[$j]['recipient'], $this->body_hash,0);
				$this->output[$j]['action'] = $this->get_action_from_status_code($this->output[$j]['status']);
			}
		}
		else if(!empty($boundary) && $this->looks_like_a_bounce){
			// oh god it could be anything, but at least it has mime parts, so let's try anyway
			$arrFailed = $this->find_email_addresses($mime_sections['first_body_part']);
			for($j=0; $j<count($arrFailed); $j++){
				$this->output[$j]['recipient'] = trim($arrFailed[$j]);
				$this->output[$j]['status'] = $this->get_status_code_from_text($this->output[$j]['recipient'], $this->body_hash,0);
				$this->output[$j]['action'] = $this->get_action_from_status_code($this->output[$j]['status']);
			}
		}
		else if($this->looks_like_a_bounce){
			// last ditch attempt
			// could possibly produce erroneous output, or be very resource consuming,
			// so be careful.  You should comment out this section if you are very concerned
			// about 100% accuracy or if you want very fast performance.
			// Leave it turned on if you know that all messages to be analyzed are bounces.
			$arrFailed = $this->find_email_addresses($this->body);
			for($j=0; $j<count($arrFailed); $j++){
				$this->output[$j]['recipient'] = trim($arrFailed[$j]);
				$this->output[$j]['status'] = $this->get_status_code_from_text($this->output[$j]['recipient'], $this->body_hash,0);
				$this->output[$j]['action'] = $this->get_action_from_status_code($this->output[$j]['status']);
			}
		}
		// else if()..... add a parser for your busted-ass MTA here
        

        // accessors
        /*if it is an FBL, you could use the class variables to access the
        data (Unlike Multipart-reports, FBL's report only one bounce)
        */
        $this->type = $this->find_type();
        $this->action = $this->output[0]['action'];
        $this->status = $this->output[0]['status'];
        $this->subject = ($this->subject) ? $this->subject : $this->head_hash['Subject'];
        $this->recipient = $this->output[0]['recipient'];
        $this->feedback_type = ($this->fbl_hash['Feedback-type']) ? $this->fbl_hash['Feedback-type'] : "";

        // sniff out any web beacons
        $this->web_beacon_1 = $this->find_web_beacon($this->body,$this->web_beacon_preg_1);
        $this->web_beacon_2 = $this->find_web_beacon($this->body,$this->web_beacon_preg_2);

        $this->x_header_beacon_1 = $this->find_x_header($this->x_header_search_1);
        $this->x_header_beacon_2 = $this->find_x_header($this->x_header_search_2);

		// return $this->output;
		return $this->type;
	}


	// general purpose recursive heuristic function
	// to try to extract useful info from the bounces produced by busted MTAs
	function get_status_code_from_text($recipient, $body_hash, $index){
		for($i=$index; $i<count($body_hash); $i++){
			$line = trim($body_hash[$i]);
			
			if ($line == "") return "";

			/******** recurse into the email if you find the recipient ********/
			if(stristr($line, $recipient)!==FALSE){
				// the status code MIGHT be in the next few lines after the recipient line,
				// depending on the message from the foreign host... What a laugh riot!
				$status_code = $this->get_status_code_from_text($recipient, $body_hash, $i+1);
				if($status_code){
					return $status_code;
				}

			}

			/******** exit conditions ********/
			// if it's the end of the human readable part in this stupid bounce
			if(stristr($line, '------ This is a copy of the message')!==FALSE){
				return '';
			}
			//if we see an email address other than our current recipient's,
			if(count($this->find_email_addresses($line))>=1
			   && stristr($line, $recipient)===FALSE
			   && strstr($line, 'FROM:<')===FALSE){ // Kanon added this line because Hotmail puts the e-mail address too soon and there actually is error message stuff after it.
				return '';
			}
			/******** pattern matching ********/
			if(    stristr($line, 'no such address')!==FALSE
               ||  stristr($line, 'Recipient address rejected')!==FALSE
               ||  stristr($line, 'User unknown in virtual alias table')!==FALSE){
				return  '5.1.1';
			}
			else if(stristr($line, 'unrouteable mail domain')!==FALSE
				 || stristr($line, 'Esta casilla ha expirado por falta de uso')!==FALSE){
				return  '5.1.2';
			}
			else if(stristr($line, 'mailbox is full')!==FALSE
				||  stristr($line, 'Mailbox quota usage exceeded')!==FALSE
				||  stristr($line, 'User mailbox exceeds allowed size')!==FALSE){
				return  '4.2.2';
			}
			else if(stristr($line, 'not yet been delivered')!==FALSE){
				return  '4.2.0';
			}
			else if(stristr($line, 'mailbox unavailable')!==FALSE){
				return  '5.2.0';
			}
			else if(stristr($line, 'Unrouteable address')!==FALSE){
				return  '5.4.4';
			}
			// else if(stristr($line, 'retry timeout exceeded')!==FALSE){
			else if(stristr($line, 'retry time')!==FALSE){
				return  '4.4.7';
			}
			else if(stristr($line, 'The account or domain may not exist, they may be blacklisted, or missing the proper dns entries.')!==FALSE){ // Kanon added
				return  '5.2.0'; // I guess.... seems like 5.1.1, 5.1.2, or 5.4.4 would fit too, but 5.2.0 seemed most generic
			}
			else if(stristr($line, '554 TRANSACTION FAILED')!==FALSE){ // Kanon added
				return  '5.5.4'; // I think this should be 5.7.1. "SMTP error from remote mail server after end of data: ... (HVU:B1) http://postmaster.info.aol.com/errors/554hvub1.html" -- AOL rejects messages that have links to certain sites in them.
			}
			else if(stristr($line, 'Status: 4.4.1')!==FALSE
				 || stristr($line, 'delivery temporarily suspended')!==FALSE){ // Kanon added
				return  '4.4.1';
			}
			else if(stristr($line, '550 OU-002')!==FALSE
				 || stristr($line, 'Mail rejected by Windows Live Hotmail for policy reasons')!==FALSE){ // Kanon added
				return  '5.5.0'; // Again, why isn't this 5.7.1 instead?
			}
			else if(stristr($line, 'PERM_FAILURE: DNS Error: Domain name not found')!==FALSE){ // Kanon added
				return  '5.1.2'; // Not sure if this is right code. Just copied from above.
			}
			else if(stristr($line, 'Delivery attempts will continue to be made for')!==FALSE){ // Kanon added. From Symantec_AntiVirus_for_SMTP_Gateways@uqam.ca
				return  '4.2.0'; // I'm not sure why Symantec delayed this message, but x.2.x means something to do with the mailbox, which seemed appropriate. x.5.x (protocol) or x.7.x (security) also seem possibly appropriate. It seems a lot of times it's x.5.x when it seems to me it should be x.7.x, so maybe x.5.x is standard when mail is rejected due to spam-like characteristics instead of x.7.x like I think it should be.
			}
			else if(stristr($line, '554 delivery error:')!==FALSE){
				return  '5.5.4'; // rogers.com
			}
			else if(strstr ($line, '550-5.1.1')!==FALSE
				 || stristr($line, 'This Gmail user does not exist.')!==FALSE){ // Kanon added
				return  '5.1.1'; // Or should it be 5.5.0?
			}
            else{
                // end strstr tests
            }

            // rfc1893 return code
            if(preg_match('/([245]\.[01234567]\.[012345678])/', $line, $matches)){
                $mycode = str_replace('.', '', $matches[1]);
                $mycode = $this->format_status_code($mycode);
                return implode('.', $mycode['code']);
            }

            // search for RFC821 return code
            // thanks to mark.tolman@gmail.com
            // Maybe at some point it should have it's own place within the main parsing scheme (at line 88)
            if(preg_match('/\]?: ([45][01257][012345]) /', $line, $matches)
               || preg_match('/^([45][01257][012345]) (?:.*?)(?:denied|inactive|deactivated|rejected|disabled|unknown|no such|not (?:our|activated|a valid))+/i', $line, $matches))
            {
                $mycode = $matches[1];
                // map common codes to new rfc values
                if($mycode == '450' || $mycode == '550' || $mycode == '551' || $mycode == '554'){
                    $mycode = '511';
                } else if($mycode == '452' || $mycode == '552'){
                    $mycode = '422';
                } else if ($mycode == '421'){
                    $mycode = '432';
                }
                $mycode = $this->format_status_code($mycode);
                return implode('.', $mycode['code']);
            }

		}
		return '';
	}

	function is_RFC1892_multipart_report(){
	    return $this->head_hash['Content-type']['type']=='multipart/report'
	       &&  $this->head_hash['Content-type']['report-type']=='delivery-status'
	       && $this->head_hash['Content-type'][boundary]!=='';
	}

	function parse_head($headers){
	    if(!is_array($headers)) $headers = explode("\r\n", $headers);
	    $hash = $this->standard_parser($headers);
	    // get a little more complex
	    $arrRec = explode('|', $hash['Received']);
	    $hash['Received']= $arrRec;
	    if($hash['Content-type']){//preg_match('/Multipart\/Report/i', $hash['Content-type'])){
	        $multipart_report = explode (';', $hash['Content-type']);
	        $hash['Content-type']='';
	        $hash['Content-type']['type'] = strtolower($multipart_report[0]);
	        foreach($multipart_report as $mr){
	            if(preg_match('/([^=.]*?)=(.*)/i', $mr, $matches)){
                // didn't work when the content-type boundary ID contained an equal sign,
                // that exists in bounces from many Exchange servers
	            //if(preg_match('/([a-z]*)=(.*)?/i', $mr, $matches)){
	                $hash['Content-type'][strtolower(trim($matches[1]))]= str_replace('"','',$matches[2]);
	            }
	        }
	    }
	    return $hash;
	}

	function parse_body_into_mime_sections($body, $boundary){
	    if(!$boundary) return array();
	    if(is_array($body)) $body = implode("\r\n", $body);
	    $body = explode($boundary, $body);
	    $mime_sections['first_body_part'] = $body[1];
	    $mime_sections['machine_parsable_body_part'] = $body[2];
	    $mime_sections['returned_message_body_part'] = $body[3];
	    return $mime_sections;
	}


	function standard_parser($content){ // associative array orstr
	    // receives email head as array of lines
	    // simple parse (Entity: value\n)
	    if(!is_array($content)) $content = explode("\r\n", $content);
	    foreach($content as $line){
	        if(preg_match('/([^\s.]*):\s(.*)/', $line, $array)){
	            $entity = ucfirst(strtolower($array[1]));
	            if(empty($hash[$entity])){
	                $hash[$entity] = trim($array[2]);
	            }
	            else if($hash['Received']){
	                // grab extra Received headers :(
	                // pile it on with pipe delimiters,
	                // oh well, SMTP is broken in this way
	                if ($entity and $array[2] and $array[2] != $hash[$entity]){
	                    $hash[$entity] .= "|" . trim($array[2]);
	                }
	            }
	        }
	        else{
	            if ($entity){
	                $hash[$entity] .= " $line";
	            }
	        }
	    }
	    return $hash;
	}

	function parse_machine_parsable_body_part($str){
	    //Per-Message DSN fields
	    $hash = $this->parse_dsn_fields($str);
	    $hash['mime_header'] = $this->standard_parser($hash['mime_header']);
	    $hash['per_message'] = $this->standard_parser($hash['per_message']);
	    if($hash['per_message']['X-postfix-sender']){
	        $arr = explode (';', $hash['per_message']['X-postfix-sender']);
	        $hash['per_message']['X-postfix-sender']='';
	        $hash['per_message']['X-postfix-sender']['type'] = trim($arr[0]);
	        $hash['per_message']['X-postfix-sender']['addr'] = trim($arr[1]);
	    }
	    if($hash['per_message']['Reporting-mta']){
	        $arr = explode (';', $hash['per_message']['Reporting-mta']);
	        $hash['per_message']['Reporting-mta']='';
	        $hash['per_message']['Reporting-mta']['type'] = trim($arr[0]);
	        $hash['per_message']['Reporting-mta']['addr'] = trim($arr[1]);
	    }
	    //Per-Recipient DSN fields
	    for($i=0; $i<count($hash['per_recipient']); $i++){
	        $temp = $this->standard_parser(explode("\r\n", $hash['per_recipient'][$i]));
	        $arr = explode (';', $temp['Final-recipient']);
	        $temp['Final-recipient']='';
	        $temp['Final-recipient']['type'] = trim($arr[0]);
	        $temp['Final-recipient']['addr'] = trim($arr[1]);
	        $arr = explode (';', $temp['Original-recipient']);
	        $temp['Original-recipient']='';
	        $temp['Original-recipient']['type'] = trim($arr[0]);
	        $temp['Original-recipient']['addr'] = trim($arr[1]);
	        $arr = explode (';', $temp['Diagnostic-code']);
	        $temp['Diagnostic-code']='';
	        $temp['Diagnostic-code']['type'] = trim($arr[0]);
	        $temp['Diagnostic-code']['text'] = trim($arr[1]);
			// now this is wierd: plenty of times you see the status code is a permanent failure,
			// but the diagnostic code is a temporary failure.  So we will assert the most general
			// temporary failure in this case.
			$ddc=''; $judgement='';
			$ddc = $this->decode_diagnostic_code($temp['Diagnostic-code']['text']);
			$judgement = $this->get_action_from_status_code($ddc);
	        if($judgement == 'transient'){
				if(stristr($temp['Action'],'failed')!==FALSE){
					$temp['Action']='transient';
					$temp['Status']='4.3.0';
				}
			}
	        $hash['per_recipient'][$i]='';
	        $hash['per_recipient'][$i]=$temp;
	    }
	    return $hash;
	}

	function get_head_from_returned_message_body_part($mime_sections){
	    $temp = explode("\r\n\r\n", $mime_sections[returned_message_body_part]);
	    $head = $this->standard_parser($temp[1]);
	    $head['From'] = $this->extract_address($head['From']);
	    $head['To'] = $this->extract_address($head['To']);
	    return $head;
	}

	function extract_address($str){
	    $from_stuff = preg_split('/[ \"\'\<\>:\(\)\[\]]/', $str);
	    foreach ($from_stuff as $things){
	        if (strpos($things, '@')!==FALSE){$from = $things;}
	    }
	    return $from;
	}

	function find_recipient($per_rcpt){
	    if($per_rcpt['Original-recipient']['addr'] !== ''){
			$recipient = $per_rcpt['Original-recipient']['addr'];
		}
	    else if($per_rcpt['Final-recipient']['addr'] !== ''){
			$recipient = $per_rcpt['Final-recipient']['addr'];
		}
		$recipient = str_replace('<', '', $recipient);
		$recipient = str_replace('>', '', $recipient);
		return $recipient;
	}

    function find_type(){
        if($this->looks_like_a_bounce)
            return "bounce";
        else if($this->looks_like_an_FBL)
            return "fbl";
        else
            return false;
    }

	function parse_dsn_fields($dsn_fields){
	    if(!is_array($dsn_fields)) $dsn_fields = explode("\r\n\r\n", $dsn_fields);
	    $j = 0;
        reset($dsn_fields);
	    for($i=0; $i<count($dsn_fields); $i++){
	        if($i==0)
                $hash['mime_header'] = $dsn_fields[0];
	        elseif($i==1 && !preg_match('/(Final|Original)-Recipient/',$dsn_fields[1])) {
                // some mta's don't output the per_message part, which means
                // the second element in the array should really be
                // per_recipient - test with Final-Recipient - which should always
                // indicate that the part is a per_recipient part
				$hash['per_message'] = $dsn_fields[1];
			}
	        else {
	            if($dsn_fields[$i] == '--') continue;
	            $hash['per_recipient'][$j] = $dsn_fields[$i];
	            $j++;
	        }
	    }
	    return $hash;
	}

	function format_status_code($code) {
		$ret = "";
		if (preg_match('/([245]\.[01234567]\.[012345678])(.*)/', $code, $matches)) {
			$ret['code'] = $matches[1];
			$ret['text'] = $matches[2];
		} elseif (preg_match('/([245][01234567][012345678])(.*)/', $code, $matches)) {
			preg_match_all("/./", $matches[1], $out);
			$ret['code'] = $out[0];
			$ret['text'] = $matches[2];
		}
		return $ret;
	}

	function fetch_status_messages($code){
	    include_once ("rfc1893.error.codes.php");
	    $ret = $this->format_status_code($code);
	    $arr = explode('.', $ret['code']);
	    $str = "<P><B>". $status_code_classes[$arr[0]]['title'] . "</B> - " .$status_code_classes[$arr[0]]['descr']. "  <B>". $status_code_subclasses[$arr[1].".".$arr[2]]['title'] . "</B> - " .$status_code_subclasses[$arr[1].".".$arr[2]]['descr']. "</P>";
	    return $str;
	}

	function get_action_from_status_code($code) {
		if($code=='') return '';
		$ret = $this->format_status_code($code);
		$stat = $ret['code'];
		switch($stat){
			case(2):
				return 'success';
				break;
			case(4):
				return 'transient';
				break;
			case(5):
				return 'failed';
				break;
			default:
				return '';
				break;
		}
	}

	function decode_diagnostic_code($dcode){
	    if(preg_match("/(\d\.\d\.\d)\s/", $dcode, $array)){
	        return $array[1];
	    }
	    else if(preg_match("/(\d\d\d)\s/", $dcode, $array)){
	        return $array[1];
	    }
	}

	function is_a_bounce(){
		if(preg_match("/(mail delivery failed|failure notice|warning: message|delivery status notif|delivery failure|delivery problem|spam eater|returned mail|undeliverable|returned mail|delivery errors|mail status report|mail system error|failure delivery|delivery notification|delivery has failed|undelivered mail|returned email|returning message to sender|returned to sender|message delayed|mdaemon notification|mailserver notification|mail delivery system|nondeliverable mail|mail transaction failed)|auto.{0,20}reply|vacation|(out|away|on holiday).*office/i", $this->head_hash['Subject'])) return true;

 		if(preg_match('/auto_reply/',$this->head_hash['Precedence'])) return true;

		if(preg_match("/^(postmaster|mailer-daemon)\@?/i", $this->head_hash['From'])) return true;
		return false;
	}
    

	// This finds all email addresses
	function find_email_addresses($first_body_part) {
		if (preg_match_all($this->find_email_preg,$first_body_part,$matches)) {
			return $matches[1];
		}	else {
			return "";
		}
	}

    // these functions are for feedback loops
    function is_an_ARF(){
 		if(preg_match('/feedback-report/',$this->head_hash['Content-type']['report-type'])) return true;
 		if(preg_match('/scomp/',$this->head_hash['X-loop'])) return true;
        if(isset($this->head_hash['X-hmxmroriginalrecipient'])){
            $this->is_hotmail_fbl = true;
            return true;
        }
        return false;
    }
    
	// use a perl regular expression to find the web beacon
	public function find_web_beacon($body,$preg) {
		preg_match($preg, $body, $matches);
		return $matches[1];
	}
    
	public function find_x_header($xheader) {
		$xheader = ucfirst(strtolower($xheader));
		// check the header
		if(isset($this->head_hash[$xheader])) {
			return $this->head_hash[$xheader];
		}
		// check the body too
		$tmp_body_hash = $this->standard_parser($this->body_hash);
		if(isset($tmp_body_hash[$xheader])) {
			return $tmp_body_hash[$xheader];
		}
		return "";
	}



    private function find_fbl_recipients($fbl){
        if(isset($fbl['Original-rcpt-to'])){
            return $fbl['Original-rcpt-to'];
        }
        else if(isset($fbl['Removal-recipient'])){
            return trim(str_replace('--', '', $fbl['Removal-recipient']));
        }
    }

}
?>