<?php
/*
Module: mailer class
Version: 4.3
Author: Richard Catto
Creation Date: 2009-08-10
Updated: 2018.08.02 22h49 - use swiftmailer v6.1.3

Description:
This class handles all the subscriber related actions.
start must be instantiated before this class is instantiated in order to open the mysql database

Milestones
* 2011-09-13 Swiftmailer version 4.1.2
* 2014-08-23 Swiftmailer version 5.2.1
* 2017-07-11 Swiftmailer version 6.0.0 - not yet
* 2018.08.02 Swiftmailer v6.1.3
*/

class mailer {
  protected $fat;
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

  public $subscriber, $message, $template, $sendlog, $smlog;

  function __construct(Base $fat, SubscribersController $subscriber, MessagesController $message, TemplatesController $template, SendlogController $sendlog, SmlogController $smlog) {
    $this->fat = $fat;
    $this->subscriber = $subscriber;
    $this->message = $message;
    $this->template = $template;
    $this->sendlog = $sendlog;
    $this->smlog = $smlog;

    $this->BaseURL = $fat->get('BaseURL');
    $this->FromName = $fat->get('FromName');
    $this->FromAddress = $fat->get('FromAddress');
    $this->Domain = $fat->get('Domain');
    $this->BounceAddress = $fat->get('BounceAddress');
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
  }

  // Open smtp connection
  public function OpenSMTP($smtp_server = "") {
    if ($smtp_server == "") $smtp_server = $this->default_smtp_server;

    // echo '<pre>' . print_r($smtp_server, true) . '</pre>';

    if ($smtp_server['active'] <> 1) return false;

    try {
      $this->cm_smtp = (new Swift_SmtpTransport($smtp_server['host'], $smtp_server['port'], $smtp_server['enc']))
        ->setUsername($smtp_server['user'])
        ->setPassword($smtp_server['pass']);

      $this->cm_smtp->start(); // Check that this smtp server will work
      $this->cm_swift =new Swift_Mailer($this->cm_smtp);

      // use the Echo Logger - for troubleshooting problems with SMTP
      $debugMode = $this->fat->get('DebugMail');
      if ($debugMode == 1) {
        $logger = new Swift_Plugins_Loggers_EchoLogger();
        $this->cm_swift->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));
      }

      // Use AntiFlood to re-connect after every X emails and specify a time Y in seconds to pause for
      // $this->cm_swift->registerPlugin(new Swift_Plugins_AntiFloodPlugin(1000, 60));

      // Rate limit to X emails per-minute per domain
      // If the smtp server has a send rate, use it, otherwise use the global send rate
      $this->EmailsPerMinute = $smtp_server['sendrate'] ?? $this->fat->get('EmailsPerMinute');
      $this->cm_swift->registerPlugin(new Swift_Plugins_ThrottlerPlugin($this->EmailsPerMinute, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE));
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
  // Catches exceptions that may be thrown by Swiftmailer
  // SendMessage($suid,$muid,$mtype,$mfrom,$toemail,$toname,$subject,$mhtml,$mtext)
  public function SendMessage() {
    // $fp = fopen("sendmsg.txt", "a");
    // $bdate = date("Y-m-d H:i:s");
    $suid = $this->subscriber->subscriber->s_uniqid;
    $muid = $this->message->message->m_uniqid;
    $mtype = "MESSAGE";
    $mFromAddress = $this->message->message->m_from_address;
    $toemail = $this->subscriber->subscriber->s_email;
    $toname = trim($this->subscriber->subscriber->s_fname . ' ' . $this->subscriber->subscriber->s_lname);
    // using $msg does not work
    // $listname = $msg->message->m_listname;
    $listname = $this->subscriber->subscriber->s_subscribedby;
    // fwrite($fp, $bdate . " sub: {$sub->subscriber->s_subscribedby} listname: {$listname}\n");
    // fclose($fp);

    $subject = $this->message->message->m_subject;
    $mhtml = $this->template->shtml;
    $mtext = $this->template->stext;

    $msg = new Swift_Message($subject);
    $msg->setBody($mhtml,'text/html');
    $msg->addPart($mtext,'text/plain');

    if (($mFromAddress == "") || (is_null($mFromAddress))) {
      $mreplyto = $this->FromAddress;
    } else {
      $mreplyto = $mFromAddress;
    }

    // echo "<p>Reply To: $mreplyto</p>";
    $mFromAddress = "info+" . $suid . '@' . $this->Domain;
    // echo "<p>From: $mfrom</p>";
    $msg->setReplyTo($mreplyto);
    $msg->setReturnPath($this->BounceAddress);
    // $msg->setFrom(array($mreplyto => $this->FromName));
    $msg->setFrom(array($mreplyto => $this->message->message->m_from_name));
    $msg->setSender($mFromAddress);

    try {
      $msg->setTo(array($toemail => $toname));
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      // echo "<p>{$this->errormsg} msg->setTo email: '{$toemail}'</p>";
      return -1;
    }

    // set the headers of the message
    $headers = $msg->getHeaders();
    // $headers->addTextHeader("Precedence", "bulk");
    $headers->addTextHeader("List-Id", $this->ListName . " <{$this->BaseURL}>");
    $headers->addTextHeader("List-Unsubscribe", "<{$this->BaseURL}unsubscribe/{$suid}/{$muid}>");
    $headers->addTextHeader("List-Subscribe", "<{$this->BaseURL}subscribe?m={$muid}>");
    $headers->addTextHeader("List-Post", "NO");
    $headers->addTextHeader("List-Owner", "<mailto:{$this->AdminEmail}> ({$this->AdminName})");
    $headers->addTextHeader("List-Archive", "<{$this->BaseURL}archives>");
    $headers->addTextHeader("X-ctnlist-suid", $suid);
    $headers->addTextHeader("X-ctnlist-muid", $muid);

    try {
      $sent = $this->cm_swift->send($msg);
      if ($sent > 0) {
          $this->sendlog->logSend($muid,$mtype,$toemail,$listname,$subject);
          $this->smlog->logMsgSent($suid,$muid);
      }
      return $sent;
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      // echo "<p>{$this->errormsg} {$this->errorcode} {$sent}</p>";
      return 0;
    }
  }

