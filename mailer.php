<?php
/*
Module: mailer class
Version: 1.3.4
Author: Richard Catto
Creation Date: 2009-08-10

Description:
This class handles all the subscriber related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

Milestones
* 2011-09-13 Swiftmailer version 4.1.2
* 2014-08-23 Swiftmailer version 5.2.1

*/

class mailer {
	
	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;
	protected $FromAddress;
	protected $Domain;
	protected $BounceAddress;
	protected $FromName;
	protected $AdminEmail;
	protected $AdminName;
	protected $ListName;
	protected $UnsubscribeAddress;
	protected $OrderFormSubject;
	protected $OrderEmail;
	protected $OrderFormName;
    protected $EmailsPerMinute;
    protected $default_smtp_server;
	
	// Swiftmailer object variable
	private $cm_smtp;
	private $cm_swift;
	
	public $errormsg;
	public $errorcode;
	public $sendmethod;
	
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');

		$this->FromAddress = $fat->get('FromAddress');
		$this->Domain = $fat->get('Domain');
		$this->BounceAddress = $fat->get('BounceAddress');
		$this->FromName = $fat->get('FromName');
		$this->AdminEmail = $fat->get('AdminEmail');
		$this->AdminName = $fat->get('AdminName');
		$this->ListName = $fat->get('ListName');
		$this->UnsubscribeAddress = $fat->get('UnsubscribeAddress');
		$this->OrderFormSubject = $fat->get('OrderFormSubject');
		$this->OrderEmail = $fat->get('OrderEmail');
		$this->OrderFormName = $fat->get('OrderFormName');
        $this->EmailsPerMinute = $fat->get('EmailsPerMinute');
        $this->default_smtp_server = $fat->get('default_smtp_server');

