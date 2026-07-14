
<?php
/*

Module: MessagesController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-06-30

*/

class MessagesController extends Controller {
  protected $fat;
  protected $dbPDO;
  protected $BaseURL;
  protected $ListName;
  protected $Domain;
  protected $FromAddress;
  protected $AdminEmail;
  protected $AdminName;

  protected $default_smtp_server;
  protected $SubscriptionConfirmAmount;

  public $message, $subscriber, $template, $smlog, $queue, $options, $archive, $mailer;

  function __construct(Base $fat, SubscribersController $subscriber, SmlogController $smlog, OptionsController $options) {
    $this->fat = $fat;
    $this->subscriber = $subscriber;
    $this->smlog = $smlog;
    $this->options = $options;

    $this->dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');
    $this->ListName = $fat->get('ListName');
    $this->Domain = $fat->get('Domain');
    $this->FromAddress = $fat->get('FromAddress');
    $this->AdminEmail = $fat->get('AdminEmail');
    $this->AdminName = $fat->get('AdminName');

    $this->message = new MessagesM($fat);

    $this->default_smtp_server = $fat->get('default_smtp_server');
    $this->SubscriptionConfirmAmount = $fat->get('SubscriptionConfirmAmount');
  }

  public function SetTemplate(TemplatesController $template) {
    $this->template = $template;
  }

  public function SetQueue(QueueController $queue) {
    $this->queue = $queue;
  }

  public function SetArchive(ArchivesController $archive) {
    $this->archive = $archive;
  }

  public function SetMailer(mailer $mailer) {
    $this->mailer = $mailer;
  }

  public function MessageCount() {
    return $this->message->msgcount();
  }

  public function ClearMsgStats($muid) {
    $html = "";
    $html .= $this->message->clrmsgstats($muid);
    return $html;
  }

  public function RetrieveMessage($muid) {
    return $this->message->read($muid);
  }

  public function getSubject($muid) {
    if ($this->RetrieveMessage($muid)) {
      return stripslashes($this->message->m_subject);
    }
    return '';
  }

  public function loadMessage($aid) {
    return $this->message->loadByAid($aid);
  }

  public function loadMid($mid) {
    return $this->message->loadByMid($mid);
  }

  public function CreateMUID() {
    return $this->message->CreateMUID();
  }

