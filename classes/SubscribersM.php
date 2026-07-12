<?php
/*

Module: Subscribers model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-02

Description:
models the Subscribers table

*/

class SubscribersM extends \DB\SQL\Mapper {
  public $fat, $gu, $gdu;

  public function __construct(Base $fat) {
    $this->fat = $fat;
    $dbPDO = $fat->get('dbPDO');

    /*** not used
    `s_password` varchar(100) DEFAULT NULL,
    `s_session_token` varchar(32) DEFAULT NULL,
    `s_login_token` varchar(32) DEFAULT NULL,
    `s_last_login` datetime DEFAULT NULL,
    `s_ip` varchar(100) DEFAULT NULL,
    `s_xfwdfor` varchar(100) DEFAULT NULL,
    `s_admin` tinyint(1) unsigned NOT NULL DEFAULT '0',
    ***/

    // 2019.06.19 maximum size of email address is 254
    // maximum size of user name is 64
    // maximum size of domain name is 253
    // s_subscribedby = Listname the subscriber belongs to

    // Database schema is managed by Phinx migrations.

    $this->gu = new GlobalUnsubscribeController($fat);
    $this->gdu = new GlobalDomainUnsubscribeController($fat);

    parent::__construct($dbPDO,'subscribers');
  }

  public function read($suid) {
    $this->load(array('s_uniqid = :suid', ':suid' => $suid));
    $this->fixEmail();
    return $this->valid();
  }

  public function loadByEmail($email) {
    $email = strtolower(trim($email));
    $this->load(array('s_email = :email', ':email' => $email));
    $this->fixEmail();
    return $this->valid();
  }

  public function isSubscribed($email) {
    $count = $this->count(array('s_email = :email', ':email' => $email));
    return (bool) ($count == 1);
  }

  public function getEmail($suid) {
    if ($this->read($suid)) {
      return $this->s_email;
    }
    return '';
  }

 public function bumpPriority($suid,$p = 1) {
    $valid = $this->read($suid);
    if ($valid) {
      $priority = (int) $this->s_priority + $p;
      $this->s_bounces = (int) 0;
      $this->s_emailsleft = (int) $this->fat->get('SubscriptionConfirmAmount');
      $this->s_priority = (int) $priority;
      $this->s_last_interacted = date("Y-m-d H:i:s");
      $this->save();
    }
    return $valid;
  }

  public function resetPriority($suid) {
    $valid = $this->read($suid);
    if ($valid) {
      $this->s_bounces = (int) 0;
      $this->s_emailsleft = (int) 0;
      $this->s_priority = (int) 0;
      $this->s_last_interacted = null;
      $this->save();
    }
    return $valid;
  }

  public function setPriority($suid,$priority) {
    $valid = $this->read($suid);
    if ($valid) {
      $this->s_bounces = (int) 0;
      $this->s_emailsleft = (int) $this->fat->get('SubscriptionConfirmAmount');
      $this->s_priority = (int) $priority;
      $this->s_last_interacted = date("Y-m-d H:i:s");
      $this->save();
    }
    return $valid;
  }

  public function numsubscribers() {
    return $this->count(array("`s_unsubscribe` = :zero", ':zero' => 0));
  }

  public function activeReaders() {
    return $this->count(array("`s_unsubscribe` = :zero and `s_last_interacted` is not null", ':zero' => 0));
  }

  public function confirmed() {
    return $this->count(array('`s_confirm` = :one and `s_unsubscribe` = :zero', ':one' => 1, ':zero' => 0));
  }

  public function unsubscribed() {
    return $this->count(array('s_unsubscribe = :one', ':one' => 1));
  }

  public function fixUser($user) {
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
    // $nemail = preg_replace("/^support/","@@",$nemail);
    $nemail = preg_replace("/^sysadmin/","@@",$nemail);
    $nemail = preg_replace("/^sysadm/","@@",$nemail);
    $nemail = preg_replace("/^complaints/","@@",$nemail);
    $nemail = preg_replace("/^hostmaster/","@@",$nemail);
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
    $nemail = preg_replace("/^pop\.wanadoo\.fr/","",$nemail);

    $nemail = str_ireplace("pop.wanadoo.fr","",$nemail);

    $nemail = str_ireplace("editor","@@",$nemail);
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
      $nemail = str_ireplace("undisclosed.recipients","@@",$nemail);
		$nemail = str_ireplace("www.","",$nemail);

    return $nemail;
  }

