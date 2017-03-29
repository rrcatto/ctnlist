<?php
/*

Module: customers class
Author: Richard Catto
Creation Date: 2011-03-01
Updated: 2014-10-02 integration with fat free framework

*/

class customers {

	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;
	protected $AdminEmail;

	public $camount;
	public $cfname;
	public $clname;
	public $cemail;
	public $ccell;
	public $clandline;
	public $cstreet;
	public $cprovince;
	public $curl;
	public $ccompany;
	public $cvat;
	public $cdelegates;
	public $cinstructions;
	public $cvenue;
	public $cdate;

	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
		$this->AdminEmail = $fat->get('AdminEmail');
	}

	function __destruct() {
	}

    // Default save
	public function defaultSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        fwrite($fp1, $bdate . "\n");
        $ctmessage = "";
        $chmessage = "";
		fwrite($fp1, "Full names: {$this->cfname}\n");
        $ctmessage .= "Full names: {$this->cfname}\n";
        $chmessage .= "<p>Full names: {$this->cfname}";
        fwrite($fp1, "Email: {$this->cemail}\n");
        $ctmessage .= "Email: {$this->cemail}\n";
        $chmessage .= "<br />Email: {$this->cemail}";
        fwrite($fp1, "Contact number: {$this->ccell}\n");
        $ctmessage .= "Contact number: {$this->ccell}\n";
        $chmessage .= "<br />Contact number: {$this->ccell}";
        fwrite($fp1, "Company: {$this->ccompany}\n");
        $ctmessage .= "Company: {$this->ccompany}\n";
        $chmessage .= "<br />Company: {$this->ccompany}";
        if ($this->fat->exists('POST.of_amount')) {
            $of_amount = $this->fat->get('POST.of_amount');
            if (is_array($of_amount)) {
                $camount = print_r($of_amount, true);
            } else {
                $camount = $of_amount;
            }
            $this->camount = $camount;
            fwrite($fp1, "Amount: {$this->camount}\n");
            $ctmessage .= "Amount: {$this->camount}\n";
            $chmessage .= "<br />Amount: {$this->camount}";
        }
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");
        $ctmessage .= "Instructions: {$this->cinstructions}\n";
        $chmessage .= "<br />Instructions: {$this->cinstructions}</p>";
    
		$ctoname = $this->cfname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
        
        fwrite($fp1, "\n");
		fclose($fp1);
    
		return $html;
	}

    // smartpoynt save
    public function spSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        fwrite($fp1, $bdate . "\n");
        fwrite($fp1, "Promo Price: {$this->camount}\n");
		fwrite($fp1, "Name: {$this->cfname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Cell: {$this->ccell}\n");
        fwrite($fp1, "Delivery: {$this->cinstructions}\n");

        /*
        $success = $this->PDOsave();
		if ($success) {
			$html .= "<p>Your Application has been successfully captured!</p>";
            fwrite($fp1, "DB-OK\n");
		} else {
			$html .= "<p>There was a technical problem capturing application. Please inform our administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "DB-FAIL\n");
		}
        */
        
		$ctmessage = "Promo Price: {$this->camount}\nName: {$this->cfname}\nEmail: {$this->cemail}\nCell: {$this->ccell}\nDelivery: {$this->cinstructions}\n";
        
		$chmessage = "<p>Promo Price: {$this->camount}<br />Name: {$this->cfname}<br />Email: {$this->cemail}<br />Cell: {$this->ccell}<br />Delivery: {$this->cinstructions}</p>";

		$ctoname = $this->cfname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your application! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was an email transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
        
        fwrite($fp1, "\n");
        fclose($fp1);

		return $html;
    }
    
    // gary's save
	public function mmSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
        // $fp1 = fopen("bookings.txt", "a"); 
		$bdate = date("Y-m-d H:i:s");

        if (is_array($this->camount)) {
            $camount = print_r($this->camount, true);
        } else {
            $camount = $this->camount;
        }
        $this->camount = $camount;

        fwrite($fp1, $bdate . "\n");
		// fwrite($fp1, "Firstname: {$this->cfname}\n");
        // fwrite($fp1, "Lastname: {$this->clname}\n");
		fwrite($fp1, "Full names: {$this->cfname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Contact number: {$this->ccell}\n");
        // fwrite($fp1, "Landline: {$this->clandline}\n");
        fwrite($fp1, "Street: {$this->cstreet}\n");
        fwrite($fp1, "Province: {$this->cprovince}\n");
        // fwrite($fp1, "Web site: {$this->curl}\n");
        fwrite($fp1, "Company: {$this->ccompany}\n");
        fwrite($fp1, "VAT #: {$this->cvat}\n");
        fwrite($fp1, "Venues: {$this->cvenue}\n");
        fwrite($fp1, "Dates: {$this->cdate}\n");
        fwrite($fp1, "Amount: {$this->camount}\n");
        fwrite($fp1, "All delegates: {$this->cdelegates}\n");
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");

        // $success = $this->save();
        /*
        $success = $this->PDOsave();
		if ($success) {
			$html .= "<p>Form data successfully captured</p>";
            fwrite($fp1, "DB-OK\n");
		} else {
			$html .= "<p>Error capturing form data. Please contact the administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "DB-FAIL\n");
		}
        */
        
		// $ctmessage = "Firstname: {$this->cfname}\nLastname: {$this->clname}\nEmail: {$this->cemail}\nCell: {$this->ccell}\nLandline: {$this->clandline}\nStreet: {$this->cstreet}\nProvince: {$this->cprovince}\nWeb site: {$this->curl}\nCompany: {$this->ccompany}\nVAT #: {$this->cvat}\nVenues: {$this->cvenue}\nDates: {$this->cdate}\nAmount: {$this->camount}\nAll delegates: {$this->cdelegates}\nInstructions: {$this->cinstructions}\n";

      $ctmessage = "Full names: {$this->cfname}\nEmail: {$this->cemail}\nContact number: {$this->ccell}\nStreet: {$this->cstreet}\nProvince: {$this->cprovince}\nCompany: {$this->ccompany}\nVAT #: {$this->cvat}\nVenues: {$this->cvenue}\nDates: {$this->cdate}\nAmount: {$this->camount}\nAll delegates: {$this->cdelegates}\nInstructions: {$this->cinstructions}\n";

		// $chmessage = "<p>Firstname: {$this->cfname}<br />Lastname: {$this->clname}<br />Email: {$this->cemail}<br />Cell: {$this->ccell}<br />Landline: {$this->clandline}<br />Street: {$this->cstreet}<br />Province: {$this->cprovince}<br />Web site: {$this->curl}<br />Company: {$this->ccompany}<br />Vat #: {$this->cvat}<br />Venues {$this->cvenue}<br />Dates: {$this->cdate}<br />Amount: <pre>{$this->camount}</pre><br />All delegates: {$this->cdelegates}<br />Instructions: {$this->cinstructions}</p>";

        $chmessage = "<p>Full names: {$this->cfname}<br />Email: {$this->cemail}<br />Contact number: {$this->ccell}<br />Street: {$this->cstreet}<br />Province: {$this->cprovince}<br />Company: {$this->ccompany}<br />Vat #: {$this->cvat}<br />Venues {$this->cvenue}<br />Dates: {$this->cdate}<br />Amount: <pre>{$this->camount}</pre><br />All delegates: {$this->cdelegates}<br />Instructions: {$this->cinstructions}</p>";

		// $ctoname = $this->cfname . " " . $this->clname;
		$ctoname = $this->cfname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
		$html .= "<p>You may make payment now via EFT into the following account. Please email proof of payment to Gary.</p>";
		$html .= "<p>Nedbank Bank<br />FINSOLVE<br />Account number: 1206 038 233<br />Branch Code: 12-06-21</p>";
        
        fwrite($fp1, "\n");
		fclose($fp1);

		return $html;
	}

      // nico-e's save
	public function neSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        if (is_array($this->camount)) {
            $camount = print_r($this->camount, true);
        } else {
            $camount = $this->camount;
        }
        $this->camount = $camount;

        fwrite($fp1, $bdate . "\n");
        fwrite($fp1, "Amount: {$this->camount}\n");
		fwrite($fp1, "Full names: {$this->cfname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Contact number: {$this->ccell}\n");
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");

        $ctmessage = "Amount: {$this->camount}\nFull names: {$this->cfname}\nEmail: {$this->cemail}\nContact number: {$this->ccell}\nInstructions: {$this->cinstructions}\n";

        $chmessage = "<p>Amount: <pre>{$this->camount}</pre><br />Full names: {$this->cfname}<br />Email: {$this->cemail}<br />Contact number: {$this->ccell}<br />Instructions: {$this->cinstructions}</p>";

		$ctoname = $this->cfname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
		$html .= "<p>You may make payment of R700 now via EFT into the following account. Please email proof of payment to leon.a@nico-e.com</p>";
		$html .= "<p>Standard Bank<br />NICO-E (Pty) Ltd<br />Account number: 27-239-0917<br />Branch Code: 03-30-12</p>";
        
        fwrite($fp1, "\n");
		fclose($fp1);

		return $html;
	}

    // Magnetic Calendars save
	public function magCalSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        fwrite($fp1, $bdate . "\n");
		fwrite($fp1, "Full names: {$this->cfname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Contact number: {$this->ccell}\n");
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");

        $ctmessage = "Full names: {$this->cfname}\nEmail: {$this->cemail}\nContact number: {$this->ccell}\nInstructions: {$this->cinstructions}\n";

        $chmessage = "<p>Full names: {$this->cfname}<br />Email: {$this->cemail}<br />Contact number: {$this->ccell}<br />Instructions: {$this->cinstructions}</p>";

		$ctoname = $this->cfname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
        
        fwrite($fp1, "\n");
		fclose($fp1);

		return $html;
	}

    // coastalprop's save
  	public function cpSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        fwrite($fp1, $bdate . "\n");
		fwrite($fp1, "Full name: {$this->cfname}\n");
		// fwrite($fp1, "Firstname: {$this->cfname}\n");
        // fwrite($fp1, "Lastname: {$this->clname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Contact number: {$this->ccell}\n");
        // fwrite($fp1, "Cell: {$this->ccell}\n");
        // fwrite($fp1, "Landline: {$this->clandline}\n");
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");

		$ctmessage = "Full name: {$this->cfname}\nEmail: {$this->cemail}\nContact number: {$this->ccell}\n\nInstructions: {$this->cinstructions}\n";
		// $ctmessage = "Firstname: {$this->cfname}\nLastname: {$this->clname}\nEmail: {$this->cemail}\nCell: {$this->ccell}\nLandline: {$this->clandline}\nInstructions: {$this->cinstructions}\n";
        
		$chmessage = "<p>Full name: {$this->cfname}<br />Email: {$this->cemail}<br />Contact number: {$this->ccell}<br />Instructions: {$this->cinstructions}</p>";
		// $chmessage = "<p>Firstname: {$this->cfname}<br />Lastname: {$this->clname}<br />Email: {$this->cemail}<br />Cell: {$this->ccell}<br />Landline: {$this->clandline}<br />Instructions: {$this->cinstructions}</p>";

		$ctoname = $this->cfname;
		// $ctoname = $this->cfname . " " . $this->clname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
        
        fwrite($fp1, "\n");
		fclose($fp1);

		return $html;
	}

    // kumalogreen's save - not needed
	public function kgSave() {
		$html = "";
		$mailer = $this->fat->get('mailer');

        $logfilename = $this->fat->get('OrderLogFilename');
        $fp1 = fopen($logfilename, "a"); 
		$bdate = date("Y-m-d H:i:s");

        if (is_array($this->camount)) {
            $camount = print_r($this->camount, true);
        } else {
            $camount = $this->camount;
        }
        $this->camount = $camount;

        fwrite($fp1, $bdate . "\n");
		fwrite($fp1, "Firstname: {$this->cfname}\n");
        fwrite($fp1, "Lastname: {$this->clname}\n");
        fwrite($fp1, "Email: {$this->cemail}\n");
        fwrite($fp1, "Cell: {$this->ccell}\n");
        fwrite($fp1, "Landline: {$this->clandline}\n");
        fwrite($fp1, "Street: {$this->cstreet}\n");
        fwrite($fp1, "Province: {$this->cprovince}\n");
        fwrite($fp1, "Web site: {$this->curl}\n");
        fwrite($fp1, "Company: {$this->ccompany}\n");
        fwrite($fp1, "VAT #: {$this->cvat}\n");
        fwrite($fp1, "Venues: {$this->cvenue}\n");
        fwrite($fp1, "Dates: {$this->cdate}\n");
        fwrite($fp1, "Amount: {$this->camount}\n");
        fwrite($fp1, "Additional delegates: {$this->cdelegates}\n");
        fwrite($fp1, "Instructions: {$this->cinstructions}\n");

        /*
        $success = $this->PDOsave();
		if ($success) {
			$html .= "<p>Form data successfully captured</p>";
            fwrite($fp1, "DB-OK\n");
		} else {
			$html .= "<p>Error capturing form data. Please contact the administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "DB-FAIL\n");
		}
        */
        
		$ctmessage = "Firstname: {$this->cfname}\nLastname: {$this->clname}\nEmail: {$this->cemail}\nCell: {$this->ccell}\nLandline: {$this->clandline}\nStreet: {$this->cstreet}\nProvince: {$this->cprovince}\nWeb site: {$this->curl}\nCompany: {$this->ccompany}\nVAT #: {$this->cvat}\nVenues: {$this->cvenue}\nDates: {$this->cdate}\nAmount: {$this->camount}\nAdditional delegates: {$this->cdelegates}\nInstructions: {$this->cinstructions}\n";
        
		$chmessage = "<p>Firstname: {$this->cfname}<br />Lastname: {$this->clname}<br />Email: {$this->cemail}<br />Cell: {$this->ccell}<br />Landline: {$this->clandline}<br />Street: {$this->cstreet}<br />Province: {$this->cprovince}<br />Web site: {$this->curl}<br />Company: {$this->ccompany}<br />Vat #: {$this->cvat}<br />Venues {$this->cvenue}<br />Dates: {$this->cdate}<br />Amount: <pre>{$this->camount}</pre><br />Additional delegates: {$this->cdelegates}<br />Instructions: {$this->cinstructions}</p>";

		$ctoname = $this->cfname." ".$this->clname;

		$mailer->OpenSMTP();
		$num_sent = $mailer->SendOrderMessage($this->cemail,$ctoname,$chmessage,$ctmessage);
		$mailer->CloseSMTP();

		// Sends to the person who submitted the order form and me
		if ($num_sent >= 2) {
			$html .= "<p>Thank you for your submission! Email successfully sent. You will be contacted shortly.</p>";
            fwrite($fp1, "EMAIL-OK\n");
		} else {
			$html .= "<p>numsent: {$num_sent}<br/>There was a transmission problem. Please try re-submitting or contact the Administrator at {$this->AdminEmail}.</p>";
            fwrite($fp1, "EMAIL-FAIL\n");
		}
        
        fwrite($fp1, "\n");
		fclose($fp1);

		return $html;
	}

	public function PDOsave() {
        $dbhost = $this->fat->get('dbhost');
        $dbuser = $this->fat->get('dbuser');
        $dbpass = $this->fat->get('dbpass');
        $dbname = $this->fat->get('dbname');
      
        $db = new \DB\SQL("mysql:host={$dbhost};dbname={$dbname}",$dbuser,$dbpass);

        $camount = json_encode($this->camount);
        $IP = json_encode($this->fat->get('IP'));
        $XFOR = json_encode(getenv('HTTP_X_FORWARDED_FOR'));
        // $dbnow = json_encode(date("Y-m-d H:i:s"));
        // $dbnow = $this->fat->format('{0,date}',time());
        
        $formargs = array(
          ':c_firstname' => json_encode($this->cfname),
          ':c_surname' => json_encode($this->clname),
          ':c_email' => json_encode($this->cemail),
          ':c_cell' => json_encode($this->ccell),
          ':c_landline' => json_encode($this->clandline),
          ':c_street' => json_encode($this->cstreet),
          ':c_province' => json_encode($this->cprovince),
          ':c_url' => json_encode($this->curl),
          ':c_company' => json_encode($this->ccompany),
          ':c_amount' => $camount,
          ':c_instructions' => json_encode($this->cinstructions),
          ':c_ip' => $IP,
          ':c_xfor' => $XFOR
          // ':c_when' => $dbnow
        );
        // echo "<pre>" . print_r($formargs,true) . "</pre>";
      
        // $success = $db->exec('INSERT INTO customers (c_firstname,c_surname,c_email,c_cell,c_landline,c_street,c_province,c_url,c_company,c_amount,c_instructions,c_ip,c_xfwdfor,c_when) VALUES (:c_firstname,:c_surname,:c_email,:c_cell,:c_landline,:c_street,:c_province,:c_url,:c_company,:c_amount,:c_instructions,:c_ip,:c_xfor,:c_when)',$formargs);
        $success = $db->exec('INSERT INTO customers (c_firstname,c_surname,c_email,c_cell,c_landline,c_street,c_province,c_url,c_company,c_amount,c_instructions,c_ip,c_xfwdfor,c_when) VALUES (:c_firstname,:c_surname,:c_email,:c_cell,:c_landline,:c_street,:c_province,:c_url,:c_company,:c_amount,:c_instructions,:c_ip,:c_xfor,now())',$formargs);

		return $success;
	}

  public function save() {
		$this->cfname = $this->dbconn->real_escape_string($this->cfname);
		$this->clname = $this->dbconn->real_escape_string($this->clname);
		$this->cemail = $this->dbconn->real_escape_string($this->cemail);
		$this->ccell = $this->dbconn->real_escape_string($this->ccell);
		$this->clandline = $this->dbconn->real_escape_string($this->clandline);
		$this->cstreet = $this->dbconn->real_escape_string($this->cstreet);
		$this->cprovince = $this->dbconn->real_escape_string($this->cprovince);
		$this->curl = $this->dbconn->real_escape_string($this->curl);
		$this->ccompany = $this->dbconn->real_escape_string($this->ccompany);
		$this->camount = $this->dbconn->real_escape_string($this->camount);
		$this->cinstructions = $this->dbconn->real_escape_string($this->cinstructions);

		// IP address of the person viewing the order form
		$ipaddr = $this->fat->get('IP');
		// Their real IP addresss if behind a proxy. Can be spoofed in some cases
		$xfwdfor = getenv('HTTP_X_FORWARDED_FOR');

		$sql = "insert into customers (c_firstname,c_surname,c_email,c_cell,c_landline,c_street,c_province,c_url,c_company,c_amount,c_instructions,c_ip,c_xfwdfor,c_when) values (\"{$this->cfname}\",\"{$this->clname}\",\"{$this->cemail}\",\"{$this->ccell}\",\"{$this->clandline}\",\"{$this->cstreet}\",\"{$this->cprovince}\",\"{$this->curl}\",\"{$this->ccompany}\",\"{$this->camount}\",\"{$this->cinstructions}\",\"{$ipaddr}\",\"{$xfwdfor}\",now())";
		return $this->dbconn->query($sql);
	}
}
?>