		$this->errormsg = "";
		$this->errorcode = 0;
		$this->sendmethod = "";		
	}

	function __destruct() {
	}

	// logs each send of mail, so I can bill for SMTP sends
	public function SendLog($mtype,$numsent,$toemail,$subject) {
		$subject = $this->dbconn->real_escape_string($subject);

		// log mail sent into local sendlog table
		// $sql = "insert into sendlog (sl_datesent, sl_type, sl_method, sl_numsent, sl_toemail, sl_subject) values (now(),\"$mtype\",\"$this->sendmethod\",\"$numsent\",\"$toemail\",\"$subject\");";
        $sql = "insert into sendlog (sl_type, sl_method, sl_numsent, sl_toemail, sl_subject) values (\"$mtype\",\"$this->sendmethod\",\"$numsent\",\"$toemail\",\"$subject\");";

		// echo '<pre>' . print_r($sql, true) . '</pre>';

		$success = $this->dbconn->query($sql);
		
		return $success;
	}

	// Open smtp connection
	public function OpenSMTP($smtp_server = "") {
		if ($smtp_server == "") $smtp_server = $this->default_smtp_server;
		
		// echo '<pre>' . print_r($smtp_server, true) . '</pre>';

		if ($smtp_server['active'] <> 1) return false;
		
		$this->sendmethod = "";
		try {
			$this->cm_smtp = Swift_SmtpTransport::newInstance()
			              ->setHost($smtp_server['host'])
			              ->setPort($smtp_server['port'])
			              ->setEncryption($smtp_server['enc'])
			              ->setUsername($smtp_server['user'])
			              ->setpassword($smtp_server['pass']);
			
			$this->cm_smtp->start(); // Check that this smtp server will work
			
			$this->cm_swift = Swift_Mailer::newInstance($this->cm_smtp);
			
			// use the Echo Logger - for troubleshooting problems with SMTP
			// $logger = new Swift_Plugins_Loggers_EchoLogger();
			// $this->cm_swift->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));
			
			// Use AntiFlood to re-connect after every X emails and specify a time Y in seconds to pause for
			// $this->cm_swift->registerPlugin(new Swift_Plugins_AntiFloodPlugin(1000, 60));
			
			// Rate limit to X emails per-minute per domain
            // If the smtp server has a send rate, use it, otherwise use the global send rate
			
            $this->EmailsPerMinute = $smtp_server['sendrate']; // ?? $this->fat->get('EmailsPerMinute');
            
			$this->cm_swift->registerPlugin(new Swift_Plugins_ThrottlerPlugin($this->EmailsPerMinute, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE));
			
			$this->sendmethod = $smtp_server['name'];
			
			return true;
		} catch (Exception $e) {
			$emsg = $e->getMessage();
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return false;
		}		
	}

	//Disconnect from SMTP
	public function CloseSMTP() {
		$this->sendmethod = "";
		try {
			$this->cm_smtp->stop();
			return true;
		} catch (Exception $e) {
			$emsg = $e->getMessage();
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return false;
		}
	}

	// Sends a single message to a single recipient using Swiftmailer
	// blind copies the message to admin if $bcctoadmin is true
	// Catches exceptions that may be thrown by Swiftmailer
	public function SendMessage($suid,$mid,$mtype,$mfrom,$toemail,$toname,$subject,$mhtml,$mtext,$bcctoadmin) {
		// include the suid in the subject line to help identify subscribers
		// if ($mtype == 'M') $subject = $suid . ' ' . $subject;
		// $message = Swift_Message::newInstance($subject,$mtext);
		// $message->AddPart($mhtml,"text/html");
      
        $message = Swift_Message::newInstance($subject);
        $message->setBody($mhtml,'text/html');
        $message->addPart($mtext,'text/plain');
      
		if (($mfrom == "") || (is_null($mfrom))) {
			$mreplyto = $this->FromAddress;
		} else {
			$mreplyto = $mfrom;
		}

		// echo "<p>Reply To: $mreplyto</p>";
		
		$mfrom = "info+" . $suid . '@' . $this->Domain;
		
		// echo "<p>From: $mfrom</p>";
		
		$message->setReplyTo($mreplyto);
		$message->setReturnPath($this->BounceAddress);
		$message->setFrom(array($mreplyto => $this->FromName));
		$message->setSender($mfrom);
		
		try {
			$message->setTo(array($toemail => $toname));
		} catch (Exception $e) {
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return -1;
		}
		if ($bcctoadmin) {
			$message->setBcc(array($this->AdminEmail => $this->AdminName));
		}

		// set the headers of the message
		$headers = $message->getHeaders();
		// $headers->addTextHeader("Precedence", "bulk");
		$headers->addTextHeader("List-Id", $this->ListName . " <{$this->BaseURL}>");
		$headers->addTextHeader("List-Unsubscribe", "<mailto:{$this->UnsubscribeAddress}?subject={$suid}>, <{$this->BaseURL}unsubscribe/{$suid}>");
		$headers->addTextHeader("List-Subscribe", "<{$this->BaseURL}subscribe>");
		$headers->addTextHeader("List-Post", "NO");
		$headers->addTextHeader("List-Owner", "<mailto:{$this->AdminEmail}> ({$this->AdminName})");
		$headers->addTextHeader("List-Archive", "<{$this->BaseURL}archives>");
		$headers->addTextHeader("X-ctnlist-suid", $suid);
		$headers->addTextHeader("X-ctnlist-mid", $mid);

		//The number of successful recipients is returned here
		// Creates a Swift_Address object to hold the from name and email address
		// Swift_Address is no longer available in swiftmailer version 4

		try {
			$sent = $this->cm_swift->send($message);
			if ($sent > 0) $this->SendLog($mtype,$sent,$toemail,$subject);
			return $sent;
		} catch (Exception $e) {
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			// echo "<p>$this->errormsg $this->errorcode</p>";
			return 0;
		}
	}

	public function SendOrderMessage($toemail,$toname,$mhtml,$mtext) {
		//Create the message to send
		$subject = $this->OrderFormSubject;
		// $message = Swift_Message::newInstance($subject,$mtext);
		// $message->AddPart($mhtml,"text/html");
		
        $message = Swift_Message::newInstance($subject);
        $message->setBody($mhtml,'text/html');
        $message->addPart($mtext,'text/plain');
      
		$message->setReturnPath($this->OrderEmail);
		$message->setFrom(array($this->OrderEmail => $this->OrderFormName));
		try {
			$message->setTo(array($toemail => $toname));
		} catch (Exception $e) {
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return -1;
		}
		$message->setBcc(array($this->AdminEmail => $this->AdminName));
		$message->setBcc(array($this->OrderEmail => $this->OrderFormName));
	
		//The number of successful recipients is returned here
		try {
			$sent = $this->cm_swift->send($message);
            if ($sent > 0) $this->SendLog("O",$sent,$toemail,$subject);
			return $sent;
		} catch (Exception $e) {
			$this->errormsg = $e->getMessage();
			$this->errorcode = $e->getCode();
			return 0;
		}
	}
}
?>