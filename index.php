<?php
/*

File: index.php
Version: 1.0
Author: Richard Catto
Creation Date: 2014-08-23

Description:
Integration with Fat Free Framework
entry point for app - front end controller

*/

// You may specify a relative or absolute path to the classes path here
define('CTNLIST_CLASS_DIRECTORY', '/usr/local/lib/php/ctnlist4/');
define('CTNLIST_VIEW_DIRECTORY', '/usr/local/lib/php/ctnlist4/views/');
define('CTNLIST_DESIGN_MAIN', 'main-template.php');
define('CTNLIST_DESIGN_CONTACTFORM', 'contact-form.php');
define('CTNLIST_DESIGN_ORDERFORM', 'order-form.php');
define('CTNLIST_DESIGN_NEW_ORDERFORM', 'new-form-template.php');

// load fat free framework
$fat = require_once('/usr/local/lib/php/f3/lib/base.php');

// debug level set to max for testing. 0 for production
$fat->set('DEBUG',3);
$fat->set('UI','/usr/local/lib/php/ctnlist4/views/');
//$fat->set('UI','views/');
$fat->set('LOGS','/');

// ini_set('error_log',$fat->get('LOGS').'error.log');
// $logger = new Log('error.log');

require_once CTNLIST_CLASS_DIRECTORY .'autoload.php';

$fat->set('TZ',"Africa/Johannesburg");
$fat->set('ESCAPE',false);

// read global config file
$fat->config(CTNLIST_CLASS_DIRECTORY . 'dbconfig.ini');

// read unique domain config file
$fat->config('config/config.ini');

// none of these are accessible in the anonymous routing functions
$API = $fat->get('API');
$Domain = $fat->get('Domain');
$BaseURL = $fat->get('BaseURL');

$fat->set('CACHE',FALSE);

/*
if ($fat->exists('dbcache')) {
  $dbcache = $fat->get('dbcache');
  $fat->set('CACHE',"folder=$dbcache");
} else {
  $dbcache = "";
  $fat->set('CACHE',FALSE);
}
*/

// rpx response is POSTed to POST /login
$token_url = "{$BaseURL}login";

$fat->set('token_url',$token_url);

// establishes a connection to the ctnlist MySQL database
$mysqli = new start($fat);

// $dbconn = $mysqli->dbconn;
// $fat->set('dbconn',$mysqli->dbconn);
// $fat->set('version',$mysqli->version);

// identifier and session_token are used to determine if a user is logged in. if they match a record in the users table, that user is logged in
if ($fat->exists('COOKIE.identifier')) {
  $identifier = $fat->get('COOKIE.identifier');
} else {
  $identifier = '';
}

if ($fat->exists('COOKIE.session_token')) {
  $session_token = $fat->get('COOKIE.session_token');
} else {
  $session_token = '';
}

// instantiates a user object used for logging someone in
$user = new users($fat);

// this returns true if the info in the auth cookies match what is in the user record
$user->check_auth_cookies($identifier,$session_token);

$fat->set('user',$user);
$fat->set('uadmin',$user->uadmin);
$fat->set('uloggedin',$user->uloggedin);
$fat->set('ufname',$user->ufname);
$fat->set('ulname',$user->ulname);

/*
 * set up dependency injection of classes
 *
$fat->set('archives', function() use (&$fat) {
  return new archives($fat);
});
*/

$fat->set('archives', new archives($fat) );
$fat->set('subscribers', new subscribers($fat) );
$fat->set('mailer', new mailer($fat) );
$fat->set('templates', new templates($fat) );
$fat->set('queue', new queue($fat) );
$fat->set('sendlog', new sendlog($fat) );
$fat->set('htmlhelper', new htmlhelper($fat) );
$fat->set('messages', new messages($fat) );
$fat->set('options', new options($fat) );

// number of tabular rows to display on a page
$r = 25;
$fat->set('r',$r);

// default content file type is string, not file
$fat->set('cfiletype',false);