  public function fixDomain($domain) {
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
    $nemail = preg_replace("/sars\.cov\.za$/","sars.gov.za",$nemail);


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
    $nemail = str_ireplace("yahoio.com","yahoo.com",$nemail);

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
        $nemail = str_ireplace("ifrica.com","iafrica.com",$nemail);
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

    // domain name changes
    $nemail = str_ireplace("kkdisplay.co.za","storequip.co.za",$nemail);
    $nemail = str_ireplace("mpsa.co.za","mpact.co.za",$nemail);
    $nemail = str_ireplace("versapak.co.za","mpact.co.za",$nemail);
    $nemail = str_ireplace("lionpackaging.co.za","mpact.co.za",$nemail);
    $nemail = str_ireplace("sacks-online.com","sacks.za.net",$nemail);
    $nemail = str_ireplace("corobrick.co.za","corobrik.co.za",$nemail);
    $nemail = str_ireplace("nopsa.co.za","shop-sa.co.za",$nemail);
    $nemail = str_ireplace("iledi.co.za","kpec.co.za",$nemail);

		// domain spaces to invalidate
		$nemail = preg_replace("/\.ac\.uk$/","@@",$nemail);
		$nemail = preg_replace("/\.ac\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.edu$/","@@",$nemail);
		$nemail = preg_replace("/\.edu\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.mil$/","@@",$nemail);
		$nemail = preg_replace("/\.gov$/","@@",$nemail);
		$nemail = preg_replace("/\.gov\.za$/","@@",$nemail);
		$nemail = preg_replace("/\.gov\.uk$/","@@",$nemail);
		$nemail = preg_replace("/\.gov\.sg$/","@@",$nemail);
		$nemail = preg_replace("/\.gnu\.org$/","@@",$nemail);
    $nemail = preg_replace("/\.org$/","@@",$nemail);
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
    $nemail = preg_replace("/\.bf$/","@@",$nemail);
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
	  $nemail = preg_replace("/\.fk$/","@@",$nemail);
		$nemail = preg_replace("/\.fr$/","@@",$nemail);
		$nemail = preg_replace("/\.gh$/","@@",$nemail);
		$nemail = preg_replace("/\.gr$/","@@",$nemail);
    $nemail = preg_replace("/\.gt$/","@@",$nemail);
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
    $nemail = preg_replace("/\.ml$/","@@",$nemail);
		$nemail = preg_replace("/\.mm$/","@@",$nemail);
		$nemail = preg_replace("/\.mt$/","@@",$nemail);
		$nemail = preg_replace("/\.mu$/","@@",$nemail);
		$nemail = preg_replace("/\.mx$/","@@",$nemail);
		$nemail = preg_replace("/\.my$/","@@",$nemail);
		$nemail = preg_replace("/\.mz$/","@@",$nemail);
    $nemail = preg_replace("/\.na$/","@@",$nemail);
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
    $nemail = preg_replace("/\.rw$/","@@",$nemail);
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

  public function fixCommonErrors($email) {
    // fix common errors
    $nemail = preg_replace("/^-{1,10}/","",$email);
    $nemail = preg_replace("/^_{1,10}/","",$nemail);
    $nemail = preg_replace("/-{2,10}/","-",$nemail);
    $nemail = preg_replace("/\.{2,10}/",".",$nemail);
    // $nemail = preg_replace("/^20/","",$nemail);
    // $nemail = preg_replace("/%20/","",$nemail);
    // $nemail = preg_replace("/^3[a-d]/","",$nemail);
    $nemail = preg_replace("/^mailto\./","",$nemail);
    $nemail = preg_replace("/^mailto/","",$nemail);
      $nemail = preg_replace("/^smtp/","",$nemail);
    $nemail = preg_replace("/^address/","",$nemail);
    $nemail = preg_replace("/^addr/","",$nemail);
    // $nemail = preg_replace("/^[0-9]/","@@",$nemail);
    $nemail = preg_replace("/\.-|-\./",".",$nemail);
    $nemail = preg_replace("/\.@|@\./","@",$nemail);
    $nemail = preg_replace("/-@|@-/","@",$nemail);
    $nemail = preg_replace("/_@|@_/","@",$nemail);

    return $nemail;
  }

  public function addressChanges($email) {
    // address changes
    $nemail = str_ireplace("thecotmpanyofwinepeople","thecompanyofwinepeople",$email);
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

    return $nemail;
  }

  public function getEmailUser($email) {
    $user = preg_replace("/@.*$/","",$email);
    return $user;
  }

  public function getEmailDomain($email) {
    $domain = preg_replace("/^.*@/","",$email);
    return $domain;
  }

  public function fixEmail() {
    if ($this->dry()) return true;
    $email = $this->s_email;
    $nemail = trim(strtolower($email));
    $nemail = $this->fixCommonErrors($nemail);
    $nemail = $this->addressChanges($nemail);

	  // check if email in whitelist domain
	  // check if email in whitelist address
	  // if yes to either, bypass all blacklist checks

    $fp1 = fopen("deleted.txt", "a");
    $bdate = date("Y-m-d H:i:s");

    $user = $this->GetEmailUser($nemail);
    $domain = $this->GetEmailDomain($nemail);

    $user = $this->fixUser($user);
    $domain = $this->fixDomain($domain);
    $email = $user . "@" . $domain;

    $domain_filtered = $this->gdu->IsUnsubscribed($domain);
    if ($domain_filtered) { // filtered domain
      fwrite($fp1, $bdate . " GDU {$email}\n");
      fclose($fp1);
      $this->erase();
      $this->skip();
      return false;
    }

    $email_unsubbed = $this->gu->IsUnsubscribed($email);
    if ($email_unsubbed) { // filtered email
      fwrite($fp1, $bdate . " GU {$email}\n");
      fclose($fp1);
      $this->erase();
      $this->skip();
      return false;
    }

    $audit = \Audit::instance();
    // check if email address is valid
    $valid = $audit->email($email,false);

    if ($valid) {
      // check if domain is valid
      /*
      $valid_domain = $audit->email($email,true);
      if (!$valid_domain) {
        // add domain to GDU
        $this->gdu->save($domain,"NOTEXIST");
        fwrite($fp1, $bdate . " BAD-DOMAIN {$email}\n");
        fclose($fp1);
        $this->erase();
        $this->skip();
        return false;
      }
      */
    } else { // bad email
      if ($user <> '' && $domain <> '') {
        $this->gu->save($email,"INVALID","INVALID");
      }
      fwrite($fp1, $bdate . " BAD-EMAIL {$email}\n");
      fclose($fp1);
      $this->erase();
      $this->skip();
      return false;
    }

    if ($this->s_email <> $email) {
      $this->s_email = $email;
      try {
        $this->save();
      } catch (PDOException $e) {
        fwrite($fp1, $bdate . " DUPLICATE {$email}\n");
        fclose($fp1);
        $this->erase();
        $this->skip();
        return false;
      }
    }

    fclose($fp1);
    return true;
  }

}