<?php
/*

Module: SiteLoginController
Version: 1.0
Author: Richard Catto
Creation Date: 2017-03-02

Description:
logs every visit to the domain

*/

class SiteLogController extends Controller {
  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    $sitelog = new SiteLogM($fat);

    $sitelog->reset();

    $path = $fat->get('PATH');
    $sitelog->sl_url = $path;

    $email = $fat->get('Email');

    $md5regex = "/[0-9a-fA-F]{32}/";
    if ($email == '') {
      // finds the first md5 in the path and uses it as a suid to find email address
      if (preg_match($md5regex,$path,$matches)) {
        $suid = $matches[0];
        $SubM = new SubscribersM($fat);
        $email = $SubM->getEmail($suid);
      }
    }

    $sitelog->sl_email = $email;
    $sitelog->sl_url = $fat->get('PATH');

    $ip = $fat->get('IP');
    $sitelog->sl_ip = $ip;

    $visitorhost = gethostbyaddr($ip);
    $sitelog->sl_host = $visitorhost;

    $sitelog->sl_xfwdfor = getenv('HTTP_X_FORWARDED_FOR');
    $sitelog->sl_agent = $fat->get('AGENT');
    $sitelog->sl_logged_in = (int) $fat->get('uloggedin');

    $sitelog->save();
  }
}
