<?php
/*{{@pclass}}

Module: SubscribersController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-02

*/

class SubscribersController extends Controller {
  protected $fat;
  protected $dbPDO;
  protected $BaseURL;

  protected $API;
  protected $Domain;
  protected $ListName;
  protected $FromAddress;
  protected $SubscriptionConfirmAmount;
  protected $SubscriptionConfirmLevel;

  public $subscriber, $smlog, $mailer;
  public $gu, $gdu;

  // from regular-expressions.info to find a valid email address
  public $find_email_preg = "/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,15})\b/i";

  function __construct(Base $fat, SmlogController $smlog) {
    $this->fat = $fat;
    $this->smlog = $smlog;

    $this->BaseURL = $fat->get('BaseURL');
    $this->dbPDO = $fat->get('dbPDO');

    $this->subscriber = new SubscribersM($fat);
    $this->gu = new GlobalUnsubscribeController($fat);
    $this->gdu = new GlobalDomainUnsubscribeController($fat);

    $this->API = $fat->get('API');
    $this->Domain = $fat->get('Domain');
    $this->ListName = $fat->get('ListName');
    $this->FromAddress = $fat->get('FromAddress');
    $this->SubscriptionConfirmAmount = $fat->get('SubscriptionConfirmAmount');
    $this->SubscriptionConfirmLevel = $fat->get('SubscriptionConfirmLevel');
  }

  public function SetMailer(mailer $mailer) {
    $this->mailer = $mailer;
  }

  // This finds all email addresses in a string
  public function find_email_addresses($email) {
    if (preg_match_all($this->find_email_preg,$email,$matches)) {
      return $matches[1];
    }	else {
      return "";
    }
  }

  public function NumSubscribers() {
    return $this->subscriber->numsubscribers();
  }

  public function ActiveReaders() {
    return $this->subscriber->activeReaders();
  }

  public function Confirmed() {
    return $this->subscriber->confirmed();
  }

  public function Unsubscribed() {
    return $this->subscriber->unsubscribed();
  }

  public function export($offset,$amount) {
    set_time_limit(86400);
    $this->subscriber->load(null,array('order' => '(s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC', 'limit' => $amount, 'offset' => $offset));
    $fp = fopen("export.txt", "a");
    while ($this->subscriber->valid()) {
      $semail = $this->subscriber->s_email;
      fwrite($fp, "{$semail}\n" );
      $this->subscriber->skip();
    }
    fclose($fp);
    $html = "<p class=\"{{@pclass}}\">Export complete</p>";
    return $html;
  }

  public function exportToFile($sql,$filename) {
    set_time_limit(86400);
    $result = $this->dbPDO->exec($sql);
    $fp = fopen($filename, "a");
    foreach ($result as $row) {
      $email = $row['s_email'];
      fwrite($fp, "$email\n" );
    }
    fclose($fp);
    $html = "<p class=\"{{@pclass}}\">{$filename} - export complete</p>";
    return $html;
  }

  // $args = array(':offset' => (int) $offset, ':limit' => (int) $limit);
  public function exportPDO($offset = 0,$limit = 10000000) {
    $html = "";
    $sql = "select s_email from subscribers where s_unsubscribe = 0 order by (s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC";
    $filename = "export-subscribers.txt";
    $html .= $this->exportToFile($sql,$filename);
    $sql = "select s_email from subscribers where s_unsubscribe = 1 order by s_email";
    $filename = "export-remove.txt";
    $html .= $this->exportToFile($sql,$filename);
    return $html;
  }

  public function RetrieveSubscriber($suid) {
    return $this->subscriber->read($suid);
  }

  public function LoadSubscriber($email) {
    return $this->subscriber->loadByEmail($email);
  }

  public function IsSubscribed($email) {
    return $this->subscriber->isSubscribed($email);
  }

  public function getEmail($suid) {
    return $this->subscriber->getEmail($suid);
  }

  public function bumpPriority($suid,$p = 1) {
    return $this->subscriber->bumpPriority($suid,$p);
  }

  public function ResetPriority($suid) {
    $this->subscriber->resetPriority($suid);
  }

  public function setPriority($suid,$priority) {
    $this->subscriber->setPriority($suid,$priority);
  }