// define routes
$fat->route('GET @home: /', function($fat,$params) {
  $fat->set('title','Home');
  $fat->set('cfiletype',true);
  $fat->set('content','default-home.php');
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$index_route = [
  'GET /index.php',
  'GET /index',
  'GET /index.html',
  'GET /index.htm',
  'GET /home.php'
];

$fat->route($index_route, function($fat,$params) {
  $fat->reroute('@home');
});

/* test landing page for MRC */
/*
$fat->route('GET /home', function($fat,$params) {
  $fat->set('title','Home');
  $fat->set('cfiletype',true);
  // $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'landing_page.php');
  $view = new View;
  echo $view->render('landing-page.php');
});
*/

$fat->route('GET /privacy', function($fat,$params) {
  $fat->set('title','Privacy Policy');
  $fat->set('cfiletype',true);
  $fat->set('content','privacy.php');
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

// SOME CUSTOM ROUTING BASED ON API
if ($API == 'K8A999148G2JGBYSKRT') { // smart-poynt-phone - not used
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@application');
  });
  
  $fat->route('GET @application: /application-form', function($fat,$params) {
    $fat->set('title','Application Form');
    $fat->set('cfiletype',true);
    // $content = "<h3>Applications are closed!</h3><p>All trial stock has been sold out.</p>";
    // $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'smartp/router.php');
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . '/smartp/imod.php');
    $view = new View;
    echo $view->render('smartp/in-clean-post.php');
    $v = new views($fat);
    $v->LogPageView(); 
  });

  $fat->route('POST /application-form', function($fat,$params) use (&$user) {
    $fat->set('title','Save Application Form');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute('@application');
      }
    }
    $customer = new customers($fat);
    $customer->camount = $fat->get('POST.of_amount');
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_cell'));
    $customer->cinstructions = $fat->get('POST.of_delivery');
    $content = $customer->spSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render('smartp/in-clean-post.php');
    $v = new views($fat);
    $v->LogPageView();
  });
} elseif ($API == 'RTLMAC001890345MXME') { // sabizonline.online
 $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@applyform');
  });
    
  $fat->route('GET @applyform: /apply-form', function($fat,$params) {
    $fat->set('title','Application Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'sabiz/sabiz-apply-form.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_NEW_ORDERFORM);
  });

  $fat->route('POST /order-form', function($fat,$params) use (&$user) {
    $fat->set('title','Submit Loan Application');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute('@applyform');
      }
    }
    $customer = new customers($fat);
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_cell'));
    $customer->ccompany = trim($fat->get('POST.of_company'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->defaultSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_NEW_ORDERFORM);
  });
} elseif ($API == 'H7F041789V3CMBHFBM') { // Gary's domains
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->set('title','Booking Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'finsolve/concise-booking-form.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_CONTACTFORM);
  });

  $fat->route('POST /booking-form', function($fat,$params) use (&$user) {
    $fat->set('title','Save Booking Form');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute("/booking-form");
      }
    }
    $customer = new customers($fat);
    $customer->camount = $fat->get('POST.of_amount');
    $customer->cfname = trim($fat->get('POST.of_name'));
    // $customer->cfname = trim($fat->get('POST.of_fname'));
    // $customer->clname = trim($fat->get('POST.of_lname'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    // $customer->ccell = trim($fat->get('POST.of_cell'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    // $customer->clandline = trim($fat->get('POST.of_tel'));
    $customer->cstreet = trim($fat->get('POST.of_street'));
    $customer->cprovince = trim($fat->get('POST.of_province'));
    // $customer->curl = trim($fat->get('POST.of_url'));
    $customer->ccompany = trim($fat->get('POST.of_company'));
    $customer->cvat = trim($fat->get('POST.of_vat'));
    $customer->cdelegates = trim($fat->get('POST.of_delegates'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $customer->cvenue = trim($fat->get('POST.of_venue'));
    $customer->cdate = trim($fat->get('POST.of_date'));
    $content = $customer->mmSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} elseif ($API == 'EF73HI19L0X45V9MXXE') { // CoastProp's domains
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@moreinfo');
  });
    
  $fat->route('GET @moreinfo: /more-info', function($fat,$params) {
    $fat->set('title','Request More Info Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'cp/cp-booking-form2.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_ORDERFORM);
  });

  $fat->route('POST /more-info', function($fat,$params) use (&$user) {
    $fat->set('title','Save Request Form');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute("/booking-form");
      }
    }
    $customer = new customers($fat);
    $customer->cfname = trim($fat->get('POST.of_name'));
    // $customer->cfname = trim($fat->get('POST.of_fname'));
    // $customer->clname = trim($fat->get('POST.of_lname'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    // $customer->ccell = trim($fat->get('POST.of_cell'));
    // $customer->clandline = trim($fat->get('POST.of_tel'));
    $customer->cstreet = '';
    $customer->cprovince = '';
    $customer->curl = '';
    $customer->ccompany = '';
    $customer->cvat = '';
    $customer->camount = '';
    $customer->cdelegates = '';
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $customer->cvenue = '';
    $customer->cdate = '';
    $content = $customer->cpSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} elseif ($API == 'J8SKLM2S4VR00PLCN4') { // GloTrade's domains
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@demorequest');
  });
    
  $fat->route('GET @demorequest: /demo-request', function($fat,$params) {
    $fat->set('title','Demo Request');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'glotrade/glotrade-demo-request.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_ORDERFORM);
  });

  $fat->route('POST /demo-request', function($fat,$params) use (&$user) {
    $fat->set('title','Submit Demo Request');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute("/demo-request");
      }
    }
    $customer = new customers($fat);
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->cpSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} elseif ($API == 'UJK428KMSAM9Z47BHZ') { // McNab's domains
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@signup');
  });
    
  $fat->route('GET @signup: /sign-up', function($fat,$params) {
    $fat->set('title','Sign Up');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'mcnabs/mcnabs-sign-up.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_ORDERFORM);
  });

  $fat->route('POST /sign-up', function($fat,$params) use (&$user) {
    $fat->set('title','Submit Sign Up');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute("/sign-up");
      }
    }
    $customer = new customers($fat);
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->cpSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} elseif ($API == 'KJ86LM21H8Z38S2KINO') { // Nico-E's order form
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@orderform');
  });
    
  $fat->route('GET @orderform: /order-form', function($fat,$params) {
    $fat->set('title','Order Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'ne/ne-order-form.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_ORDERFORM);
  });

  $fat->route('POST /order-form', function($fat,$params) use (&$user) {
    $fat->set('title','Place Order');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute('@orderform');
      }
    }
    $customer = new customers($fat);
    $customer->camount = $fat->get('POST.of_amount');
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->neSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} elseif ($API == 'HBX9018JNLPM2S47XD') { // vrsdigitalonline.xyz
  $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@orderform');
  });
    
  $fat->route('GET @orderform: /order-form', function($fat,$params) {
    $fat->set('title','Order Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'vrsd/vrsd-order-form.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_ORDERFORM);
  });

  $fat->route('POST /order-form', function($fat,$params) use (&$user) {
    $fat->set('title','Place Order for VRS Digital Online');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute('@orderform');
      }
    }
    $customer = new customers($fat);
    // $customer->camount = $fat->get('POST.of_amount');
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_number'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->magCalSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  });
} else { // default booking-form goes here
 $fat->route('GET /booking-form', function($fat,$params) {
    $fat->reroute('@orderform');
  });
    
  $fat->route('GET @orderform: /order-form', function($fat,$params) {
    $fat->set('title','Order Form');
    $fat->set('cfiletype',true);
    $fat->set('content',CTNLIST_VIEW_DIRECTORY . 'default/default-order-form.php');
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_NEW_ORDERFORM);
  });

  $fat->route('POST /order-form', function($fat,$params) use (&$user) {
    $fat->set('title','Place Order');
    if (!$user->uloggedin) {
      $captcha1 = $fat->get('POST.captcha');
      $captcha2 = $fat->get('SESSION.captcha');
      if ($captcha1 <> $captcha2) {
        $fat->reroute('@orderform');
      }
    }
    $customer = new customers($fat);
    $customer->cfname = trim($fat->get('POST.of_name'));
    $customer->cemail = trim($fat->get('POST.of_email'));
    $customer->ccell = trim($fat->get('POST.of_cell'));
    $customer->ccompany = trim($fat->get('POST.of_company'));
    $customer->cinstructions = trim($fat->get('POST.of_instructions'));
    $content = $customer->defaultSave();
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_NEW_ORDERFORM);
  });
}
 
