<?php
/*

Module: users class
Version: 1.3
Author: Richard Catto
Creation Date: 2009-10-08

Description:
This class logs users in and allows them to manage their profile plus claim email addresses belonging to them.
start must be instantiated before this class is instantiated in order to open the mysql database

*/

class users {

	protected $fat;
	protected $dbconn;
	protected $BaseURL;
	protected $ListName;
	protected $FromAddress;
	protected $AdminEmail;
	protected $AdminName;

	// user variables
	public $uloggedin;
	public $uid;
	public $uuid;
	public $uidentifier;
	public $uprovider;
	public $uadmin;

	public $ureferrer;
	public $uaffiliateid;

	public $ufname;
	public $ulname;
	public $uphoto;
	public $ugender;
	public $ubirthday;
	public $uincome;
	public $uoccupation;

	public $ubusiness;
	public $uaddress;
	public $usuburb;
	public $uprovince;
	public $ucountry;
	public $upostalcode;
	public $uphone;
	public $uemail;
	public $uurl;

	// affiliates also need to provide banking details in order to get paid
	
	function __construct($fat) {
		$this->fat = $fat;
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
		$this->ListName = $fat->get('ListName');
		$this->FromAddress = $fat->get('FromAddress');
		$this->AdminEmail = $fat->get('AdminEmail');
		$this->AdminName = $fat->get('AdminName');

		$this->uloggedin = false;
		$this->uid = '0';
		$this->uuid = '';
		$this->uidentifier = '';
		$this->uprovider = '';
		$this->uadmin = '0';

		$this->ureferrer = '';
		$this->uaffiliateid = '';

		$this->ufname = '';
		$this->ulname = '';
		$this->uphoto = '';
		$this->ugender = '';
		$this->ubirthday = '';
		$this->uincome = '';
		$this->uoccupation = '';

		$this->ubusiness = '';
		$this->uaddress = '';
		$this->usuburb = '';
		$this->uprovince = '';
		$this->ucountry = '';
		$this->upostalcode = '';
		$this->uphone = '';
		$this->uemail = '';
		$this->uurl = '';

	}

	function __destruct() {
	}

	// Each user gets assigned a unique random md5 hash ID
	// which is used to identify them in lieu of their u_id for security purposes.
	protected function CreateUUID() {
		$uuid = md5(uniqid(mt_rand(),true));
		$result = $this->dbconn->query("select * from users where u_uniqid = \"$uuid\"");
		while ($this->dbconn->affected_rows > 0) {
			$result->close();
			$uuid = md5(uniqid(mt_rand(),true));
			$this->dbconn->query("select * from users where u_uniqid = \"$uuid\"");
		}
		$result->close();
		return $uuid;
	}
	