  // copy all system notifications to the admin
  public function SendNotification($muid,$mtype,$mfrom,$toemail,$toname,$subject,$mhtml,$mtext) {
    $listname = $this->subscriber->subscriber->s_subscribedby;
    $msg = new Swift_Message($subject);
    $msg->setBody($mhtml,'text/html');
    $msg->addPart($mtext,'text/plain');

    if (($mfrom == "") || (is_null($mfrom))) {
      $mreplyto = $this->FromAddress;
    } else {
      $mreplyto = $mfrom;
    }

    $msg->setReplyTo($mreplyto);
    $msg->setReturnPath($this->BounceAddress);
    $msg->setFrom(array($mreplyto => $this->FromName));
    $msg->setSender($mfrom);

    try {
      $msg->setTo(array($toemail => $toname));
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      return -1;
    }
    // blind copy notification to admin
    $msg->setBcc(array($this->AdminEmail => $this->AdminName));

    try {
      $sent = $this->cm_swift->send($msg);
      if ($sent > 0) {
        $this->sendlog->logSend($muid,$mtype,$toemail,$listname,$subject);
      }
      return $sent;
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      // echo "<p>$this->errormsg $this->errorcode</p>";
      return 0;
    }
  }

  // Called from customers class with POST data
  public function SendOrderMessage($toemail,$toname,$mhtml,$mtext) {
    $valid = $this->subscriber->LoadSubscriber($toemail);
    $listname = ($valid) ? $this->subscriber->subscriber->s_subscribedby : "";
    $suid = $this->fat->get('POST.suid');
    $muid = $this->fat->get('POST.muid');
    $subject = "{$toemail} - {$this->OrderFormSubject}";
    $msg = new Swift_Message($subject);
    $msg->setBody($mhtml,'text/html');
    $msg->addPart($mtext,'text/plain');

    $msg->setReturnPath($this->OrderEmail);
    $msg->setFrom(array($this->OrderEmail => $this->OrderFormName));
    try {
      $msg->setTo(array($toemail => $toname));
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      return -1;
    }
    $msg->setBcc(array($this->AdminEmail => $this->AdminName));
    $msg->setBcc(array($this->OrderEmail => $this->OrderFormName));

    //The number of successful recipients is returned here
    try {
      $sent = $this->cm_swift->send($msg);
      if ($sent > 0) {
        $this->sendlog->logSend($muid,"CONTACT",$toemail,$listname,$subject);
        $this->smlog->logMsgBooking($suid,$muid);
        $this->subscriber->bumpPriority($suid,100000);
      }
      return $sent;
    } catch (Exception $e) {
      $this->errormsg = $e->getMessage();
      $this->errorcode = $e->getCode();
      return 0;
    }
  }
}
