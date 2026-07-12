
<?php
/*

Module: UsersContoller class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-06-30

*/

class UsersController extends Controller {
  protected $fat,
            $dbPDO,
            $BaseURL,
            $ListName,
            $FromAddress,
            $AdminEmail,
            $AdminName;

  public  $user,
          $uloggedin,
          $uadmin,
          $uid;

  public $mailer;

  public function __construct(Base $fat) {
    $this->fat = $fat;
    $this->dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');
    $this->ListName = $fat->get('ListName');
    $this->FromAddress = $fat->get('FromAddress');
    $this->AdminEmail = $fat->get('AdminEmail');
    $this->AdminName = $fat->get('AdminName');

    $this->user = new UsersM($fat);

    $this->uloggedin = false;
    $this->uadmin = 0;
    $this->uid = 0;
    $fat->set('uloggedin',false);
    $fat->set('uadmin',0);
    $fat->set('ufname','');
    $fat->set('ulname','');
    $fat->set('uname','');

    $this->check_auth_cookies();
  }

  public function SetMailer(mailer $mailer) {
    $this->mailer = $mailer;
  }

  public function logout() {
    setcookie('identifier', '', time() - 2419200, '/');
    setcookie('session_token', '', time() - 2419200, '/');
    $this->uloggedin = false;
    $this->uadmin = 0;
    $this->uid = 0;
    $this->fat->set('uloggedin',false);
    $this->fat->set('uadmin',0);
    $this->fat->set('ufname','');
    $this->fat->set('ulname','');
    $this->fat->set('uname','');
    $this->fat->set('SESSION.email','');
    $this->fat->set('SESSION.suid','');
    $this->fat->set('Email','');
    $this->user->reset();
  }

  // POSTs necessary data back to rpxnow
  public function rpx_http_post($url, $post_data) {
    $content = http_build_query($post_data);
    $opts = array ('http' => array ('method' => "POST", 'header' => "Content-Type: application/json; charset=utf-8", 'content' => $content));
    $context = stream_context_create($opts);
    $raw_data = file_get_contents($url, 0, $context);
    return $raw_data;
  }

  // checks if the person is logged in
  // <i class="fa fa-user-secret" aria-hidden="true"></i>
  public function check_auth_cookies() {
    // identifier and session_token are used to determine if a user is logged in. if they match a record in the users table, that user is logged in
    if ($this->fat->exists('COOKIE.identifier')) {
      $ident = $this->fat->get('COOKIE.identifier');
    } else {
      $ident = '';
    }

    if ($this->fat->exists('COOKIE.session_token')) {
      $token = $this->fat->get('COOKIE.session_token');
    } else {
      $token = '';
    }

    $this->user->load(array('u_identifier = :ident and u_session_token = :token', ':ident' => $ident, ':token' => $token));
    if (!$this->user->dry()) {
      $this->uloggedin = true;
      $this->uadmin = $this->user->u_admin;
      $this->uid = $this->user->u_id;
      $this->fat->set('uloggedin',true);
      $this->fat->set('uadmin',$this->uadmin);
      $ufname = trim($this->user->u_fname);
      $this->fat->set('ufname',$ufname);
      $ulname = trim($this->user->u_lname);
      $this->fat->set('ulname',$ulname);
      $uname = trim($ufname . ' ' . $ulname);
      $this->fat->set('uname',$uname);
      $this->fat->set('SESSION.email',$this->user->u_email);
      $this->fat->set('SESSION.suid',$this->user->u_suid);
      $this->fat->set('Email',$this->user->u_email);
      return true;
    } else {
      $this->logout();
      return false;
    }
  }