  public function CreateSubscriberHTMLform($suid = "",$muid = "") {
    $html = "";
    if ($suid <> '') { // edit an existing subscriber
      if (!$this->RetrieveSubscriber($suid)) { // subscriber does not exist
        $html .= "<p class=\"{{@pclass}}\">The subscriber does not exist.</p>";
        return $html;
      }
      $email_disabled = " readonly"; // disable the input for email of existing subscriber
      $legend = 'Edit Subscriber';
    } else { // add a new subscriber
      $this->subscriber->reset();
      $email_disabled = " required"; // require email to be input because new subscriber
      $legend = 'Add New Subscriber';
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("subscribeform","{{@BaseURL}}subscribe","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend($legend);

    $html .= $ff->FF_hidden("muid",$muid);
    $html .= $ff->FF_hidden("suid",$suid);
    $html .= $ff->FF_hidden("s_priority",$this->subscriber->s_priority);
    $html .= $ff->FF_hidden("s_subscribedby",$this->subscriber->s_subscribedby);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    if ($this->fat->get('uadmin') == '1') {
      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= $ff->FF_input("s_subscribedby","text",$this->subscriber->s_subscribedby," required","{{@inputclass}}");
      $html .= $ff->FF_Label("Subscribed by / listname","s_subscribedby","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= $ff->FF_input("s_priority","number",$this->subscriber->s_priority," required","{{@inputclass}}");
      $html .= $ff->FF_Label("Priority","s_priority","{{@labelclass}}");
      $html .= $ff->FF_DivClose();
    }

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("s_fname","text",$this->subscriber->s_fname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("First Name","s_fname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("s_lname","text",$this->subscriber->s_lname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Last Name","s_lname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("s_email","email",$this->subscriber->s_email,$email_disabled,"{{@inputclass}}");
    $html .= $ff->FF_Label("Email Address","s_email","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_DropDown("s_gender",$ff->CreateGenderHTMLDropDown($this->subscriber->s_gender),"{{@selectclass}}");
    $html .= $ff->FF_Label("Gender","s_gender","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_DropDown("s_province",$ff->CreateProvinceHTMLDropDown($this->subscriber->s_province),"{{@selectclass}}");
    $html .= $ff->FF_Label("Province","s_province","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_DropDown("s_country",$ff->CreateCountryHTMLDropDown($this->subscriber->s_country),"{{@selectclass}}");
    $html .= $ff->FF_Label("Country","s_country","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    if (!$this->fat->get('uloggedin')) {
      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= "<img src=\"/captcha\" title=\"Refresh page for new captcha image. No timeout.\" alt=\"captcha\"/>";
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= $ff->FF_input("captcha","text","","","{{@inputclass}}");
      $html .= $ff->FF_Label("Captcha code","captcha","{{@labelclass}}");
      $html .= $ff->FF_DivClose();
    }

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Subscribe","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  // $suid == '' means new subscriber - check if email they enter is already subscribed
  // $suid <> '' means existing subscriber, discard the email because email, once entered, is read only
  public function save() {
    $html = '';
    $suid = $this->fat->get('POST.suid');
    $muid = $this->fat->get('POST.muid');

    $email = strtolower(trim($this->fat->get('POST.s_email')));

    if ($suid == '') { // NEW SUBSCRIBER - email must not be blank or already be in the database
      if ($email == '') {
        $html .= '<p class=\"{{@pclass}}\">email is blank</p>';
        return $html;
      }
      $subscribed = $this->IsSubscribed($email);
      if ($subscribed) {
        $html .= "<p class=\"{{@pclass}}\">{$email} is already in database</p>";
        return $html;
      }
      $this->subscriber->reset();
      $this->subscriber->s_email = $email;
      $this->subscriber->save();
      $suid = $this->subscriber->s_uniqid;
      $this->smlog->logMsgSub($suid,$muid);
      $html .= "<p class=\"{{@pclass}}\">{$email} has been subscribed to {$this->ListName}</p>";

      // $subject = "{$this->ListName} notification: {$email} has been subscribed";
      $subject = "{$email} has subscribed to {$this->ListName}";

      $mhtml = "<p>{$email} has been subscribed to {$this->ListName}.</p><p>Please confirm your subscription by clicking this link:<br /><a href=\"{$this->BaseURL}confirm/{$suid}/{$muid}\">CONFIRM SUBSCRIPTION</a></p>";
      $mtext = "{$email} has been subscribed to {$this->ListName}.\nPlease confirm your subscription by clicking this link:\n{$this->BaseURL}confirm/{$suid}/{$muid}";
      $mtype = "SUBSCRIBE";
    } else { // UPDATE PROFILE - existing subscriber - check if $suid exists - email is read only
      $valid = $this->RetrieveSubscriber($suid);
      if (!$valid) {
        $html .= '<p class=\"{{@pclass}}\">invalid subscriber id</p>';
        return $html;
      }
      $this->subscriber->s_unsubscribe = (int) 0;
      $this->subscriber->s_unsubscribedate = null;

      $this->smlog->logMsgUpdate($suid,$muid);
      $html .= "<p class=\"{{@pclass}}\">{$email} subscriber info updated</p>";

      // $subject = "{$this->ListName} notification: {$email} has been updated their profile";
      $subject = "{$email} has updated their profile on {$this->ListName}";
      $mhtml = "<p>Subscriber info for {$email} on {$this->ListName} has been updated.</p><p><a href=\"{$this->BaseURL}subscribe/{$suid}/{$muid}\">UPDATE YOUR PROFILE</a></p>";
      $mtext = "Subscriber info for {$email} on {$this->ListName} has been updated.\nUpdate your profile: {$this->BaseURL}subscribe/{$suid}/{$muid}";
      $mtype = "UPDATE-PROFILE";
    }

    $this->subscriber->s_subscribedby = $this->fat->get('POST.s_subscribedby');
    $this->subscriber->s_fname = trim($this->fat->get('POST.s_fname'));
    $this->subscriber->s_lname = trim($this->fat->get('POST.s_lname'));
    $this->subscriber->s_province = $this->fat->get('POST.s_province');
    $this->subscriber->s_country = trim($this->fat->get('POST.s_country'));
    $this->subscriber->s_gender = trim($this->fat->get('POST.s_gender'));
    $this->subscriber->save();

    $priority = (int) $this->fat->get('POST.s_priority') + 100;
    $this->setPriority($suid,$priority);

    $mfrom = $this->FromAddress;
    $sname = $this->subscriber->s_fname . " " . $this->subscriber->s_lname;

    $this->mailer->OpenSMTP();
    $this->mailer->SendNotification($muid,$mtype,$mfrom,$email,$sname,$subject,$mhtml,$mtext);
    $this->mailer->CloseSMTP();
    return $html;
  }

  // gotta fix this - check if $suid is valid
  public function CreateUnsubscribeHTMLform($suid,$muid = '') {
    $html = "";
    if (!$this->RetrieveSubscriber($suid)) {
      $gemail = $this->gu->getEmail($suid);
      if ($gemail <> '') {
        $html .= "<p class=\"{{@pclass}}\">Your {$gemail} has already been globally banned from receiving mail</p>";
      } else {
        $html .= "<p class=\"{{@pclass}}\">Your email is unknown to this service {$this->ListName}</p>";
      }
      return $html;
    }
    $semail = $this->subscriber->s_email;
    if ($this->subscriber->s_unsubscribe == 1) {
      $html .= "<p class=\"{{@pclass}}\">Your email account {$semail} on {$this->ListName} was previously removed</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("unsubscribeform","{{@BaseURL}}unsubscribe","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Unsubscribe");

    $html .= $ff->FF_hidden("suid",$suid);
    $html .= $ff->FF_hidden("muid",$muid);
    $html .= $ff->FF_hidden("email",$semail);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= "Email address to be unsubscribed: <b>{$semail}</b>";
    $html .= $ff->FF_DivClose();

    $radio_name = "sglobalunsubscribe";
    $radio_div_class = "{{@checkdivclass}}";
    $radio_class = "{{@checkclass}}";
    $radio_labelclass = "{{@checklabelclass}}";

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"sg1","b","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Address does not exist\n","sg1",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"sg2","s","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Stop sending me SPAM\n","sg2",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"sg3","g","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Remove me from ALL mailing lists\n","sg3",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"sg4","o"," checked",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Take me off this list only\n","sg4",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("sunsubscribereason","","","{{@textareaclass}}","{{@textareawidth2}}","{{@textareaheight2}}");
    $html .= $ff->FF_Label("Reason / comments","sunsubscribereason","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Unsubscribe","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  public function Unsubscribe() {
    $html = "";
    $suid = $this->fat->get('POST.suid');
    $muid = $this->fat->get('POST.muid');
    $pemail = $this->fat->get('POST.email');
    $sglobalunsubscribe = $this->fat->get('POST.sglobalunsubscribe');
    $sunsubscribereason = $this->fat->get('POST.sunsubscribereason');

    if ($this->fat->get('uadmin') == 1) {
      $admin = true;
    } else {
      $admin = false;
    }

    $exists = $this->RetrieveSubscriber($suid);
    if (!$exists) {
      $gemail = $this->gu->getEmail($suid);
      if ($gemail <> '') {
        $html .= "<p class=\"{{@pclass}}\">Your {$gemail} has been globally banned from receiving mail</p>";
      } elseif ($pemail <> '') {
        $html .= "<p class=\"{{@pclass}}\">Your {$pemail} is unknown to this service {$this->ListName}</p>";
      } else {
        $html .= "<p class=\"{{@pclass}}\">Your email is blank and is thus unknown to this service {$this->ListName}</p>";
      }
      return $html;
    }

    $semail = $this->subscriber->s_email;

    if ($this->subscriber->s_unsubscribe == 1) {
      $html .= "<p class=\"{{@pclass}}\">Your email account {$semail} on {$this->ListName} was previously erased</p>";
      return $html;
    }

    if ($sglobalunsubscribe == "b") {
      $global = true;
      $subject_tag = "BOUNCE";
      $gutype = ($admin ? 'BOUNCE-ADMIN' : 'BOUNCE');
    } elseif ($sglobalunsubscribe == "s") {
      $global = true;
      $subject_tag = "SPAM";
      $gutype = ($admin ? 'SPAM-ADMIN' : 'SPAM');
    } elseif ($sglobalunsubscribe == "g") {
      $global = true;
      $subject_tag = "GLOBAL";
      $gutype = ($admin ? 'ADMIN' : 'USER');
    } else { // unsubscribe only from this list
      $global = false;
      $subject_tag = "LIST";
      $gutype = "";
    }

    $this->subscriber->s_unsubscribe = (int) 1;
    $this->subscriber->s_unsubscribedate = date("Y-m-d H:i:s");
    $this->subscriber->s_unsubscribereason = $sunsubscribereason;
    $this->subscriber->save();

    $this->ResetPriority($suid);
    $this->smlog->logMsgUnsub($suid,$muid);

    $mtype = "UNSUBSCRIBE";
    $mfrom = $this->FromAddress;
    $sname = $this->subscriber->s_fname . " " . $this->subscriber->s_lname;
    $subject = "{$semail} has been unsubscribed from {$this->ListName} - {$subject_tag}";
    $mhtml = "<p>{$semail} has been unsubscribed from {$this->ListName}.</p><p>Reason: {$sunsubscribereason}</p><p><a href=\"{$this->BaseURL}subscribe/{$suid}/{$muid}\">RE-SUBSCRIBE</a></p>";
    $mtext = "{$semail} has been unsubscribed from {$this->ListName}.\nReason: {$sunsubscribereason}\nRe-subscribe: {$this->BaseURL}subscribe/{$suid}/{$muid}";

    $this->mailer->OpenSMTP();
    $this->mailer->SendNotification($muid,$mtype,$mfrom,$semail,$sname,$subject,$mhtml,$mtext);
    $this->mailer->CloseSMTP();

    $html .= "<p class=\"{{@pclass}}\">{$semail} has been unsubscribed from {$this->ListName}.</p>";

    if ($global) {
      $success = $this->gu->save($semail,$gutype,$sunsubscribereason);
      if ($success) $html .= "<p class=\"{{@pclass}}\">{$semail} was removed from ALL mailing lists.</p>";
    }
    return $html;
  }

  // returns true if added subscriber else false
  // maybe use AddOrUpdate() instead of save() defined in SubscriberM which calls FixEmail
  public function SimpleSubscribe($email,$priority = 0,$saddedby = 'ADMIN') {
    $email = strtolower(trim($email));
    if ($email == '') return false;
    $maxpriority = (int) 10000000;
    $valid = $this->LoadSubscriber($email);
    if ($valid) {
      $ipriority = max($priority,$this->subscriber->s_priority);
      $ipriority = min($ipriority,$maxpriority);
      $this->subscriber->s_priority = (int) $ipriority;
      // $this->subscriber->s_subscribedby = $saddedby;
      $this->subscriber->save();
    } else {
      $this->subscriber->reset();
      $this->subscriber->s_email = $email;
      $this->subscriber->s_priority = min($priority,$maxpriority);
      $this->subscriber->s_subscribedby = $saddedby;
      $this->subscriber->save();
      return true;
    }
    return false;
  }

  public function SyncSubscribers() {
    set_time_limit(86400);
    $dbservers = $this->fat->get('dbservers');
    $num_db_servers = count($dbservers);
    $total_added = 0;
    $fp1 = fopen("sync.txt", "a");
    $bdate = date("Y-m-d H:i:s");
    fwrite($fp1, "--------------\n");
    fwrite($fp1, $bdate . "\n");
    fwrite($fp1, "--------------\n");

    foreach ($dbservers as $db_server) {
      if (($db_server['active'] == 1) && ($this->Domain <> $db_server['domain'])) {
        $domain = $db_server['domain'];
        // $saddedby = $domain;
        fwrite($fp1, "Domain: $domain\n");
        $domain_total = 0;

        $dbhost = $db_server['host'];
        $dbuser = $db_server['user'];
        $dbpass = $db_server['pass'];
        $dbname = $db_server['name'];
        $driver = strtolower((string) ($db_server['driver'] ?? 'mysql'));
        $defaultPort = $driver === 'pgsql' ? 5432 : 3306;
        $port = (int) ($db_server['port'] ?? $defaultPort);

        if ($driver === 'pgsql') {
          $sslmode = (string) ($db_server['sslmode'] ?? 'prefer');
          $dsn = "pgsql:host={$dbhost};port={$port};dbname={$dbname};sslmode={$sslmode}";
        } elseif ($driver === 'mysql') {
          $charset = (string) ($db_server['charset'] ?? 'utf8mb4');
          $dsn = "mysql:host={$dbhost};port={$port};dbname={$dbname};charset={$charset}";
        } else {
          throw new RuntimeException("Unsupported sync database driver: {$driver}");
        }

        $extPDO = new \DB\SQL($dsn,$dbuser,$dbpass);

        $limit = 5000000;
        $sql = "select s_email, s_priority, s_last_interacted, s_subscribedby from subscribers WHERE (s_unsubscribe = 0) and (s_last_interacted is not null) ORDER BY s_last_interacted DESC, s_priority DESC, s_email ASC LIMIT {$limit}";

        $result = $extPDO->exec($sql);

        foreach ($result as $row) {
          // set_time_limit(0);
          $email = trim(strtolower($row['s_email']));
          $priority = (int) $row['s_priority'];
          $lastinteracted = $row['s_last_interacted'];
		  $saddedby = $row['s_subscribedby'];
          if (!is_null($lastinteracted)) $priority = (int) $priority + 1000000;
          $subscribed = $this->SimpleSubscribe($email,$priority,$saddedby);
          if ($subscribed) {
            fwrite($fp1, "{$email}\n");
            $total_added++;
            $domain_total++;
          }
        }
        $extPDO = null;
        $result = null;
        $row = null;
        fwrite($fp1, "Domain total subscribed: $domain_total\n");
      }
    }
    fwrite($fp1, "--------------\n");
    fwrite($fp1, "Total subscribed: $total_added\n");
    fclose($fp1);
    return $total_added;
  }

  // To display a drop down select box in the edit messages form
  public function CreateListHTMLDropDown($listName = "ALL") {
    $html = "";
    $sub = new SubscribersM($this->fat);
    $sub->s_total = "COUNT(s_subscribedby)";
    $sub->load(null,array('group' => 's_subscribedby', 'order' => 's_subscribedby'));
    $selected = ($listName == "ALL") ? 'selected' : '';
    $html .= "<option {$selected} value=\"ALL\">ALL</option>";
    while(!$sub->dry()) {
      $addedby = $sub->s_subscribedby;
      $stotal = $sub->s_total;
      $selected = ($listName == $addedby) ? 'selected' : '';
      $html .= "<option {$selected} value=\"{$addedby}\">{$addedby} ({$stotal})</option>";
      $sub->skip();
    }
    return $html;
  }

  // new parameter: $includeunsubs for activesubscribers is always 0
  public function CreateSubscribersHTMLList($ssemail = '',$pageno = 1,$numrows = 25,$activesubs = false, $includeunsubs = 0) {
    $html = "";
    $filter = null;
    $action = $activesubs ? "activesubscribers" : "subscribers";
    $ff = new formfield;
    // begin
    if ($this->fat->get('uadmin') == 1) {
      $html .= $ff->FF_FormOpen("subscribersform","{{@BaseURL}}{$action}","GET");
      $html .= $ff->FF_DivOpen("{{@rowclass}}");

      if (!$activesubs) {
        $html .= $ff->FF_DivOpen("{{@columnclass2}}");
        $html .= $ff->FF_DivOpen("{{@checkdivclass}}");
        $html .= $ff->FF_checkbox("u","us1","1","","{{@checkclass}}");
        $html .= $ff->FF_Label("\n&nbsp;Include unsubscribed\n","us1","{{@checklabelclass}}");
        $html .= $ff->FF_DivClose();
        $html .= $ff->FF_DivClose();
      }

      $html .= $ff->FF_DivOpen("{{@columnclass3}}");
      $html .= $ff->FF_DropDown("l",$this->CreateListHTMLDropDown(),"{{@selectclass}}");
      $html .= $ff->FF_Label("Listname","l","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass3}}");
      $html .= $ff->FF_input("e","search",$ssemail,"","{{@inputclass}}");
      $html .= $ff->FF_Label("Email address","e","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass2}}");
      $html .= $ff->FF_Button("submit","Search","{{@buttonclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_FormClose();
    } else {
      $html .= "<p class=\"{{@pclass}}\">Access denied</p>";
      return $html;
    }
    // end
    if ($activesubs) {
      $action = "activesubscribers";
      if ($ssemail == '') {
        $filter = array("(s_unsubscribe = :zero) and (s_last_interacted is not null)", ':zero' => 0);
      } else {
        $filter = array("(s_unsubscribe = :zero) and (s_last_interacted is not null) and (LOWER(s_email) like LOWER(:email))", ':zero' => 0, ':email' => "%{$ssemail}%");
      }
    } else {
      $action = "subscribers";
      if ($ssemail == '') {
        $filter = array("s_unsubscribe = :zero", ':zero' => 0);
      } else {
        $filter = array("(s_unsubscribe = :zero) and (LOWER(s_email) like LOWER(:email))", ':zero' => 0, ':email' => "%{$ssemail}%");
      }
    }

    // $html .= "<p class=\"{{@pclass}}\">filter: <pre>". print_r($filter,true) . "</pre></p>";
    $totalmatches = $this->subscriber->count($filter);
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\">No subscribers match that search.</p>";
      return $html;
    }
    $lastpage = ceil($totalmatches/$numrows);
    $pageno = (int) $pageno;
    if ($pageno > $lastpage) {
      $pageno = $lastpage;
    } elseif ($pageno < 1) {
      $pageno = 1;
    }
    // email, last interacted, priority, confirmed, unsubscribed,
    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Email","{{@thclass}}");
    $html .= $ff->FF_Th("Listname","{{@thclass}}");
    $html .= $ff->FF_Th("Last Interacted","{{@thclass}}");
    $html .= $ff->FF_Th("Priority","{{@thclass}}");
    $html .= $ff->FF_Th("Confirmed","{{@thclass}}");
    $html .= $ff->FF_Th("Unsubscribed","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $page = $this->subscriber->paginate($pageno - 1,$numrows,$filter,array('order' => 's_unsubscribe ASC, (s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC'));
    // $html .= "<p class=\"{{@pclass}}\">page.subset:<br/><pre>". print_r($page['subset'],true) . "</pre></p>";
    // $html .= "<p class=\"{{@pclass}}\">page.subset:<br/><pre> {$numrows} ". count($page['subset'],true) . "</pre></p>";

    foreach ($page['subset'] as $row) {
      $suid = $row['s_uniqid'];
      $semail = $row['s_email'];
      $slemail = "<a href=\"{{@BaseURL}}subscribe/{$suid}\"><i class=\"fa fa-pencil\" aria-hidden=\"true\"></i> {$semail}</a>";
      $saddedby = $row['s_subscribedby'];
      $lastinteracted = $row['s_last_interacted'];
      $spriority = $row['s_priority'];
      $sconfirm = ($row['s_confirm'] == 1 ? 'Yes' : "<a href=\"{{@BaseURL}}confirm/{$suid}\"><i class=\"fa fa-check\" aria-hidden=\"true\"></i> confirm</a>");
      $sunsubscribe = ($row['s_unsubscribe'] == 1 ? 'Yes' : "<a href=\"{{@BaseURL}}unsubscribe/{$suid}\"><i class=\"fa fa-times\" aria-hidden=\"true\"></i> unsubscribe</a>");
      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($slemail,"");
      $html .= $ff->FF_Td($saddedby,"");
      $html .= $ff->FF_Td($lastinteracted,"");
      $html .= $ff->FF_Td($spriority,"");
      $html .= $ff->FF_Td($sconfirm,"");
      $html .= $ff->FF_Td($sunsubscribe,"");
      $html .= $ff->FF_TrClose();
    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $qsemail = urlencode($ssemail);
    $querystring = '';
    if ($ssemail == '') {
      $querystring = '';
    } else {
      $querystring = "?e={$qsemail}";
    }

    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action,$querystring);
    return $html;
  }

  public function CreateBulkSubscribeHTMLform() {
    $html = "";
    if ($this->fat->get('uadmin') <> 1) {
      $html = "<p class=\"{{@pclass}}\">Access denied</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("bsubscribeform","{{@BaseURL}}bulk-subscribe","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Subscribe multiple emails");

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("spriority","number","10"," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Priority","spriority","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("saddedby","text","ADMIN"," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Added by / listname","saddedby","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("bemail","","","{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight2}}");
    $html .= $ff->FF_Label("Emails to subscribe","bemail","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Subscribe Emails","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  // BulkSubscribe from a file
  // this method uses the file() function to read the entire file into memory which places a limit on file size
  public function BulkSubscribe($fname,$spriority = 0,$saddedby = 'FILE',$prt_debug = false) {
    $html = "";
    $fcontents = file($fname);
    $html .= $this->BulkSubscribeForm($fcontents,$spriority,$saddedby,$prt_debug);
    return $html;
  }

  // method to iterate through lines in a file
  // this is a generator
  function GetLineFromFile($filename) {
    $fh = fopen($filename, 'r');
    while (($line = fgets($fh)) !== false) {
      yield $line;
    }
    fclose($fh);
  }

  // process a large input file, line by line, which is memory efficient
  public function BulkSubScribeFile($fname,$spriority = 0,$saddedby = 'FILE',$prt_debug = false) {
    $html = "";
    $num = 0;
    set_time_limit(86400);
    $fp1 = fopen("emails_added.txt", "a");
    $fp2 = fopen("emails_rejected.txt", "a");

    foreach ($this->GetLineFromFile($fname) as $line) {
      // set_time_limit(0);
      $nline = strtolower(trim($line));
      // Extract all the email addresses in $semail into an array
      $EmailAddresses = $this->find_email_addresses($nline);
      if (is_array($EmailAddresses)) {
        foreach ($EmailAddresses as $email) {
          $subscribed = $this->SimpleSubscribe($email,$spriority,$saddedby);
          if ($subscribed) {
            if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">{$email}</p>";
            fwrite($fp1, "{$email}\n");
            $num++;
          } else {
            if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">NS {$email}</p>";
          }
        }
      } else { // did not find at least one valid email address
        fwrite($fp2, "{$nline}\n");
      }
    }
    if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">Number subscribed: {$num}</p>";
    fwrite($fp1, "Number subscribed: {$num}\n");
    fclose($fp1);
    fclose($fp2);
    return $html;
  }

  public function BulkSubscribeForm($bemail,$spriority = 0,$saddedby = 'ADMIN',$prt_debug = true) {
    $html = "";
    // $html .= "<p class=\"{{@pclass}}\">{$bemail}</p>";
    set_time_limit(86400);
    if (is_array($bemail)) {
      // $html .= "<p class=\"{{@pclass}}\">bemail is an array</p>";
      $fcontents = $bemail;
    } else {
      // $pattern = array("/\r\n/","/\r/");
      // $txt = trim($bemail);
      // $txt = preg_replace($pattern, "\n", $txt);
      // $fcontents = explode("\n",$txt);
      // $fcontents = explode("\w",$bemail);
      // $fcontents = preg_split("\w",$bemail);
      preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $bemail, $matches);
      $fcontents = $matches[0];
      // $html .= "<pre>". print_r($fcontents,true) . "</pre>";
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
      // $html .= "<p class=\"{{@pclass}}\">{$semail}</p>";
      // Extract all the email addresses in $semail into an array
      $EmailAddresses = $this->find_email_addresses($semail);
      if (is_array($EmailAddresses)) {
        foreach ($EmailAddresses as $email) {
          $subscribed = $this->SimpleSubscribe($email,$spriority,$saddedby);
          if ($subscribed) {
            if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">{$email}</p>";
            fwrite($fp1, "{$email}\n");
            $num++;
          } else {
            if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">NS {$email}</p>";
          }
        }
      } else { // did not find at least one valid email address
        fwrite($fp2, "{$semail}\n");
      }
      // script is allowed to run for 10 mins
      // set_time_limit(0);
    }
    if ($prt_debug) $html .= "<p class=\"{{@pclass}}\">Number subscribed: {$num}</p>";
    fwrite($fp1, "Number subscribed: {$num}\n");
    fclose($fp1);
    fclose($fp2);
    return $html;
  }

  public function CreateBulkUnsubscribeHTMLform() {
    $html = "";
    if ($this->fat->get('uadmin') <> 1) {
      $html = "<p class=\"{{@pclass}}\">Access denied</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("bunsubscribeform","{{@BaseURL}}bulk-unsubscribe","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Unsubscribe multiple emails");

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $radio_name = "bbounce";
    $radio_div_class = "{{@checkdivclass}}";
    $radio_class = "{{@checkclass}}";
    $radio_labelclass = "{{@checklabelclass}}";

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"bb1","l"," checked",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Remove from this list only\n","bb1",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"bb2","d","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;Filter entire domains\n","bb2",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"bb3","b","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;These are bounces\n","bb3",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen($radio_div_class);
    $html .= $ff->FF_radio($radio_name,"bb4","s","",$radio_class);
    $html .= $ff->FF_Label("\n&nbsp;These are spam complainers\n","bb4",$radio_labelclass);
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("bemail","","","{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight2}}");
    $html .= $ff->FF_Label("Emails to unsubscribe","bemail","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Unsubscribe Emails","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  // bulk unsubscribes emails entered into a textarea, each on a separate line
  public function BulkUnsubscribeForm($bemail,$bounce = "") {
    $html = "";
    set_time_limit(86400);
    if ($bounce == "b") {
      $gutype = 'BOUNCE-ADMIN';
      $html .= "<p class=\"{{@pclass}}\">BOUNCE-ADMIN</p>";
      $globalunsub = true;
    } elseif ($bounce == "s") {
      $gutype = 'SPAM-ADMIN';
      $html .= "<p class=\"{{@pclass}}\">SPAM-ADMIN</p>";
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
          $domain = $this->subscriber->getEmailDomain($email);
          if ($email <> '') {
            if ($globalunsub) {
              if ($bounce == "d") {
                $this->gdu->save($domain,"SPAM");
                $html .= "<p class=\"{{@pclass}}\">GDU {$domain}</p>";
                $num++;
              } else {
                $this->gu->save($email,$gutype,$reason);
                $html .= "<p class=\"{{@pclass}}\">GU {$email}</p>";
                $num++;
              }
            } else {
              $added = $this->SimpleSubscribe($email);
              $this->subscriber->s_unsubscribe = (int) 1;
              $this->subscriber->s_unsubscribedate = date("Y-m-d H:i:s");
              $this->subscriber->s_unsubscribereason = $reason;
              $this->subscriber->save();
              $html .= "<p class=\"{{@pclass}}\">U {$email}</p>";
              $num++;
            }
          }
        }
      }
      // script is allowed to run forever
      // set_time_limit(0);
    }
    $html .= "<p class=\"{{@pclass}}\">Number unsubscribed: $num</p>";
    return $html;
  }

  public function ConfirmSubscription($suid,$muid = '') {
    $html = "";
    $valid = $this->RetrieveSubscriber($suid);
    if ($valid) {
      $html .= "<p class=\"{{@pclass}}\">Subscriber exists</p>";
      $email = $this->subscriber->s_email;
      $this->subscriber->s_confirm = (int) 1;
      $this->subscriber->s_confirmdate = date("Y-m-d H:i:s");
      $this->subscriber->save();

      $priority = $this->subscriber->s_priority + 10;
      $this->setPriority($suid,$priority);

      $this->smlog->logMsgConfirm($suid,$muid);

      $html .= "<p class=\"{{@pclass}}\">Subscriber updated: {$email}</p>";

      $mtype = "CONFIRM";
      $mfrom = $this->FromAddress;
      $sname = $this->subscriber->s_fname . " " . $this->subscriber->s_lname;
      // $subject = "{$this->ListName} notification: {$email} has confirmed subscription";
      $subject = "{$email} has confirmed subscription to {$this->ListName}";
      $mhtml = "<p>{$email} has confirmed their subscription to {$this->ListName}.</p><p>{$suid}</p>";
      $mtext = "{$email} has confirmed their subscription to {$this->ListName}.\n{$suid}";

      $this->mailer->OpenSMTP();
      $this->mailer->SendNotification($muid,$mtype,$mfrom,$email,$sname,$subject,$mhtml,$mtext);
      $this->mailer->CloseSMTP();

      $html .= "<p class=\"{{@pclass}}\">{$email} has confirmed their subscription to {$this->ListName}.</p>";
      return $html;
    } else {
      $html .= "<p class=\"{{@pclass}}\">Confirmation code is incorrect.</p>";
    }
    return $html;
  }
}