  public function CreateMessageHTMLform($muid = '') {
    $html = "";
    if ($this->fat->get('uadmin') <> '1') {
      $html .= "<p class=\"{{@pclass}\">Access denied</p>";
      return $html;
    }
    if ($muid == '') {
      $legend = 'Create Message';
      $this->message->reset();
      $this->message->m_from_address = $this->FromAddress;
      $m_html = "Enter your message here";
      $m_subject = "Your subject here";
    } else {
      $msgexists = $this->RetrieveMessage($muid);
      if (!$msgexists) {
        $html .= "<p class=\"{{@pclass}\">Message does not exist</p>";
        return $html;
      }
      $legend = 'Edit Message';
      $m_html = $this->message->m_html;
      $m_subject = $this->message->m_subject;
    }

    $ff = new formfield;

    // javascript variable
    /*
    $ckjs = "<script>";
    $ckjs .= "CKEDITOR.config.height = {{@textareaheight}};";
    $ckjs .= "CKEDITOR.config.width = {{@textareawidth}};";
    $ckjs .= "CKEDITOR.config.font_names = 'Arial/Arial, Helvetica, sans-serif;' + 'Calibri/Calibri, sans-serif;' + 'Comic Sans MS/Comic Sans MS, cursive;' + 'Courier New/Courier New, Courier, monospace;' + 'Georgia/Georgia, serif;' + 'Lucida Sans Unicode/Lucida Sans Unicode, Lucida Grande, sans-serif;' + 'Tahoma/Tahoma, Geneva, sans-serif;' + 'Times New Roman/Times New Roman, Times, serif;' + 'Trebuchet MS/Trebuchet MS, Helvetica, sans-serif;' + 'Verdana/Verdana, Geneva, sans-serif;';";
    $ckjs .= "CKEDITOR.replace( 'm_html' );";
    $ckjs .= "</script>";
    */
    $ckjs = "";

    $html .= $ff->FF_FormOpen("messageform","{{@BaseURL}}message","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend($legend);

    $html .= $ff->FF_hidden("m_uniqid",$muid);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_DropDown("m_t_id",$this->template->CreateTemplateHTMLDropDown($this->message->m_t_id),"{{@selectclass}}");
    $html .= $ff->FF_Label("Message Template","m_t_id","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("m_from_name","text",$this->message->m_from_name," required","{{@inputclass}}");
    $html .= $ff->FF_Label("From Name","m_from_name","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("m_from_address","text",$this->message->m_from_address," required","{{@inputclass}}");
    $html .= $ff->FF_Label("From Email Address","m_from_address","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_DropDown("m_listname",$this->subscriber->CreateListHTMLDropDown($this->message->m_listname),"{{@selectclass}}");
    $html .= $ff->FF_Label("Send to List","m_listname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("m_priority","number",(int) $this->message->m_priority," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Message Priority","m_priority","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("m_max_send","number",(int) $this->message->m_max_send," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Maximum emails to send","m_max_send","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $m_subject =  htmlspecialchars($this->message->m_subject ?? '',ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_input("m_subject","text",$m_subject," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Message Subject","m_subject","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $msg_content = htmlspecialchars($m_html ?? '',ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("mt_html",$msg_content,$ckjs,"{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
    $html .= $ff->FF_Label("HTML part","m_html","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("m_text",$this->message->m_text,"","{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
    $html .= $ff->FF_Label("Text part","m_text","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Save Message","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  public function save() {
    $html = "";
    $muid = $this->fat->get('POST.m_uniqid');
    $mtid = $this->fat->get('POST.m_t_id');
    // if ($mtid == 0) $mtid = 1; // force template usage
    $msubject = trim($this->fat->get('POST.m_subject'));
    if ($msubject == '') {
      $msubject = 'No subject';
    }

    if ($muid == '') { // create new msg
      $this->message->reset();
      $muid = $this->CreateMUID();
      $this->message->m_uniqid = $muid;
      $html .= "<p class=\"{{@pclass}\">tid: {$mtid}<br />subject: {$msubject}<br />muid: {$muid}</p>";
      $valid = true;
    } else { // save existing message
      $valid = $this->RetrieveMessage($muid);
    }
    if ($valid) {
      $this->message->m_t_id = $mtid;
	  $this->message->m_listname = $this->fat->get('POST.m_listname');
      $this->message->m_from_name = $this->fat->get('POST.m_from_name');
      $this->message->m_from_address = $this->fat->get('POST.m_from_address');
      $this->message->m_subject = $msubject;
      $this->message->m_priority = (int) $this->fat->get('POST.m_priority');
      $this->message->m_max_send = (int) $this->fat->get('POST.m_max_send');
      $this->message->m_html = $this->fat->get('POST.mt_html');
      $this->message->m_text = $this->fat->get('POST.m_text');
      $this->message->save();
      $html .= "<p class=\"{{@pclass}\">message saved</p>";
    }
    return $html;
  }

  // <i class="fa fa-bar-chart" aria-hidden="true"></i>
  public function CreateMessagesHTMLList($pageno = 1,$numrows = 25,$saved = false) {
    $html = "";
    $uadmin = $this->fat->get('uadmin');
    $uloggedin = $this->fat->get('uloggedin');
    if (!$uloggedin) {
      $html .= "<p class=\"{{@pclass}}\">Please login in order to view this page.</p>";
      return $html;
    }
    if ($saved) {
      $html .= "<div class=\"alert col-md-2 text-center alert-info alert-dismissible fade show\" role=\"alert\"><button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button><strong>Message saved!</strong></div>";
    }
    $totalmatches = $this->message->count();
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\">There are no messages.</p>";
      return $html;
    }
    $lastpage = ceil($totalmatches/$numrows);
    $pageno = (int) $pageno;
    if ($pageno > $lastpage) {
      $pageno = $lastpage;
    } elseif ($pageno < 1) {
      $pageno = 1;
    }

    $ff = new formfield;
    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Subject <i class=\"fa fa-pencil\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("Sent","{{@thclass}}");
    $html .= $ff->FF_Th("Q:S","{{@thclass}}");
    $html .= $ff->FF_Th("Read","{{@thclass}}");
    $html .= $ff->FF_Th("Liked","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-envelope-open-o\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-heart-o\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-thumbs-o-down\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("Add2Q","{{@thclass}}");
    $html .= $ff->FF_Th("SendQ","{{@thclass}}");
    $html .= $ff->FF_Th("Proof","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $page = $this->message->paginate($pageno - 1,$numrows,null,array('order' => 'm_id DESC'));
    foreach ($page['subset'] as $row) {
      $muid = $row['m_uniqid'];
      $msubject = stripslashes($row['m_subject']);
      $msub = substr($msubject,0,20) . '...';
      // $msubject = $msubject;
      $msubject = "<a href=\"{{@BaseURL}}message/{$muid}\" data-toggle=\"tooltip\" title=\"{$msubject}\">{$msub}</a>";
      $mdatesent = (is_null($row['m_datesent']) ? '&nbsp;' : $row['m_datesent']);
      $mqueued = $row['m_queued'];
      $msent = $row['m_sent'];
      $mqs = "{$mqueued}:{$msent}";
      $mreads = $row['m_reads'];
      $mr = "<a href=\"{{@BaseURL}}message-views/{$muid}\">{$mreads}</a>";
      $mlastread = $row['m_last_read'];
      $mlikes = $row['m_likes'];
      $mlastlike = $row['m_last_like'];
      $mdislikes = $row['m_dislikes'];
      $mq =  "<a href=\"{{@BaseURL}}queuelist/{$muid}\" class=\"btn btn-xs u-btn-deeporange\" target=\"_blank\">Queue</a>";
      $msq = "<a href=\"{{@BaseURL}}processqueue/{$muid}\" class=\"btn btn-xs u-btn-yellow\" target=\"_blank\">SendQ</a>";
      $mp =  "<a href=\"{{@BaseURL}}forward/{$muid}\" class=\"btn btn-xs u-btn-indigo\">Proof</a>";

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($msubject,"");
      $html .= $ff->FF_Td($mdatesent,"");
      $html .= $ff->FF_Td($mqs,"");
      $html .= $ff->FF_Td($mlastread,"");
      $html .= $ff->FF_Td($mlastlike,"");
      $html .= $ff->FF_Td($mr,"");
      $html .= $ff->FF_Td($mlikes,"");
      $html .= $ff->FF_Td($mdislikes,"");
      $html .= $ff->FF_Td($mq,"");
      $html .= $ff->FF_Td($msq,"");
      $html .= $ff->FF_Td($mp,"");
      $html .= $ff->FF_TrClose();
    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = 'messages';
    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action);
    return $html;
  }

  // @GET /forward
  // 2019.06.04 13h25 commented out subscriber validation code block
  public function CreateForwardHTMLform($suid,$muid) {
    $html = "";
    /*
    $valid = $this->subscriber->RetrieveSubscriber($suid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">The subscriber does not exist.</p>";
      return $html;
    }
    */
    $valid = $this->RetrieveMessage($muid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">The message does not exist.</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("forwardform","{{@BaseURL}}forward","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Forward Message");
    $html .= $ff->FF_hidden("muid",$muid);
    $html .= $ff->FF_hidden("suid",$suid);
    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("bemail","","","{{@textareaclass}}","600","450");
    $html .= $ff->FF_Label("Forward to these emails","bemail","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Forward Message","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();

    return $html;
  }

  // Forwards the message to user entered email addresses and subscribes the emails.
  // Must be a valid subscriber - commented out
  // 2019.06.04 13h25 an invalid subscriber no longer causes the forward to quit
  // @POST /forward
  public function ForwardSubscribeMessage($suid,$muid,$bemail) {
    $html = "";
    $valid = $this->subscriber->RetrieveSubscriber($suid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">The subscriber does not exist.</p>";
      $femail = "invalid@{$this->Domain}";
      // return $html;
    } else {
      // store email address of the subscriber who is forwarding message
      $femail = $this->subscriber->subscriber->s_email;
    }
    $valid = $this->RetrieveMessage($muid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">The message does not exist.</p>";
      return $html;
    }
    $bemail = strtolower($bemail);
    $txt = preg_replace('/\r\n|\r/', "\n", $bemail);
    $fcontents = explode("\n",trim($txt));
    $num = 0;
    for($i = 0; $i < sizeof($fcontents); $i++) {
      $semail = trim($fcontents[$i]);
      if ($semail <> '') {
        $added = $this->subscriber->SimpleSubscribe($semail);
        if ($added) {
          // todo: write $semail to forwarded.txt
        }

        if ($this->subscriber->subscriber->valid()) {
          $html .= "<p class=\"{{@pclass}\">$semail forwarded</p>";
          $this->smlog->logMsgForward($suid,$muid);

          $this->SendToAddress($muid,$semail);

          // send notification message to admin
          $mtype = "FORWARD-MESSAGE";
          // $mfrom = $this->FromAddress;
          $mfrom = $this->message->m_from_address;
          $sname = $this->subscriber->subscriber->s_fname . " " . $this->subscriber->subscriber->s_lname;
          $subject = "{$this->ListName} notification: {$femail} has forwarded {$muid} to {$semail}";
          $mhtml = "<p>{$this->ListName} message {$muid} has been forwarded to {$semail} by {$femail}</p>";
          $mtext = "{$this->ListName} message {$muid} has been forwarded to {$semail} by {$femail}";

          $this->mailer->OpenSMTP();
          $this->mailer->SendNotification($muid,$mtype,$mfrom,$this->AdminEmail,$sname,$subject,$mhtml,$mtext);
          $this->mailer->CloseSMTP();
          $this->subscriber->bumpPriority($suid,5);
          $num++;
        }
      }
    }
    $html .= "<p class=\"{{@pclass}\">Number forwarded: {$num}</p>";
    return $html;
  }

  public function ForwardSubscribeArchive() {
    $html = "";
    $aid = $this->fat->get('POST.aid');
    $suid = $this->fat->get('POST.suid');
    $muid = $this->fat->get('POST.muid');
    $bemail = $this->fat->get('POST.bemail');
    if ($muid == '') {
      $valid = $this->loadMessage($aid);
      if ($valid) {
        $muid = $this->message->m_uniqid;
      } else {
        $html .= "<p class=\"{{@pclass}\">Cannot find associated message</p>";
        return $html;
      }
    }
    $html .= $this->ForwardSubscribeMessage($suid,$muid,$bemail);
    return $html;
  }

  public function SendToAddress($muid,$semail) {
    $html = "";
    // Retrieve the message we wish to send
    $valid = $this->RetrieveMessage($muid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">Message not found.</p>";
      return $html;
    }
    if ($semail == '') {
      $html .= "<p class=\"{{@pclass}\">BLANK address. aborting.</p>";
      return $html;
    }
    $valid = $this->subscriber->LoadSubscriber($semail);
    // todo: check if subscriber exists!

    $tid = $this->message->m_t_id;

    $this->template->RetrieveTemplate($tid);

    $this->template->MergeTemplate();

    $html .= "<p class=\"{{@pclass}\">Opening SMTP connection to ". (string) $this->default_smtp_server['host'] . "</p>";
    $success = $this->mailer->OpenSMTP();
    if (!$success) {
      $html .= "<p class=\"{{@pclass}\">FAIL: could not open connection to SMTP server!</p>";
      return $html;
    } else {
      $html .= "<p class=\"{{@pclass}\">SUCCESS: opened connection to SMTP server!</p>";
    }
    $sname = trim($this->subscriber->subscriber->s_fname . ' ' . $this->subscriber->subscriber->s_lname);
    $html .= "<p class=\"{{@pclass}\">Sending to $sname $semail ";
    $sent = $this->mailer->SendMessage();
    if ($sent == 1) {
      $html .= "SUCCESS</p>";
    } else {
      $html .= "FAIL</p>";
    }
    $this->mailer->CloseSMTP();
    return $html;
  }

  // this is called when a subscriber reads a message. in order for this to work, they need to be both online and be displaying images in their email reader
  public function TrackOpen($suid,$muid) {
    $valid = $this->RetrieveMessage($muid);
    if (!$valid) {
      // backwards compatibility with ver 4.2 - the $muid might be a $mid
      $valid = $this->loadMid($muid);
      if ($valid) $muid = $this->message->m_uniqid;
    }
    if ($valid) {
      $reads = $this->message->m_reads + 1;
      $this->message->m_reads = (int) $reads;
      $this->message->m_last_read = date("Y-m-d H:i:s");
      $this->message->save();
    }

    $this->subscriber->bumpPriority($suid);

    $this->smlog->logMsgRead($suid,$muid);
  }

  public function like($suid,$muid) {
    $html = "";
    $valid = $this->RetrieveMessage($muid);
    if ($valid) {
      $d = date("Y-m-d H:i:s");
      $reads = $this->message->m_reads + 1;
      $this->message->m_reads = (int) $reads;
      $this->message->m_last_read = $d;
      $likes = (int) $this->message->m_likes + 1;
      $this->message->m_likes = (int) $likes;
      $this->message->m_last_like = $d;
      $this->message->save();
      $html = "<p class=\"{{@pclass}\">Thank you for your LIKE</p>";
    }

    $this->subscriber->bumpPriority($suid,1000);

    $this->smlog->logMsgLike($suid,$muid);
    return $html;
  }

  public function dislike($suid,$muid) {
    $html = "";
    $valid = $this->RetrieveMessage($muid);
    if ($valid) {
      $d = date("Y-m-d H:i:s");
      $reads = $this->message->m_reads + 1;
      $this->message->m_reads = (int) $reads;
      $this->message->m_last_read = $d;
      $dislikes = (int) $this->message->m_dislikes + 1;
      $this->message->m_dislikes = (int) $dislikes;
      $this->message->m_last_dislike = $d;
      $this->message->save();
      $html = "<p class=\"{{@pclass}\">We apologise for the lack of relevance of our message to you</p>";
    }

    $this->subscriber->resetPriority($suid);

    $this->smlog->logMsgDislike($suid,$muid);
    return $html;
  }

  // Queues a message for sending
  // 2019.02.19 12h48 grab a small chunk at a time and queue it, until total is queued
  public function SendListToQueue($muid,$sendlimit = 500000) {
    $html = "";
    set_time_limit(86400);
    // variable holds the number of messages sent
    $numsent = 0;
    // add batchsize at a time, until sendlimit is reached
    $batchsize = 5000;

    $this->options->SetOption("ActiveMessage",$muid);  // the current or last message to be sent

    // Retrieve the message we wish to send
    $valid = $this->RetrieveMessage($muid);
    if (!$valid) {
      $html .= "<p class=\"{{@pclass}\">Message not found.</p>";
      return $html;
    }
    $mpriority = (int) $this->message->m_priority;
    $msubject = stripslashes($this->message->m_subject);
    $mlistname = $this->message->m_listname;
    $html .= "<p class=\"{{@pclass}\">listname: {$mlistname}</p>";
    $tid = (int) $this->message->m_t_id;
    if (is_null($this->message->m_datesent)) {
      $this->message->m_datesent = date("Y-m-d H:i:s");
      $this->message->save();
    }

    $this->template->RetrieveTemplate($tid);

    // Create the archive for the message when queueing it for sending
    if ($this->message->m_a_id == (int) 0) {
      $this->message->m_a_id = $this->archive->CreateArchive();
      $this->message->save();
    }

    do {
      if ($mlistname == "ALL") {
         $sql = "select s_uniqid, s_email, s_last_interacted, s_priority, s_subscribedby, s_emailsleft, sml_muid from subscribers left join smlog on (s_uniqid = sml_suid) and (sml_muid = :muid) where (s_unsubscribe = 0) and (sml_muid is null) ORDER BY (s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC LIMIT {$batchsize}";
         $args = array(':muid' => $muid);
  	  } else {
        $sql = "select s_uniqid, s_email, s_last_interacted, s_priority, s_subscribedby, s_emailsleft, sml_muid from subscribers left join smlog on (s_uniqid = sml_suid) and (sml_muid = :muid) where (s_unsubscribe = 0) and (s_subscribedby = :listname) and (sml_muid is null) ORDER BY (s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC LIMIT {$batchsize}";
        $args = array(':muid' => $muid, ':listname' => $mlistname);
      }
      $result = $this->dbPDO->exec($sql,$args);

      // check for zero row return and exit do-while loop
      $rowsreturned = $this->dbPDO->count();
      $html .= "<p class=\"{{@pclass}\">rows returned: {$rowsreturned}</p>";
      if ($rowsreturned == 0) break;

      foreach ($result as $row) {
        $suid = $row['s_uniqid'];
        $email = $row['s_email'];
        $listname = $row['s_subscribedby'];
        $lastinteracted = $row['s_last_interacted'];
        $spriority = (int) $row['s_priority'];
        $semailsleft = (int) $row['s_emailsleft'];

        $valid = $this->subscriber->RetrieveSubscriber($suid);
        if ($valid) {
          $semailsleft = max(0,$semailsleft - 1);
          $this->subscriber->subscriber->s_bounces = (int) 0;
          $this->subscriber->subscriber->s_priority = (int) 0;
          $this->subscriber->subscriber->s_emailsleft = (int) $semailsleft;
          $this->subscriber->subscriber->save();

          // also need to update the number queued to in the messages table
          $queued = (int) $this->message->m_queued + 1;
          $this->message->m_queued = (int) $queued;
          $this->message->save();

          $this->smlog->logMsgQueued($suid,$muid);

          $this->queue->AddToQueue($muid,$suid,$msubject,$email,$listname,$lastinteracted,$mpriority,$spriority);
          // increment the variable containing the number of messages sent
          $numsent++;
        }
      }
    } while ($numsent < $sendlimit);

    $html .= "<p class=\"{{@pclass}\">Finished queueing: {$numsent}</p>";
    return $html;
  }

  // To display a drop down select box of all available messages in the Advanced Queue form
  public function CreateMessageHTMLDropDown() {
    $html = "";
    $this->message->load(null,array('order' => 'm_id'));
    $html .= "<option selected value=\"\">no message</option>";
    while(!$this->message->dry()) {
      $html .= "<option value=\"{$this->message->m_uniqid}\">". htmlspecialchars($this->message->m_subject) . "</option>";
      $this->message->skip();
    }
    return $html;
  }

  // form has 4 message select fields, total volume of emails to queue, queue button
  public function CreateAdvancedQueueHTMLform() {
    $html = "";
    if ($this->fat->get('uadmin') <> '1') {
      $html .= "<p class=\"{{@pclass}\">Access denied</p>";
      return $html;
    }

    // $hh = new htmlhelper($this->fat);
    $ff = new formfield;

    $html .= $ff->FF_FormOpen("advancedqueueform","{{@BaseURL}}advanced-queue","POST","form-horizontal");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Send Multiple Messages");

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $msgDropDown = $this->CreateMessageHTMLDropDown();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_DropDown("muid1",$msgDropDown,"{{@selectclass}}");
    $html .= $ff->FF_Label("Select Message 1","muid1","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_DropDown("muid2",$msgDropDown,"{{@selectclass}}");
    $html .= $ff->FF_Label("Select Message 2","muid2","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_DropDown("muid3",$msgDropDown,"{{@selectclass}}");
    $html .= $ff->FF_Label("Select Message 3","muid3","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_DropDown("muid4",$msgDropDown,"{{@selectclass}}");
    $html .= $ff->FF_Label("Select Message 4","muid4","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_input("mvolume","number","1000000"," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Total number of emails to send","mvolume","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_Button("submit","Queue Multiple Messages","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();

    return $html;
  }

  public function AdvancedSendListToQueue($muids,$mvolume) {
    $html = "";
    set_time_limit(86400);

    $muids = array_values(array_filter(
      array_map(static fn($muid) => trim((string) $muid), (array) $muids),
      static fn($muid) => $muid !== ''
    ));

    if ($muids === []) {
      return "<p class=\"{{@pclass}}\">Select at least one message before queueing.</p>";
    }

    $mvolume = max(1, (int) $mvolume);
    $i = 0;
    $msgs = array();

    foreach($muids as $muid) {
      $msgs[$i] = new MessagesM($this->fat);
      if (!$msgs[$i]->read($muid)) {
        return "<p class=\"{{@pclass}}\">Message {$muid} does not exist.</p>";
      }
      $msgs[$i]->m_datesent = date("Y-m-d H:i:s");
      $msgs[$i]->save();
      $i++;
    }

    $this->subscriber->subscriber->load('s_unsubscribe = 0',array('order' => '(s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email', 'limit' => $mvolume));
    $nummsgs = count($muids);
    $i = 0;
    while ($this->subscriber->subscriber->valid()) {
      // set_time_limit(0);
      $suid = $this->subscriber->subscriber->s_uniqid;
      $email = $this->subscriber->subscriber->s_email;
      $listname = $this->subscriber->subscriber->s_subscribedby;
      $lastinteracted = $this->subscriber->subscriber->s_last_interacted;
      $spriority = $this->subscriber->subscriber->s_priority;
      $semailsleft = $this->subscriber->subscriber->s_emailsleft;

      $muid = $muids[$i];
      $msg = $msgs[$i];
      $msubject = stripslashes($msg->m_subject);
      $mpriority = (int) $msg->m_priority;

      $this->queue->AddToQueue($muid,$suid,$msubject,$email,$listname,$lastinteracted,$mpriority,$spriority);
      // increment m_queued per message
      $queued = (int) $msg->m_queued + 1;
      $msg->m_queued = (int) $queued;
      $msg->save();

      $this->smlog->logMsgQueued($suid,$muid);

      $sbounces = 0;
      $spriority = 0;
      $semailsleft = max(0,$semailsleft - 1);
      $this->subscriber->subscriber->s_bounces = (int) $sbounces;
      $this->subscriber->subscriber->s_priority = (int) $spriority;
      $this->subscriber->subscriber->s_emailsleft = (int) $semailsleft;
      $this->subscriber->subscriber->save();

      $i++;
      $i = $i % $nummsgs; // next msg, wrap around to start at end
      $this->subscriber->subscriber->skip();
    }
    $html .= "<p class=\"{{@pclass}\">All done!</p>";
    return $html;
  }
}
