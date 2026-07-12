<?php
/*

Module: customers class
Author: Richard Catto
Creation Date: 2011-03-01
Updated: 2014-10-02 integration with fat free framework

*/

class customers {
  protected $fat;
  protected $AdminEmail;

  public $mailer;

  function __construct(Base $fat, mailer $mailer) {
    $this->fat = $fat;
    $this->AdminEmail = $fat->get('AdminEmail');
    $this->mailer = $mailer;
  }

  public function save($formvars) {
    $html = "";

    $logfilename = $this->fat->get('OrderLogFilename');
    $fp1 = fopen($logfilename, "a");
    $bdate = date("Y-m-d H:i:s");
    fwrite($fp1, "~ ~ ~\n\n{$bdate}\n");

    $ctmessage = "";
    $chmessage = "";

    foreach ($formvars as $formkey => $formvalue) {
      fwrite($fp1, "{$formkey}:\n{$formvalue}\n\n");
      $ctmessage .= "{$formkey}:\n{$formvalue}\n\n";
      $chmessage .= "<p><b>{$formkey}:</b><br />{$formvalue}</p>";
    }

    // $chmessage .= "";

    $ctoname = $formvars['name'];
    $cemail = $formvars['email'];

    $this->mailer->OpenSMTP();
    $num_sent = $this->mailer->SendOrderMessage($cemail,$ctoname,$chmessage,$ctmessage);
    $this->mailer->CloseSMTP();
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
}
