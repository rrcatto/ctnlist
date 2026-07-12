
<?php
/*

File: index.php
Version: 4.4
Author: Richard Catto
Creation Date: 2017-06-30
Update date: 2021.01.03 17:47

v4.4 2019.08.04 17h03 current one in use

v.4.5 - 4.6 skipped over

v4.7 2021.01.03 18h01 - never implemented, only proposed

Change View rendering to Template rendering:

$view = new View;
echo $view->render(CTNLIST_DESIGN_MAIN);

$content = \Template::instance()->resolve($content);
echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);

I'm calling resolve on $content before render because $content contains placeholders. See this url:
https://stackoverflow.com/questions/34131875/fat-free-framework-3-render-arbitrary-html-not-from-files#

Description:
entry point for app - front end controller

*/

// You may specify a relative or absolute path to the classes path here
define('CTNLIST_CLASS_DIRECTORY', '/usr/local/lib/php/ctnlist/4.4/classes/');

define('CLASS_FOLDERS', "/usr/local/lib/php/; /usr/local/lib/php/f3/lib/; /usr/local/lib/php/ctnlist/4.4/classes/; /usr/local/lib/php/lexer/lib/");

define('CTNLIST_VIEW_DIRECTORY', '/usr/local/lib/php/ctnlist/4.4/views/');
// define('CTNLIST_DESIGN_MAIN', 'main-template4c.php');
//define('CTNLIST_DESIGN_NEW_ORDERFORM', 'default-contact-form.php');
define('CTNLIST_DESIGN_MAIN', 'unify-main-template.html');
define('CTNLIST_DESIGN_NEW_ORDERFORM', 'unify-contact-form.html');
define('CTNLIST_DESIGN_STORE', 'unify-custom-store.html');

// Load Composer dependencies and environment configuration.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
  throw new RuntimeException('Composer dependencies are missing. Run: composer install');
}
require_once $composerAutoload;

\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

// Fat-Free Framework is now loaded through Composer.
$fat = \Base::instance();

$fat->set('CACHE',FALSE);

// debug level set to 3 for testing. 0 for production
$fat->set('DEBUG',0);
$fat->set('UI',CTNLIST_VIEW_DIRECTORY);
$fat->set('LOGS','/');
$fat->set('AUTOLOAD',CLASS_FOLDERS);

$fat->set('TZ',"Africa/Johannesburg");
$fat->set('ESCAPE',false);

// read global config file
$fat->config(CTNLIST_CLASS_DIRECTORY . 'dbconfig.ini',true);

// read unique domain config file
// $fat->config('config/config.ini',true);
$fat->config('../f3/config.ini',true);

// CSS classes for layout
// $fat->config('theme/design.ini',true);
$fat->config(CTNLIST_CLASS_DIRECTORY . 'design.ini',true);

// turns off the automatic creation of archives
if (!$fat->exists('archive')) {
  $fat->set('archive',(int) 0);
}

// $today = date("l, F d Y H:i e");
$today = date("Y.m.d H:i:s");
$fat->set('today',$today);

// deprecated
// $API = $fat->get('API');
// $DOMAIN = $fat->get('Domain');

// establishes a connection to the ctnlist MySQL database
$dbhost = $fat->get('dbhost');
$dbuser = $fat->get('dbuser');
$dbpass = $fat->get('dbpass');
$dbname = $fat->get('dbname');

$args = array(
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // generic attribute
    \PDO::ATTR_PERSISTENT => TRUE,  // we want to use persistent connections
    \PDO::MYSQL_ATTR_COMPRESS => TRUE, // MySQL-specific attribute
);

$dbPDO = new \DB\SQL("mysql:host={$dbhost};dbname={$dbname}",$dbuser,$dbpass,$args);

$fat->set('dbPDO',$dbPDO);

$version = '5.0.0-dev';
$fat->set('version',$version);

$options = new OptionsController($fat);

$oldversion = $options->GetOption('version');
$options->SetOption('version',$version);

$sess = new \DB\SQL\Session($dbPDO);

// checks if a person is logged in
$user = new UsersController($fat);