	public function logout() {
		setcookie('identifier', '', time() - 2419200, '/');
		setcookie('session_token', '', time() - 2419200, '/');
		$this->uloggedin = false;
		$this->uid = '0';
		$this->uuid = '';
		$this->uidentifier = '';
		$this->uprovider = '';
		$this->uadmin = '0';
		$this->ureferrer = '';
		$this->uaffiliateid = '';
		$this->ufname = '';
		$this->ulname = '';
		$this->uphoto = '';
		$this->ugender = '';
		$this->ubirthday = '';
		$this->uincome = '';
		$this->uoccupation = '';
		$this->ubusiness = '';
		$this->uaddress = '';
		$this->usuburb = '';
		$this->uprovince = '';
		$this->ucountry = '';
		$this->upostalcode = '';
		$this->uphone = '';
		$this->uemail = '';
		$this->uurl = '';
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
	public function check_auth_cookies($identifier,$session_token) {
		$sql = "select * from users where u_identifier = \"$identifier\" and u_session_token = \"$session_token\"";
		$result = $this->dbconn->query($sql);
		$count = $result->num_rows;
		$row = $result->fetch_array();
		// uadmin will be set to 1 if the user is an administrator otherwise 0
		if ($count == 1) {
			$this->uloggedin = true;
			$this->uid = $row['u_id'];
			$this->uuid = $row['u_uniqid'];
			$this->uidentifier = $row['u_identifier'];
			$this->uprovider = $row['u_provider'];
			$this->uadmin = $row['u_admin'];
			
			$this->ureferrer = $row['u_referrer'];
			$this->uaffiliateid = $row['u_affiliate_id'];

			$this->ufname = $row['u_fname'];
			$this->ulname = $row['u_lname'];
			$this->uphoto = $row['u_photo'];
			$this->ugender = $row['u_gender'];
			$this->ubirthday = $row['u_birthday'];
			$this->uincome = $row['u_income'];
			$this->uoccupation = $row['u_occupation'];

			$this->ubusiness = $row['u_business'];
			$this->uaddress = $row['u_address'];
			$this->usuburb = $row['u_suburb'];
			$this->uprovince = $row['u_province'];
			$this->ucountry = $row['u_country'];
			$this->upostalcode = $row['u_postalcode'];
			$this->uphone = $row['u_phone'];
			$this->uemail = $row['u_email'];
			$this->uurl = $row['u_url'];

			return true;
		} else {
			$this->logout();
			return false;
		}
	}

	public function CreateEditProfileHTMLform() {
		$html = "";
		if (!$this->uloggedin) {
			$html .= "<p>Please login to edit your profile.</p>";
			return $html;
		}
		$hh = $this->fat->get('htmlhelper');
		$html .= "<form name=\"profileform\" action=\"edit-profile\" method=\"post\">";
		$html .= "<input name=\"uoemail\" type=\"hidden\" value=\"$this->uemail\">";
		$html .= "<fieldset><legend>Basic Information</legend>";
		$html .= "<p><label for=\"uphoto\">Photo url (200 x 150 pixels):</label> <input name=\"uphoto\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->uphoto\"></p>";
		$html .= "<p><label for=\"ufname\">First Name:</label> 	<input name=\"ufname\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->ufname\"></p>";
		$html .= "<p><label for=\"ulname\">Surname:</label> <input name=\"ulname\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->ulname\"></p>";
		$html .= "<p><p><label for=\"ugender\">Gender:</label>	<select name=\"ugender\">" . $hh->CreateGenderHTMLDropDown($this->ugender) . "</select></p>";
		$html .= "<p><label for=\"ubday\">Birthday:</label> <input id=\"bday\" class=\"calendar\" name=\"ubirthday\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->ubirthday\"></p>";
		$html .= "<p><label for=\"uincome\">Monthly income:</label> <select name=\"uincome\">" . $hh->CreateIncomeHTMLDropDown($this->uincome) . "</select></p>";
		$html .= "<p><label for=\"uoccupation\">Occupation:</label> <input name=\"uoccupation\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->uoccupation\"></p>";
		$html .= "</fieldset><fieldset><legend>Contact Information</legend>";
		$html .= "<p><label for=\"ubusiness\">Business name:</label> <input name=\"ubusiness\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->ubusiness\"></p>";
		$html .= "<p><label for=\"uaddress\">Street Address:</label> <input name=\"uaddress\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->uaddress\"></p>";
		$html .= "<p><label for=\"usuburb\">Suburb:</label> <input name=\"usuburb\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->usuburb\"></p>";
		$html .= "<p><label for=\"uprovince\">Province or state:</label> <select name=\"uprovince\">" . $hh->CreateProvinceHTMLDropDown($this->uprovince) . "</select></p>";
		$html .= "<p><label for=\"ucountry\">Country:</label> <select name=\"ucountry\">" . $hh->CreateCountryHTMLDropDown($this->ucountry) . "</select></p>";
		$html .= "<p><label for=\"upostalcode\">Postal Code:</label> <input name=\"upostalcode\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->upostalcode\"></p>";
		$html .= "<p><label for=\"uphone\">Tel or cell:</label> <input name=\"uphone\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->uphone\"></p>";
		$html .= "<p><label for=\"uemail\">Primary email address:</label> <input name=\"uemail\" type=\"text\" size=\"50\" maxlength=\"255\" value=\"$this->uemail\"></p>";
		$html .= "<p><label for=\"uurl\">Web site:</label> <input name=\"uurl\" type=\"text\" size=\"50\" maxlength=\"100\" value=\"$this->uurl\"></p>";
		$html .= "</fieldset><fieldset><legend>Secondary email addresses</legend>";
		$html .= "<p><label for=\"ubemail\">Claim secondary email addresses:</label></p>";
		$html .= "<textarea name=\"ubemail\" rows=\"20\" cols=\"75\"></textarea>";
		$html .= "</fieldset>";
		$html .= "<input class=\"submit\" type=\"submit\" name=\"Save\" value=\"Save\">";
		$html .= "</form>";
		return $html;
	}

	public function DisplayProfileHTML()
	{
		$html = "";
		if (!$this->uloggedin) {
			$html .= "<p>Please login to view your profile.</p>";
			return $html;
		}
		$html .= "<table class=\"table table-bordered\"><thead><tr><th>Photo</th><th colspan=2>Information</th><th colspan=2>Contact</th></tr></thead><tbody><tr><td rowspan=7>";
		if ($this->uphoto <> '') {
			$html .= "<a href=\"$this->uurl\"><img class='profile' align='left' width='200' height='150' src=\"$this->uphoto\"></a>";
		} else {
			$html .= "<a href=\"$this->uurl\"><img class='profile' align='left' src='img/placeholder.jpg'></a>";
		}
		$html .= "</td><td>First name:</td><td>$this->ufname</td><td>Business name:</td><td>$this->ubusiness</td></tr>";
		$html .= "<tr><td>Surname:</td><td>$this->ulname</td><td>Street address:</td><td>$this->uaddress</td></tr>";
		$html .= "<tr><td>Gender:</td><td>$this->ugender</td><td>Suburb:</td><td>$this->usuburb</td></tr>";
		$html .= "<tr><td>Birthday:</td><td>$this->ubirthday</td><td>Province:</td><td>$this->uprovince</td></tr>";
		$html .= "<tr><td>Monthly income:</td><td>$this->uincome</td><td>Country:</td><td>$this->ucountry</td></tr>";
		$html .= "<tr><td>Occupation:</td><td>$this->uoccupation</td><td>Postal code:</td><td>$this->upostalcode</td></tr>";
		$html .= "<tr><td>Primary email:</td><td>$this->uemail</td><td>Phone number:</td><td>$this->uphone</td></tr>";
		$html .= "</tbody></table><h3>Confirmed claimed emails</h3>";
		$html .= $this->CreateEmailsHTMLList();
		$html .= "<p><a href=\"{$this->BaseURL}edit-profile\">Edit Profile</a></p>";
		return $html;
	}

  // extracts the data from the rpx response
  // $ureferrer is the affiliate id of the affiliate who referred this login to the site
  public function process_auth($auth_info) {
    if ($auth_info['stat'] == 'ok') {
      // The user has been successfully authenticated by rpxnow
      // this will create a new user if necessary, populate the user record with data received from rpxnow, generate a session_token, store it and set the cookies to log the user in
      $this->uloggedin = true;
      $profile = $auth_info['profile'];
      
      $uidentifier = $profile['identifier'];
      $uprovider = $profile['providerName'];
      
      // $displayName = $profile['displayName'];
      
      // requires PHP 7 null Coalesce operator
/*
      $uusername = $profile['preferredUsername'] ?? '';
      
      // $utcOffset = $profile['utcOffset'];
      // $verifiedEmail = $profile['verifiedEmail'];
      
      $ugender = $profile['gender'] ?? '';
      $ubirthday = $profile['birthday'] ?? '';
      $uemail = $profile['email'] ?? '';
      $uurl = $profile['url'] ?? '';
      
      if (strpos($uurl,"wordpress")) {
        $uprovider = "WordPress";
      }
      
      $uphone = $profile['phoneNumber'] ?? '';
      $uphoto = $profile['photo'] ?? '';
      
      $name = $profile['name'] ?? '';
      // $name_formatted = $name['formatted'];
      // $middlename = $name['middleName'];
      // $name_prefix = $name['honorificPrefix'];
      // $name_suffix = $name['honorificSuffix'];
      $ufname = $name['givenName'] ?? '';
      $ulname = $name['familyName'] ?? '';

      $address = $profile['address'] ?? '';
      // $address_formatted = $address['formatted'];
      // $locality = $address['locality'];
      $uaddress = $address['streetAddress'] ?? '';
      $uprovince = $address['region'] ?? '';
      $upostalcode = $address['postalCode'] ?? '';
   
      $ucountry = $profile['country'] ?? '';
*/

      // What to store in cookies
      // store the rpx identifier field which identifies the user
      // store a session token which is randomly generated
			
      // generate a random session token
      $session_token = md5(uniqid(mt_rand()));

      // Set the auth cookies
      // Cookies expire in 28 days time
			
      setcookie('identifier', $uidentifier, time() + 2419200, '/');
      setcookie('session_token', $session_token, time() + 2419200, '/');
			
      // Store the auth cookies in the users table

      // We need to check now if the user exists, using identifier as a uniquekey
      // if they do not exist, we create a record in the users table, write the auth_info information to the record and set the login cookies

      // IP address of the person logging in or the IP address of their proxy server
      $ipaddr = $this->fat->get('IP');
      // Their real IP addresss if behind a proxy. Can be spoofed in some cases
      $xfwdfor = getenv('HTTP_X_FORWARDED_FOR');

      // check to see if the user already has a record in the users table
      $sql = "select * from users where u_identifier = \"$uidentifier\"";

      $result = $this->dbconn->query($sql);
      if ($this->dbconn->affected_rows == 0) {
        // create a record for this new identifier
        $uuid = $this->CreateUUID();

        $sql = "insert into users (u_uniqid, u_identifier, u_referrer, u_session_token, u_last_login, u_ip, u_xfwdfor, u_provider, u_fname, u_lname, u_gender, u_birthday, u_email, u_username, u_url, u_phone, u_photo, u_address, u_province, u_country, u_postalcode) values (\"$uuid\", \"$uidentifier\", \"admin\", \"$session_token\", now(), \"$ipaddr\", \"$xfwdfor\", \"$uprovider\", \"$ufname\", \"$ulname\", \"$ugender\", \"$ubirthday\", \"$uemail\", \"$uusername\", \"$uurl\", \"$uphone\", \"$uphoto\", \"$uaddress\", \"$uprovince\", \"$ucountry\", \"$upostalcode\")";

        $success = $this->dbconn->query($sql);
        // new user logged in
        $return_value = '1';
      } else {
        // update the existing record for this identifier
        $sql ="update users set u_session_token = \"$session_token\", u_last_login = now(), u_ip = \"$ipaddr\", u_xfwdfor = \"$xfwdfor\" where u_identifier = \"$uidentifier\"";
        $success = $this->dbconn->query($sql);
        // existing user logged in
        $return_value = '2';
      }
    } else {
      $this->logout();
      // login failed
      $return_value = '0';
    }
    return $return_value;
  }

	// Use this to create a browseable list of claimed emails
	public function CreateEmailsHTMLList() {
		$html = "";
		$sql = "select count(*) from subscribers where s_u_id = \"$this->uid\"";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>There are no confirmed claimed emails.</p>";
			return $html;
		}
		$sql = "select s_uniqid, s_email, s_unsubscribe from subscribers where s_u_id = \"$this->uid\" order by s_id";
		$result = $this->dbconn->query($sql);
		$html .= "<table><thead><tr><th>Email</th><th>Unsubscribed?</th><th>Remove</th></tr></thead><tbody>";
		while($row = $result->fetch_array()) {
			$suid = $row["s_uniqid"];
			$semail = $row["s_email"];
			$slemail = "<a href=\"{$this->BaseURL}subscribe/{$suid}\">$semail</a>";
			$sunsubscribe = ($row["s_unsubscribe"] == "1" ? "Yes" : "<a href=\"{$this->BaseURL}unsubscribe/{$suid}\">unsubscribe</a>");
			$sunclaim = "<a href=\"{$this->BaseURL}unclaim/{$suid}\">unclaim</a>";
			$html .= "<tr><td>$slemail</td><td>$sunsubscribe</td><td>$sunclaim</td></tr>";
		}
		$html .= "</tbody></table><br />";
		return $html;
	}