$fat->route('GET /advanced-queue', function($fat,$params) {
  $fat->set('title','Advanced Queue');
  $message = $fat->get('messages');
  $content = $message->CreateAdvancedQueueHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /advanced-queue', function($fat,$params) use (&$user) {
  $fat->set('title','Advanced Queue');
  if ($user->uadmin == '1') {
    $message = $fat->get('messages');
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
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /archives', function($fat,$params) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Archives');
  $archive = $fat->get('archives');
  $content = $archive->CreateArchivesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archives/@p', function($fat,$params) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Archives');
  $archive = $fat->get('archives');
  $content = $archive->CreateArchivesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /archive/@aid', function($fat,$params) {
  $aid = $params['aid'];
  $archive = $fat->get('archives');
  $archive->RetrieveArchive($aid);
  $fat->set('title',"Archive - $archive->asubject");
  $content = $archive->ShowArchive($aid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /bulk-subscribe', function($fat,$params) {
  $fat->set('title','Bulk Subscribe');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateBulkSubscribeHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /bulk-subscribe', function($fat,$params) use (&$user) {
  $fat->set('title','Bulk Subscribe save');
  if ($user->uadmin == '1') {
    $subscriber = $fat->get('subscribers');
    $spriority = $fat->get('POST.spriority');
    $bemail = trim($fat->get('POST.bemail'));
    $content = $subscriber->BulkSubscribeForm($bemail,$spriority);
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /bulk-unsubscribe', function($fat,$params) {
  $fat->set('title','Bulk Unsubscribe');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateBulkUnsubscribeHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /bulk-unsubscribe', function($fat,$params) use (&$user) {
  $fat->set('title','Bulk Unsubscribe save');
  if ($user->uadmin == '1') {
    $subscriber = $fat->get('subscribers');
    $bbounce = $fat->get('POST.bbounce');
    $bemail = trim($fat->get('POST.bemail'));
    $content = $subscriber->BulkUnsubscribeForm($bemail,$bbounce);
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /import', function($fat,$params) use (&$user) {
  $fat->set('title','Import Subscribers from File');
  if ($user->uadmin == '1') {
    $subscriber = $fat->get('subscribers');
    $content = "<p>Import subscribers from files</p>";
    $content .= $subscriber->BulkSubscribe("list1.txt",0);
    $content .= $subscriber->BulkSubscribe("list2.txt",0);
    $content .= $subscriber->BulkSubscribe("list3.txt",0);
    $content .= $subscriber->BulkSubscribe("list4.txt",0);
    $content .= $subscriber->BulkSubscribe("list5.txt",0);
    $content .= $subscriber->BulkSubscribe("list6.txt",0);
    $content .= $subscriber->BulkSubscribe("list7.txt",0);
    $content .= $subscriber->BulkSubscribe("list8.txt",0);
    $content .= $subscriber->BulkSubscribe("list9.txt",0);
    $content .= $subscriber->BulkSubscribe("list10-01.txt",0);
    $content .= $subscriber->BulkSubscribe("list10-02.txt",0);
    $content .= $subscriber->BulkSubscribe("list11.txt",0);
    $content .= $subscriber->BulkSubscribe("list12.txt",0);
    $content .= $subscriber->BulkSubscribe("list13.txt",0);
    $content .= $subscriber->BulkSubscribe("list14.txt",0);
    $content .= $subscriber->BulkSubscribe("list15.txt",0);
    $content .= $subscriber->BulkSubscribe("list16.txt",0);
    $content .= $subscriber->BulkSubscribe("list17.txt",0);
    $content .= $subscriber->BulkSubscribe("list18.txt",0);
    $content .= $subscriber->BulkSubscribe("list19.txt",0);
    $content .= $subscriber->BulkSubscribe("list20.txt",0);
    $content .= $subscriber->BulkSubscribe("list21.txt",0);
    $content .= $subscriber->BulkSubscribe("list22.txt",0);
    $content .= $subscriber->BulkSubscribe("list23.txt",0);
    $content .= $subscriber->BulkSubscribe("list24.txt",0);
    $content .= $subscriber->BulkSubscribe("list25.txt",0);
    $content .= $subscriber->BulkSubscribe("list26.txt",0);
    $content .= $subscriber->BulkSubscribe("list27.txt",0);
    $content .= $subscriber->BulkSubscribe("list28.txt",0);
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /checksubscribers', function($fat,$params) use (&$user) {
  $fat->set('title','Check Subscribers');
  if ($user->uadmin == '1') {
    $subscriber = $fat->get('subscribers');
    $subscriber->CheckSubscribers();
  } else {
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /checkgdu', function($fat,$params) use (&$user) {
  $fat->set('title','Check GDU');
  if ($user->uadmin == '1') {
    $subscriber = $fat->get('subscribers');
    $subscriber->CheckGDU();
  } else {
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /confirm/@suid', function($fat,$params) {
  $suid = $params['suid'];
  $fat->set('title','Confirm Subscription');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->ConfirmSubscription($suid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /confirm/@suid/@uid', function($fat,$params) {
  $suid = $params['suid'];
  $uid = $params['uid'];
  $fat->set('title','Confirm Claim');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->ConfirmSubscription($suid,$uid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /edit-profile', function($fat,$params) use (&$user) {
  $fat->set('title','Edit Profile');
  if ($user->uloggedin) {
    $content = $user->CreateEditProfileHTMLform();
  } else {
    $content .= "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /edit-profile', function($fat,$params) use (&$user) {
  $fat->set('title','Save Profile');
  $content = "";
  if ($user->uloggedin) {
    $uoemail = trim($fat->get('POST.uoemail'));
    $user->ufname = trim($fat->get('POST.ufname'));
    $user->ulname = trim($fat->get('POST.ulname'));
    $user->uphoto = trim($fat->get('POST.uphoto'));
    $user->ugender = trim($fat->get('POST.ugender'));
    $user->ubirthday = trim($fat->get('POST.ubirthday'));
    $user->uincome = trim($fat->get('POST.uincome'));
    $user->uoccupation = trim($fat->get('POST.uoccupation'));
    $user->ubusiness = trim($fat->get('POST.ubusiness'));
    $user->uaddress = trim($fat->get('POST.uaddress'));
    $user->usuburb = trim($fat->get('POST.usuburb'));
    $user->uprovince = trim($fat->get('POST.uprovince'));
    $user->ucountry = trim($fat->get('POST.ucountry'));
    $user->upostalcode = trim($fat->get('POST.upostalcode'));
    $user->uphone = trim($fat->get('POST.uphone'));
    $user->uemail = trim($fat->get('POST.uemail'));
    $user->uurl = trim($fat->get('POST.uurl'));
    $ubemail = trim($fat->get('POST.ubemail'));
    $content .= $user->UpdateProfile($uoemail);
    $subscriber = $fat->get('subscribers');
    // Claims the primary email
    $content .= $subscriber->ClaimEmail($user->uemail,$user->uid);
    // Claims the secondary emails
    $content .= $subscriber->ClaimEmail($ubemail,$user->uid);
  } else {
    $content .= "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /forward/@suid/@mid', function($fat,$params) {
  $suid = $params['suid'];
  $mid = $params['mid'];
  $fat->set('title','Forward to');
  $message = $fat->get('messages');
  $content = $message->CreateForwardHTMLform($suid,$mid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /forward', function($fat,$params) {
  $fat->set('title','Forward message');
  $suid = $fat->get('POST.suid');
  $mid = $fat->get('POST.mid');
  $bemail = $fat->get('POST.bemail');
  $message = $fat->get('messages');
  $content = $message->ForwardSubscribeMessage($suid,$mid,$bemail);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /forward-archive', function($fat,$params) {
  $fat->set('title','Forward archive');
  $aid = $fat->get('POST.aid');
  $bemail = $fat->get('POST.bemail');
  $message = $fat->get('messages');
  $content = $message->ForwardSubscribeArchive($aid,$bemail);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /phpinfo', function($fat,$params) {
  echo phpinfo();
});

$fat->route('GET /profile', function($fat,$params) use (&$user)  {
  $fat->set('title','Profile');
  $content = $user->DisplayProfileHTML();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /login', function($fat,$params) {
  $fat->set('title','Login');
  $content = "<div id=\"janrainEngageEmbed\"></div>";
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /login', function($fat,$params) use (&$user) {
  $token = $fat->get('REQUEST.token');
  $return_url = $fat->get('REQUEST.return_url');
  $post_data = array (
    'token' => $token,
    'apiKey' => $fat->get('rpx_api_key'),
    'format' => 'json'
  );
  $rpx_response = $user->rpx_http_post($fat->get('rpx_api_url'), $post_data);
  // parse the json formatted response into an associative array
  $auth_info = json_decode($rpx_response, true);
  $login_status = $user->process_auth($auth_info);
  // If logging in for the first time, redirect to profile.php with edit mode on
  // otherwise return to page the user was at when logging on
  if ($user->uloggedin) {
    // redirect to user profile page
    if ($login_status == '1') {
      // new user
      $fat->reroute("/edit-profile");
    } elseif ($login_status == '2') {
      // existing user
      $fat->reroute("/profile");
    } else {
      // login failed
      $fat->reroute("/login");
    }
  }
});

$fat->route('GET /logout', function($fat,$params) use (&$user) {
  $fat->set('title','Logout');
  $user->logout();
  $content = "<p>You have been logged out!</p>";
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message', function($fat,$params) {
  $fat->set('title','Create new Message');
  $message = $fat->get('messages');
  $content = $message->CreateMessageHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message/@muid', function($fat,$params) {
  $muid = $params['muid'];
  $fat->set('title','Edit Message');
  $message = $fat->get('messages');
  $content = $message->CreateMessageHTMLform($muid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /message', function($fat,$params) use (&$user) {
  $fat->set('title','Save Message');
  $message = $fat->get('messages');
  if ($user->uadmin == '1') {
    $message->muid = $fat->get('POST.muid');
    $message->mtid = $fat->get('POST.mtid');
    $message->mfrom = $fat->get('POST.mfrom');
    $message->mpriority = (int) $fat->get('POST.mpriority');
    $message->msubject = $fat->get('POST.msubject');
    $message->mhtml = $fat->get('POST.mhtml');
    $message->mtext = $fat->get('POST.mtext');
    $content = $message->save();
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /messages', function($fat,$params) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Messages');
  $message = $fat->get('messages');
  $content = $message->CreateMessagesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /messages/@p', function($fat,$params) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Messages');
  $message = $fat->get('messages');
  $content = $message->CreateMessagesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message-views/@m', function($fat,$params) {
  $m = $params['m'];
  $p = 1; // page 1
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/message-views/$m/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Message Views');
  $message = $fat->get('messages');
  $content = $message->CreateMessageViewsHTMLList($e,$m,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message-views/@m/@p', function($fat,$params) {
  $m = $params['m'];
  $p = $params['p'];
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/message-views/$m/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Message Views');
  $message = $fat->get('messages');
  $content = $message->CreateMessageViewsHTMLList($e,$m,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /message-views/@m/@p/@e', function($fat,$params) {
  $m = $params['m'];
  $p = $params['p'];
  $e = $params['e'];
  $r = $fat->get('r');
  $fat->set('title','Message Views');
  $message = $fat->get('messages');
  $content = $message->CreateMessageViewsHTMLList($e,$m,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /queue', function($fat,$params) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Queue');
  $queue = $fat->get('queue');
  $content = $queue->CreateQueueHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /queue/@p', function($fat,$params) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Queue');
  $queue = $fat->get('queue');
  $content = $queue->CreateQueueHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sendlog', function($fat,$params) {
  $p = 1; // page 1
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/sendlog/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Send Log');
  $sl = $fat->get('sendlog');
  $content = $sl->CreateSendlogHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sendlog/@p', function($fat,$params) {
  $p = $params['p'];
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/sendlog/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Send Log');
  $sl = $fat->get('sendlog');
  $content = $sl->CreateSendlogHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sendlog/@p/@e', function($fat,$params) {
  $p = $params['p'];
  $e = $params['e'];
  $r = $fat->get('r');
  $fat->set('title','Send Log');
  $sl = $fat->get('sendlog');
  $content = $sl->CreateSendlogHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /sync', function($fat,$params) use (&$user) {
  $fat->set('title','Sync Subscribers');
  $subscriber = $fat->get('subscribers');
  if ($user->uadmin == '1') {
    $content = $subscriber->SyncSubscribers();
  } else {
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /processqueue', function($fat,$params) use (&$user) {
  $fat->set('title','Process Queue');
  if ($user->uadmin == '1') {
    $queue = $fat->get('queue');
    $content = $queue->ProcessQueue();
  } else {
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /output-queue', function($fat,$params) use (&$user) {
  $fat->set('title','Output Queue');
  if ($user->uadmin == '1') {
      // $sql = "select distinct s_email from Queue_emails"; // this uses a view
      // view: select s_email from subscribers join queue on (s_uniqid = q_suid)
      $sql = "select distinct s_email from subscribers join queue on (s_uniqid = q_suid)";
      $dbconn = $fat->get('dbconn');
      $result = $dbconn->query($sql);
      echo "<p>";
      while ($row = $result->fetch_array()) {
          $semail = $row['s_email'];
          echo "$semail<br>";
      }
      echo "</p>";
  }
});

$fat->route('GET /export', function($fat,$params) use (&$user) {
  $fat->set('title','Export Subscribers');
  if ($user->uadmin == '1') {
    $offset = '0';
    $amount = '100000';
    $fat->reroute("/export/$offset/$amount");
  }
});

$fat->route('GET /export/@offset/@amount', function($fat,$params) use (&$user) {
  $offset = $params['offset'];
  $amount = $params['amount'];
  $fat->set('title','Export Subscribers');
  if ($user->uadmin == '1') {
    $sql = "select s_email from subscribers order by s_priority desc, s_email asc LIMIT ?,?";
    $args = array(
      1 => (int) $offset,
      2 => (int) $amount
    );
    $dbPDO = $fat->get('dbPDO');
    $result = $dbPDO->exec($sql,$args);
    $fp = fopen("export.txt", "a");
    set_time_limit(0);
    foreach ($result as $row) {
      $semail = $row['s_email'];
      fwrite($fp, "$semail\n" );
    }
    set_time_limit(30);
    fclose($fp);
    $content = "<p>Export complete</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /setadmin/@api', function($fat,$params) use (&$user) {
  $api = $params['api'];
  $API = $fat->get('API');
  $fat->set('title','Grant Admin rights');
  if (!$user->uloggedin) {
    $content = "<p>You must be logged in</p>";
  } elseif ($user->uadmin == '1') {
    $content = "<p>You already have admin rights</p>";
  } elseif ($api <> $API) {
    $content = "<p>wrong authenticator</p>";
  } else {
    $sql = "update users set u_admin = '1' where u_id = :uid";
    $args = array(':uid' => $user->uid);
    $dbPDO = $fat->get('dbPDO');
    $result = $dbPDO->exec($sql,$args);
    if ($result) {
      $content = "<p>Administrator rights granted</p>";
      $user->uadmin = '1';
      $fat->set('uadmin',$user->uadmin);
    } else {
      $content = "<p>ERROR: failed to grant admin rights</p>";
    }
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /queuelist/@muid', function($fat,$params) use (&$user) {
  $muid = $params['muid'];
  $fat->set('title','Queue emails');
  $message = $fat->get('messages');
  if ($user->uadmin == '1') {
    $content = $message->SendListToQueue($muid);
    echo $content;
  } else {
    $content = "<p>Access denied</p>";
    $fat->set('cfiletype',false);
    $fat->set('content',$content);
    $view = new View;
    echo $view->render(CTNLIST_DESIGN_MAIN);
  }
});

$fat->route('GET /sendtome/@muid', function($fat,$params) use (&$user) {
  $muid = $params['muid'];
  $fat->set('title','Send Test Email');
  $message = $fat->get('messages');
  $TestEmail = $fat->get('TestEmail');
  if ($user->uadmin == '1') {
    $content = $message->SendToAddress($muid,$TestEmail);
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /stop-send', function($fat,$params) use (&$user) {
  $fat->set('title','Stop Queue being Sent');
  if ($user->uadmin == '1') {
    $options = $fat->get('options');
    if ($options->SetOption("SendQueue","N")) {
        $options->SetOption("CurrentlySending","N");
        $content = "<p>Sending of Queue STOPPED</p>";
    } else {
        $content = "<p>FAILED to stop sending the queue</p>";
    }
  } else {
    $content = "<p>Access denied</p>";      
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribers', function($fat,$params) {
  $p = 1; // page 1
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/subscribers/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Subscribers');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribers/@p', function($fat,$params) {
  $p = $params['p'];
  if ($fat->exists('GET.e')) {
    $e = $fat->get('GET.e');
    $fat->reroute("/subscribers/$p/$e");
  } else $e = '';
  $r = $fat->get('r');
  $fat->set('title','Subscribers');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribers/@p/@e', function($fat,$params) {
  $p = $params['p'];
  $e = $params['e'];
  $r = $fat->get('r');
  $fat->set('title','Subscribers');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateSubscribersHTMLList($e,$p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribe', function($fat,$params) {
  $fat->set('title','Subscribe');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateSubscriberHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /subscribe/@suid', function($fat,$params) {
  $suid = $params['suid'];
  $fat->set('title','Update Profile');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateSubscriberHTMLform($suid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /subscribe', function($fat,$params) use (&$user) {
  $fat->set('title','Save subscriber');
  $subscriber = $fat->get('subscribers');
  $suid = $fat->get('POST.suid');
  if (!$user->uloggedin) {
    $captcha1 = $fat->get('POST.captcha');
    $captcha2 = $fat->get('SESSION.captcha');
    if ($captcha1 <> $captcha2) {
      $fat->reroute("/subscribe/$suid");
    }
  }
  if ($suid <> '') $subscriber->Read($suid);
  $subscriber->semail = trim($fat->get('POST.semail'));
  $subscriber->spriority = $fat->get('POST.spriority');
  $subscriber->sfname = trim($fat->get('POST.sfname'));
  $subscriber->slname = trim($fat->get('POST.slname'));
  $subscriber->sprovince = $fat->get('POST.sprovince');
  $subscriber->scountry = trim($fat->get('POST.scountry'));
  $subscriber->sgender = trim($fat->get('POST.sgender'));
  $success = $subscriber->save();
  $content = "<p>" . $subscriber->save_result . "</p>";
  if (!$success) {
    $content .= "<p>{$subscriber->save_error}</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /templates', function($fat,$params) {
  $p = 1; // page 1
  $r = $fat->get('r');
  $fat->set('title','Templates');
  $template = $fat->get('templates');
  $content = $template->CreateTemplatesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /templates/@p', function($fat,$params) {
  $p = $params['p'];
  $r = $fat->get('r');
  $fat->set('title','Templates');
  $template = $fat->get('templates');
  $content = $template->CreateTemplatesHTMLList($p,$r);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /template', function($fat,$params) {
  $fat->set('title','Create new Template');
  $template = $fat->get('templates');
  $content = $template->CreateTemplateHTMLform();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /template/@tid', function($fat,$params) {
  $tid = $params['tid'];
  $fat->set('title','Edit Template');
  $template = $fat->get('templates');
  $content = $template->CreateTemplateHTMLform($tid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /template', function($fat,$params) use (&$user) {
  $fat->set('title','Save Template');
  $template = $fat->get('templates');
  if ($user->uadmin == '1') {
    $template->tid = $fat->get('POST.tid');
    $template->tname = $fat->get('POST.tname');
    $template->thtml = $fat->get('POST.thtml');
    $template->ttext = $fat->get('POST.ttext');
    $content = $template->save();
  } else {
    $content = "<p>Access denied</p>";
  }
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /unsubscribe/@suid', function($fat,$params) {
  $suid = $params['suid'];
  $fat->set('title','Unsubscribe');
  $subscriber = $fat->get('subscribers');
  $content = $subscriber->CreateUnsubscribeHTMLform($suid);
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('POST /unsubscribe', function($fat,$params) {
  $fat->set('title','Unsubscribe');
  $subscriber = $fat->get('subscribers');
  $subscriber->suid = $fat->get('POST.suid');
  $subscriber->sglobalunsubscribe = $fat->get('POST.sglobalunsubscribe');
  $subscriber->sunsubscribereason = $fat->get('POST.sunsubscribereason');
  $content = $subscriber->Unsubscribe();
  $fat->set('cfiletype',false);
  $fat->set('content',$content);
  $view = new View;
  echo $view->render(CTNLIST_DESIGN_MAIN);
});

$fat->route('GET /ut/@suid/@mid', function($fat,$params) {
  $suid = $params['suid'];
  $mid = $params['mid'];
  $message = $fat->get('messages');
  $message->TrackOpen($suid,$mid);
  header("Content-Type: image/png");
  print base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAABGdBTUEAALGPC/xhBQAAAAZQTFRF////AAAAVcLTfgAAAAF0Uk5TAEDm2GYAAAABYktHRACIBR1IAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH0gQCEx05cqKA8gAAAApJREFUeJxjYAAAAAIAAUivpHEAAAAASUVORK5CYII=');
});

$fat->route('GET /captcha', function($fat,$params) {
  $img = new Image();
  $img->captcha('fonts/captcha.ttf',24,5,'SESSION.captcha');
  $img->render();
});

$fat->set('ONERROR', function($fat,$params) {
  $view = new View;
  echo $view->render('error.php');
});
  
$fat->run();
?>