// manually set up dependency injection into class constructors
// $htmlhelper = new htmlhelper($fat);
$sendlog = new SendlogController($fat);
$smlog = new SmlogController($fat);
$subscriber = new SubscribersController($fat,$smlog);
$message = new MessagesController($fat,$subscriber,$smlog,$options);
$template = new TemplatesController($fat,$subscriber,$message);
$archive = new ArchivesController($fat,$message,$template);
$mailer = new mailer($fat,$subscriber,$message,$template,$sendlog,$smlog);
$queue = new QueueController($fat,$subscriber,$message,$template,$mailer,$options);

// Setters used to inject dependencies which are circular and thus cannot be injected via the constructor
$user->SetMailer($mailer);
$smlog->SetSubscriber($subscriber);
$smlog->SetMessage($message);
$subscriber->SetMailer($mailer);
$message->SetTemplate($template);
$message->SetQueue($queue);
$message->SetArchive($archive);
$message->SetMailer($mailer);

// Stats
$subs = $subscriber->NumSubscribers();
$fat->set('numsubscribers',$subs);

$ar = $subscriber->ActiveReaders();
$fat->set('activereaders',$ar);

$qcount = $queue->QueueCount();
$fat->set('qcount',$qcount);

$slcount = $sendlog->SendlogCount();
$fat->set('slcount',$slcount);

$msgcount = $message->MessageCount();
$fat->set('msgcount',$msgcount);

$templatecount = $template->TemplateCount();
$fat->set('templatecount',$templatecount);

/*
$confirms = $subscriber->Confirmed();
$fat->set('confirms',$confirms);
$unsub = $subscriber->Unsubscribed();
$fat->set('unsubscribed',$unsub);
*/

// logs every visitor to the site (domain)
$v = new SiteLogController($fat);

// number of tabular rows to display on a page
if ($fat->exists('GET.r')) {
    $r = $fat->get('GET.r');
} else $r = 10;
$fat->set('r',$r);