	// save changes to user profile to mysql database
	public function UpdateProfile($uoemail) {
		$html = "";
		$sql ="update users set u_fname = \"$this->ufname\", u_lname = \"$this->ulname\", u_photo = \"$this->uphoto\", u_gender = \"$this->ugender\", u_birthday = \"$this->ubirthday\", u_income = \"$this->uincome\", u_occupation = \"$this->uoccupation\", u_business = \"$this->ubusiness\", u_address = \"$this->uaddress\",  u_suburb = \"$this->usuburb\", u_province = \"$this->uprovince\", u_country = \"$this->ucountry\", u_postalcode = \"$this->upostalcode\", u_phone = \"$this->uphone\", u_email = \"$this->uemail\", u_url = \"$this->uurl\" where u_id = \"$this->uid\"";
		$success = $this->dbconn->query($sql);
		if ($success) {
			$html .= "<p>$uoemail --> $this->uemail profile info updated.</p>";
			// send profile update notification email to user and admin
			$uname = $this->ufname . " " . $this->ulname;
			$subject = $this->ListName . " user profile updated";
			$mhtml = "<p>Profile for $this->uid on " . $this->ListName . " has been updated.</p>";
			$mtext = "Profile for $this->uid on " . $this->ListName . " has been updated.\n";
			// $mailer = new mailer($this->fat);
			$mailer = $this->fat->get('mailer');
			if ($mailer->OpenSMTP()) {
				$html .= "<p>SMTP server opened</p>";
				$count = $mailer->SendMessage("0","0","Q",$this->FromAddress,$uoemail,$uname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
				$html .= "<p>$count email sent to $uoemail</p>";
				$count = $mailer->SendMessage("0","0","Q",$this->FromAddress,$this->uemail,$uname,$subject,$mhtml,$mtext,true); // the true at the end means this message will be blind copied to the Admin
				$html .= "<p>$count email sent to $this->uemail</p>";
				$mailer->CloseSMTP();
			} else {
				$html .= "<p>SMTP server did not open</p>";
			}
		} else {
			$html .= "<p>$uoemail --> $this->uemail profile info NOT updated due to error.</p>";
		}
		return $html;
	}
}
?>