  public function CreateEditProfileHTMLform() {
    $html = "";
    if (!$this->uloggedin) {
      $html .= "<p class=\"{{@pclass}\">Please login to edit your profile.</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("profileform","{{@BaseURL}}edit-profile","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Edit Profile");

    // don't think this is needed anymore because email is not editable
    $html .= $ff->FF_hidden("u_oemail",$this->user->u_email);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_fname","text",$this->user->u_fname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("First Name","u_fname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_lname","text",$this->user->u_lname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Last Name","u_lname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_email","email",$this->user->u_email,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Email","u_email","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_phone","text",$this->user->u_phone,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Cell","u_phone","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_birthday","date",$this->user->u_birthday,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Birthdate","u_birthday","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_gender",$ff->CreateGenderHTMLDropDown($this->user->u_gender),"{{@selectclass}}");
    $html .= $ff->FF_Label("Gender","u_gender","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_province",$ff->CreateProvinceHTMLDropDown($this->user->u_province),"{{@selectclass}}");
    $html .= $ff->FF_Label("Province","u_province","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_country",$ff->CreateCountryHTMLDropDown($this->user->u_country),"{{@selectclass}}");
    $html .= $ff->FF_Label("Country","u_country","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("u_business","text",$this->user->u_business,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Company","u_business","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("u_url","text",$this->user->u_url,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Web site address","u_url","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_input("u_photo","text",$this->user->u_photo,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Photo URL","u_photo","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_Button("submit","Save Profile","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  public function save() {
    $html = "";
    $this->user->u_fname = trim($this->fat->get('POST.u_fname'));
    $this->user->u_lname = trim($this->fat->get('POST.u_lname'));
    $this->user->u_photo = trim($this->fat->get('POST.u_photo'));
    $this->user->u_gender = trim($this->fat->get('POST.u_gender'));
    $this->user->u_birthday = trim($this->fat->get('POST.u_birthday'));
    $this->user->u_business = trim($this->fat->get('POST.u_business'));
    $this->user->u_province = trim($this->fat->get('POST.u_province'));
    $this->user->u_country = trim($this->fat->get('POST.u_country'));
    $this->user->u_phone = trim($this->fat->get('POST.u_phone'));
    $this->user->u_email = trim($this->fat->get('POST.u_email'));
    $this->user->u_url = trim($this->fat->get('POST.u_url'));

    $u_oemail = trim($this->fat->get('POST.u_oemail'));
    // $u_bemail = trim($this->fat->get('POST.u_bemail'));

    $this->user->save();

    // $subscriber = $this->fat->get('subscribers');
    // Claims the primary email
    // $content .= $subscriber->ClaimEmail($this->user->u_email,$user->uid);
    // Claims the secondary emails
    // $content .= $subscriber->ClaimEmail($u_bemail,$user->uid);

    $html .= "<p class=\"{{@pclass}\">{$u_oemail} --> {$this->user->u_email} profile info updated.</p>";
    // send profile update notification email to user and admin

    $mtype = "UPDATE-USER";
    $muid = '';
    $mfrom = $this->FromAddress;
    $toemail = $this->user->u_email;
    $toname = $this->user->u_fname . " " . $this->user->u_lname;
    $subject = "{$this->ListName} notification: {$toemail} has updated their user profile";
    $mhtml = "<p>Profile for $this->uid on " . $this->ListName . " has been updated.</p>";
    $mtext = "Profile for $this->uid on " . $this->ListName . " has been updated.\n";

    if ($this->mailer->OpenSMTP()) {
      $html .= "<p class=\"{{@pclass}\">SMTP server opened</p>";
      $count = $this->mailer->SendNotification($muid,$mtype,$mfrom,$toemail,$toname,$subject,$mhtml,$mtext);
      $html .= "<p class=\"{{@pclass}\">$count email sent to {$this->user->u_email}</p>";
      $this->mailer->CloseSMTP();
    } else {
      $html .= "<p class=\"{{@pclass}\">SMTP server did not open</p>";
    }
    return $html;
  }

  public function DisplayProfileHTML() {
    $html = "";
    if (!$this->uloggedin) {
      $html .= "<p class=\"{{@pclass}\">Please login to view your profile.</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Photo","{{@thclasscenter}}");
    $html .= $ff->FF_Th("Personal Information","{{@thclasscenter}}"," colspan=4");
    // $html .= $ff->FF_Th("Contact","{{@thclass}}"," colspan=2");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $html .= $ff->FF_TrOpen("");
    $photo = "";
    if ($this->user->u_photo <> '') {
      $photo .= "<a href=\"{$this->user->u_url}\"><img width='200' height='200' src=\"{$this->user->u_photo}\"></a>";
    } else {
      $photo .= "<a href=\"{$this->user->u_url}\"><img width='200' height='200' src='img/placeholder.jpg'></a>";
    }
    $html .= $ff->FF_Td($photo,"{{@tdclass}}"," rowspan=6");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("First name:","");
    $html .= $ff->FF_Td($this->user->u_fname,"");
    $html .= $ff->FF_Td("Last name:","");
    $html .= $ff->FF_Td($this->user->u_lname,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Email:","");
    $html .= $ff->FF_Td($this->user->u_email,"");
    $html .= $ff->FF_Td("Cell:","");
    $html .= $ff->FF_Td($this->user->u_phone,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Birthday:","");
    $html .= $ff->FF_Td($this->user->u_birthday,"");
    $html .= $ff->FF_Td("Gender:","");
    $html .= $ff->FF_Td($this->user->u_gender,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Province:","");
    $html .= $ff->FF_Td($this->user->u_province,"");
    $html .= $ff->FF_Td("Country:","");
    $html .= $ff->FF_Td($this->user->u_country,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Company:","");
    $html .= $ff->FF_Td($this->user->u_business,"");
    $html .= $ff->FF_Td("Web site address:","");
    $html .= $ff->FF_Td($this->user->u_url,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    return $html;
  }

  public function process_auth($auth_info) {
    // get the status of the login request
    $status = $auth_info['stat'];
    // if it's not 'ok' then you did not login
    if ($status <> 'ok') {
      $this->logout();
      return 0;
    }

    // DEBUG: print the returned array to the screen
    /*
    echo "<pre>";
    print_r($auth_info);
    echo "</pre>";
    */

    $profile = $auth_info['profile'];

    $ident = $profile['identifier'];

    $this->user->load(array('u_identifier = :ident', ':ident' => $ident));

    // The user has been successfully authenticated by rpxnow
    // this will create a new user if necessary, populate the user record with data received from rpxnow, generate a session_token, store it and set the cookies to log the user in
    $this->uloggedin = true;
    $this->fat->set('uloggedin',true);

    // generate fresh session token
    $token = $this->user->CreateToken('u_session_token');
    $this->user->u_session_token = $token;
    $this->user->u_last_login = date("Y-m-d H:i:s");

    // Set the auth cookies
    // Cookies expire in 28 days time
    setcookie('identifier', $ident, time() + 2419200, '/');
    setcookie('session_token', $token, time() + 2419200, '/');

    // IP address of the person logging in or the IP address of their proxy server
    $this->user->u_ip = $this->fat->get('IP');
    // Their real IP addresss if behind a proxy. Can be spoofed in some cases
    $this->user->u_xfwdfor = getenv('HTTP_X_FORWARDED_FOR');

    if (!$this->user->dry()) { // existing user
      $this->uid = $this->user->u_id;
      $this->uadmin = $this->user->u_admin;
      $this->fat->set('uadmin',$this->uadmin);
      $ufname = trim($this->user->u_fname);
      $this->fat->set('ufname',$ufname);
      $ulname = trim($this->user->u_lname);
      $this->fat->set('ulname',$ulname);
      $uname = trim($ufname . ' ' . $ulname);
      $this->fat->set('uname',$uname);
      $this->user->save();
      $return_value = '2';
    } else { // new user
      $this->user->u_uniqid = $this->user->CreateToken('u_uniqid');
      $this->user->u_identifier = $ident;

      $this->uadmin = 0;
      $this->fat->set('uadmin',$this->uadmin);

      $this->user->u_provider = $profile['providerName'];
      $this->user->u_username = $profile['preferredUsername'] ?? '';
      $this->user->u_gender = $profile['gender'] ?? '';
      $this->user->u_birthday = $profile['birthday'] ?? '';
      $this->user->u_email = $profile['email'] ?? '';
      $this->user->u_url = $profile['url'] ?? '';

      $this->user->u_phone = $profile['phoneNumber'] ?? '';
      $this->user->u_photo = $profile['photo'] ?? '';

      $name = $profile['name'] ?? '';

      $this->user->u_fname = $name['givenName'] ?? '';
      $this->user->u_lname = $name['familyName'] ?? '';

      $ufname = trim($this->user->u_fname);
      $this->fat->set('ufname',$ufname);
      $ulname = trim($this->user->u_lname);
      $this->fat->set('ulname',$ulname);
      $uname = trim($ufname . ' ' . $ulname);
      $this->fat->set('uname',$uname);

      $address = $profile['Address'] ?? '';

      $this->user->u_province = $address['region'] ?? '';

      // $ucountry = $profile['country'] ?? '';
      $this->user->u_country = $address['country'] ?? '';

      $this->user->save();
      $this->uid = $this->user->_id;
      $this->fat->set('SESSION.email',$this->user->u_email);
      $this->fat->set('SESSION.suid',$this->user->u_suid);
      $this->fat->set('Email',$this->user->u_email);
      $return_value = '1';
    }
    return $return_value;
  }

}