$fat->route('GET @home: /', function($fat,$params) {
  $fat->set('title','Home');
  $content = '';
  $content = "<p class=\"{{@pclass}}\">Welcome to {{@ListName}}</p>";

  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$index_route = [
  'GET /index',
  'GET /index.php',
  'GET /index.html',
  'GET /index.htm',
  'GET /home',
  'GET /home.php'
];

$fat->route($index_route, function($fat,$params) {
  $fat->reroute('@home');
});

// render this page using a separate design completely.
$fat->route('GET /privacy', function($fat,$params) {
  // $fat->set('title','Privacy Policy');
  // $fat->set('cfiletype',true);
  // $fat->set('content','privacy-policy.php');
  echo \Template::instance()->render('privacy-policy.html');
});

$fat->route('GET @contactform: /contact-form', function($fat,$params) {
    if ($fat->get('uloggedin')) {
        $fat->set('suid','');
        $fat->set('muid','');
        $fat->set('title','Contact Form');
        $webtemplate = CTNLIST_DESIGN_NEW_ORDERFORM;
    } else {
        $content = "<p class=\"{{@pclass}}\">Please login to use this form</p>";
        $fat->set('content',$content);
        $webtemplate = CTNLIST_DESIGN_MAIN;
    }
    echo \Template::instance()->render($webtemplate);
 });

$fat->route('GET /contact-form/@suid', function($fat,$params) use (&$subscriber) {
    $suid = $params['suid'];
    $fat->set('suid',$suid);
    $fat->set('muid','');
    $fat->set('title','Contact Form');
    $subscriber->bumpPriority($suid,12345);
    echo \Template::instance()->render(CTNLIST_DESIGN_NEW_ORDERFORM);
});

$fat->route('GET /contact-form/@suid/@muid', function($fat,$params) use (&$subscriber) {
    $suid = $params['suid'];
    $muid = $params['muid'];
    $fat->set('suid',$suid);
    $fat->set('muid',$muid);
    $fat->set('title','Contact Form');
    $subscriber->bumpPriority($suid,23456);
    echo \Template::instance()->render(CTNLIST_DESIGN_NEW_ORDERFORM);
});

$fat->route('POST /contact-form', function($fat,$params) use (&$mailer,&$subscriber) {
    $fat->set('title','Contact form submit');
    $formvars = array();
    $formvars['name'] = trim($fat->get('POST.cname'));
    $formvars['email'] = trim($fat->get('POST.cemail'));
    $formvars['Cell'] = trim($fat->get('POST.ccell'));
    $formvars['Web site'] = trim($fat->get('POST.cweb'));
    $formvars['Company'] = trim($fat->get('POST.ccompany'));
    $formvars['Topic'] = trim($fat->get('POST.ctopic'));
    $formvars['Comments'] = trim($fat->get('POST.cmessage'));

    $realm = $fat->get('POST.realm');
    $formvars['Booking-Form-URL'] = $realm;

    $suid = $fat->get('POST.suid');
    $email = $subscriber->getEmail($suid);
    $formvars['SubscriberEmail'] = $email;

    $formvars['IPAddr'] = $fat->get('IP');
    $formvars['UserAgent'] = $fat->get('AGENT');
    $formvars['XFWDFOR'] = getenv('HTTP_X_FORWARDED_FOR');

    $customer = new customers($fat,$mailer);
    $content = $customer->save($formvars);
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  });

$fat->route('GET /advanced-queue', function($fat,$params) use (&$message) {
  $fat->set('title','Advanced Queue');
  $content = $message->CreateAdvancedQueueHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /advanced-queue', function($fat,$params) use (&$message) {
  $fat->set('title','Advanced Queue');
  if ($fat->get('uadmin') == '1') {
    $muid = array();
    $i = 0;
    $m = $fat->get('POST.muid1');
    if ($m <> "") $muid[$i++] = $m;
    $m = $fat->get('POST.muid2');
    if ($m <> "") $muid[$i++] = $m;
    $m = $fat->get('POST.muid3');
    if ($m <> "") $muid[$i++] = $m;
    $m = $fat->get('POST.muid4');
    if ($m <> "") $muid[$i++] = $m;
    $mvolume = $fat->get('POST.mvolume');
    $content = $message->AdvancedSendListToQueue($muid,$mvolume);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archives', function($fat,$params) use (&$archive) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Archives');
  $content = $archive->CreateArchivesHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archives/@p', function($fat,$params) use (&$archive) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Archives');
  $content = $archive->CreateArchivesHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archive/@aid', function($fat,$params) use (&$archive) {
  $aid = $params['aid'];
  $content = $archive->ShowArchive($aid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archive/@aid/@suid/@muid', function($fat,$params) use (&$archive) {
  $aid = $params['aid'];
  $suid = $params['suid'];
  $muid = $params['muid'];
  $content = $archive->ShowArchive($aid,$suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /bulk-subscribe', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Bulk Subscribe');
  $content = $subscriber->CreateBulkSubscribeHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /bulk-subscribe', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Bulk Subscribe save');
  if ($fat->get('uadmin') == '1') {
    $bemail = $fat->get('POST.bemail');
    $spriority = $fat->get('POST.spriority');
    $saddedby = $fat->get('POST.saddedby');
    $content = $subscriber->BulkSubscribeForm($bemail,$spriority,$saddedby);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /bulk-unsubscribe', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Bulk Unsubscribe');
  $content = $subscriber->CreateBulkUnsubscribeHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /bulk-unsubscribe', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Bulk Unsubscribe save');
  if ($fat->get('uadmin') == '1') {
    $bbounce = $fat->get('POST.bbounce');
    $bemail = trim($fat->get('POST.bemail'));
    $content = $subscriber->BulkUnsubscribeForm($bemail,$bbounce);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});


$fat->route('GET /import', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Import Subscribers from File');
  if ($fat->get('uadmin') == '1') {
    $content = "<p class=\"{{@pclass}}\">Import subscribers from files</p>";
    $content .= $subscriber->BulkSubScribeFile("2021.01.12-jan.txt",2000000,'JANMACKAY');
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

// subscribe new EcWid customers automatically
$fat->route('POST /ecwid-subscribe', function($fat,$params) use (&$subscriber) {
  if ($fat->exists('POST.email')) {
		$email = $fat->get('POST.email');
	} else $email = '';
  $content = $subscriber->SimpleSubscribe($email,1000000,'ECWID');
});

$fat->route('GET /export', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Export Subscribers');
  if ($fat->get('uadmin') == '1') {
    // $offset = '0';
    // $limit = '1000000';
    $content = $subscriber->exportPDO();
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /export/@offset/@limit', function($fat,$params) use (&$subscriber) {
  $offset = $params['offset'];
  $limit = $params['limit'];
  $fat->set('title','Export Subscribers');
  if ($fat->get('uadmin') == '1') {
    $content = $subscriber->exportPDO($offset,$limit);
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /like/@suid/@muid', function($fat,$params) use (&$message) {
  $suid = $params['suid'];
  $muid = $params['muid'];
  $fat->set('title','Like Message');
  $content = $message->like($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /dislike/@suid/@muid', function($fat,$params) use (&$message) {
  $suid = $params['suid'];
  $muid = $params['muid'];
  $fat->set('title','Dislike Message');
  $content = $message->dislike($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /confirm/@suid/@muid', function($fat,$params) use (&$subscriber) {
  $muid = $params['muid'];
  $suid = $params['suid'];
  $fat->set('title','Confirm Subscription');
  $content = $subscriber->ConfirmSubscription($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /confirm/@suid', function($fat,$params) use (&$subscriber) {
  $suid = $params['suid'];
  $fat->set('title','Confirm Subscription');
  $content = $subscriber->ConfirmSubscription($suid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /edit-profile', function($fat,$params) use (&$user) {
  $fat->set('title','Edit Profile');
  if ($fat->get('uloggedin')) {
    $content = $user->CreateEditProfileHTMLform();
  } else {
    $content .= "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /edit-profile', function($fat,$params) use (&$user) {
  $fat->set('title','Save Profile');
  if ($fat->get('uloggedin')) {
    $content = $user->save();
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

// 2019.06.04 12h31 used for sending internal proof
$fat->route('GET /forward/@muid', function($fat,$params) use (&$message) {
  $FromAddress = $fat->get('FromAddress');
  $muid = $params['muid'];
  $suid = md5($FromAddress);
  $fat->set('title','Forward to');
  $content = $message->CreateForwardHTMLform($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /forward/@suid/@muid', function($fat,$params) use (&$message) {
  $muid = $params['muid'];
  $suid = $params['suid'];
  $fat->set('title','Forward to');
  $content = $message->CreateForwardHTMLform($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /forward', function($fat,$params) use (&$message) {
  $fat->set('title','Forward message');
  $suid = $fat->get('POST.suid');
  $muid = $fat->get('POST.muid');
  $bemail = $fat->get('POST.bemail');
  $content = $message->ForwardSubscribeMessage($suid,$muid,$bemail);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /forward-archive', function($fat,$params) use (&$message) {
  $fat->set('title','Forward archive');
  $content = $message->ForwardSubscribeArchive();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});


$fat->route('GET /profile', function($fat,$params) use (&$user)  {
  $fat->set('title','Profile');
  $content = $user->DisplayProfileHTML();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /login', function($fat,$params) use (&$user) {
  $fat->set('title','Login');
  $content = $user->CreateLoginHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /login', function($fat,$params) use (&$user) {
  $email = trim((string) $fat->get('POST.email'));

  // Deliberately ignore the result here. The same response is shown for valid,
  // invalid, existing and new addresses to avoid account enumeration.
  $user->requestMagicLink($email);

  $fat->set('title','Check your email');
  $content = '<p class="{{@pclass}}">If the address can receive mail, a one-time sign-in link has been sent. The link will expire shortly.</p>';
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /auth/verify', function($fat,$params) use (&$user) {
  $token = trim((string) $fat->get('GET.token'));
  $loginStatus = $user->verifyMagicLink($token);

  if ($loginStatus === 1) {
    $fat->reroute('/edit-profile');
  }

  if ($loginStatus === 2) {
    $fat->reroute('/profile');
  }

  $fat->set('title','Sign-in link invalid');
  $content = '<p class="{{@pclass}}">This sign-in link is invalid, expired or has already been used. Please request a new link.</p>';
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /logout', function($fat,$params) use (&$user) {
  $fat->set('title','Logout');
  $user->logout();
  $content = "<p class=\"{{@pclass}}\">You have been logged out!</p>";
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message', function($fat,$params) use (&$message) {
  $fat->set('title','Create new Message');
  $content = $message->CreateMessageHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message/@muid', function($fat,$params) use (&$message) {
  $muid = $params['muid'];
  $fat->set('title','Edit Message');
  $content = $message->CreateMessageHTMLform($muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /message', function($fat,$params) use (&$message) {
  $fat->set('title','Save Message');
  if ($fat->get('uadmin') == '1') {
    $content = $message->save();
    $fat->reroute("/messages/?s=1");
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

// s=1 saved = true
$fat->route('GET /messages', function($fat,$params) use (&$message) {
  if ($fat->exists('GET.s')) {
    $s = (bool) $fat->get('GET.s');
  } else $s = false;
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Messages');
  $content = $message->CreateMessagesHTMLList($p,$r,$s);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /messages/@p', function($fat,$params) use (&$message) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Messages');
  $content = $message->CreateMessagesHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message-views/@m', function($fat,$params) use (&$smlog) {
  $m = $params['m'];
  $p = 1; // page 1
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Message Reads');
  $content = $smlog->CreateMessageReadsHTMLList($m,$e,$p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message-views/@m/@p', function($fat,$params) use (&$smlog) {
  $m = $params['m'];
  $p = $params['p'];
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Message Reads');
  $content = $smlog->CreateMessageReadsHTMLList($m,$e,$p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});


$fat->route('GET /queue', function($fat,$params) use (&$queue) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Queue');
  $content = $queue->CreateQueueHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /queue/@p', function($fat,$params) use (&$queue) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Queue');
  $content = $queue->CreateQueueHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sendlog', function($fat,$params) use (&$sendlog) {
  $p = 1; // page 1
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  if ($fat->exists('GET.t')) {
    $t = $fat->get('GET.t');
  } else $t = '';
  $r = $fat->get('r');
  $fat->set('title','Send Log');
  $content = $sendlog->CreateSendlogHTMLList($e,$t,$p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sendlog/@p', function($fat,$params) use (&$sendlog) {
  $p = $params['p'];
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  if ($fat->exists('GET.t')) {
    $t = $fat->get('GET.t');
  } else $t = '';
  $r = $fat->get('r');
  $fat->set('title','Send Log');
  $content = $sendlog->CreateSendlogHTMLList($e,$t,$p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /store', function($fat,$params) {
  echo \Template::instance()->render(CTNLIST_DESIGN_STORE);
});

$fat->route('GET /sync', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Sync Subscribers');
  if ($fat->get('uadmin') == '1') {
    $content = $subscriber->SyncSubscribers();
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /processqueue', function($fat,$params) use (&$queue) {
  $fat->set('title','Process Queue');
  if ($fat->get('uadmin') == '1') {
    $content = $queue->ProcessQueue();
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /processqueue/@muid', function($fat,$params) use (&$queue) {
  $muid = $params['muid'];
  $fat->set('title','Process Queue');
  if ($fat->get('uadmin') == '1') {
    $content = $queue->ProcessQueue($muid);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /processqueue/@muid/@totaltosend', function($fat,$params) use (&$queue) {
  $muid = $params['muid'];
  $totaltosend = $params['totaltosend'];
  $fat->set('title','Process Queue');
  if ($fat->get('uadmin') == '1') {
    $content = $queue->ProcessQueue($muid,$totaltosend);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});


$fat->route('GET /queuelist/@muid', function($fat,$params) use (&$message) {
  $muid = $params['muid'];
  $fat->set('title','Queue emails');
  if ($fat->get('uadmin') == '1') {
    $content = $message->SendListToQueue($muid);
    echo $content;
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
    $content = \Template::instance()->resolve($content);
    $fat->set('content',$content);
    echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
  }
});

// deprecated
$fat->route('GET /sendtome/@muid', function($fat,$params) use (&$message) {
  $muid = $params['muid'];
  $fat->set('title','Send Test Email');
  $TestEmail = $fat->get('TestEmail');
  if ($fat->get('uadmin') == '1') {
    $content = $message->SendToAddress($muid,$TestEmail);
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /stop-send', function($fat,$params) use (&$options) {
  $fat->set('title','Stop Queue being Sent');
  if ($fat->get('uadmin') == '1') {
    $options->SetOption('SendQueue','N');
    $options->SetOption('CurrentlySending','N');
    $content = "<p class=\"{{@pclass}}\">Sending of Queue STOPPED</p>";
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribers', function($fat,$params) use (&$subscriber) {
  $p = 1; // page 1
  $activesubs = false;
  if ($fat->exists('GET.u')) {
    $u = (int) $fat->get('GET.u');
  } else $u = 0;
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r,$activesubs,$u);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribers/@p', function($fat,$params) use (&$subscriber) {
  $p = $params['p'];
  $activesubs = false;
  if ($fat->exists('GET.u')) {
    $u = (int) $fat->get('GET.u');
  } else $u = 0;
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r,$activesubs,$u);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /activesubscribers', function($fat,$params) use (&$subscriber) {
  $p = 1; // page 1
  $activesubs = true;
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Active Subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r,$activesubs);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /activesubscribers/@p', function($fat,$params) use (&$subscriber) {
  $p = $params['p'];
  $activesubs = true;
  if ($fat->exists('GET.e')) {
    $e = strtolower(trim($fat->get('GET.e')));
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Active Subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r,$activesubs);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribe', function($fat,$params) use (&$subscriber) {
  if ($fat->exists('GET.m')) {
    $muid = $fat->get('GET.m');
  } else $muid = '';
  $suid = '';
  $fat->set('title','Subscribe');
  $content = $subscriber->CreateSubscriberHTMLform($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribe/@suid', function($fat,$params) use (&$subscriber) {
  $suid = $params['suid'];
  $fat->set('title','Update Profile');
  $content = $subscriber->CreateSubscriberHTMLform($suid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

// existing subscriber
$fat->route('GET /subscribe/@suid/@muid', function($fat,$params) use (&$subscriber) {
  $suid = $params['suid'];
  $muid = $params['muid'];
  $fat->set('title','Update Profile');
  $content = $subscriber->CreateSubscriberHTMLform($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /subscribe', function($fat,$params) use (&$subscriber) {
  $suid = trim((string) $fat->get('POST.suid'));
  $fat->set('title','Save Subscriber');
  if (!$fat->get('uloggedin')) {
    $captcha1 = $fat->get('POST.captcha');
    $captcha2 = $fat->get('SESSION.captcha');
    if ($captcha1 <> $captcha2) {
      $fat->reroute("/subscribe/$suid");
    }
  }
  $content = $subscriber->save();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /templates', function($fat,$params) use (&$template) {
  if ($fat->exists('GET.s')) {
    $s = (bool) $fat->get('GET.s');
  } else $s = false;
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Templates');
  $content = $template->CreateTemplatesHTMLList($p,$r,$s);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /templates/@p', function($fat,$params) use (&$template) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Templates');
  $content = $template->CreateTemplatesHTMLList($p,$r);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /template', function($fat,$params) use (&$template) {
  $fat->set('title','Create new Template');
  $content = $template->CreateTemplateHTMLform();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /template/@tid', function($fat,$params) use (&$template) {
  $tid = $params['tid'];
  $fat->set('title','Edit Template');
  $content = $template->CreateTemplateHTMLform($tid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /template', function($fat,$params) use (&$template) {
  $fat->set('title','Save Template');
  if ($fat->get('uadmin') == '1') {
    $content = $template->save();
    $fat->reroute("/templates/?s=1");
  } else {
    $content = "<p class=\"{{@pclass}}\">Access denied</p>";
  }
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /unsubscribe/@suid', function($fat,$params) use (&$subscriber) {
  $suid = $params['suid'];
  $fat->set('title','Unsubscribe');
  $content = $subscriber->CreateUnsubscribeHTMLform($suid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /unsubscribe/@suid/@muid', function($fat,$params) use (&$subscriber) {
  $muid = $params['muid'];
  $suid = $params['suid'];
  $fat->set('title','Unsubscribe');
  $content = $subscriber->CreateUnsubscribeHTMLform($suid,$muid);
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /unsubscribe', function($fat,$params) use (&$subscriber) {
  $fat->set('title','Unsubscribe');
  $content = $subscriber->Unsubscribe();
  $content = \Template::instance()->resolve($content);
  $fat->set('content',$content);
  echo \Template::instance()->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /ut/@suid/@muid', function($fat,$params) use (&$message) {
  $muid = $params['muid'];
  $suid = $params['suid'];
  $message->TrackOpen($suid,$muid);
  header("Content-Type: image/png");
  print base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAABGdBTUEAALGPC/xhBQAAAAZQTFRF////AAAAVcLTfgAAAAF0Uk5TAEDm2GYAAAABYktHRACIBR1IAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH0gQCEx05cqKA8gAAAApJREFUeJxjYAAAAAIAAUivpHEAAAAASUVORK5CYII=');
});

$fat->route('GET /captcha', function($fat,$params) {
  $img = new Image();
  $img->captcha('fonts/captcha.ttf',24,5,'SESSION.captcha');
  $img->render();
});

$fat->set('ONERROR', function($fat,$params) {
  echo \Template::instance()->render('error.html');
});

$fat->run();
