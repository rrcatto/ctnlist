<?php
/*

Module: GlobalUnsubscribeController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-27

*/

class GlobalUnsubscribeController extends Controller
{
  public $fat;
  public $gu;

  // $dbconn is a mysqli connection object to an open MySQL database
  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->gu = new GlobalUnsubscribeM($fat);
  }

  public function readGU($email) {
    $this->gu->load(array('`gu_email` = :email', ':email' => $email));
    return $this->gu->valid();
  }

  public function RetrieveGU($suid) {
    $this->gu->load(array('`gu_suid` = :suid', ':suid' => $suid));
    return $this->gu->valid();
  }

  public function getEmail($suid) {
    if ($this->RetrieveGU($suid)) {
      return $this->gu->gu_email;
    }
    return '';
  }

  public function IsUnsubscribed($email) {
    $count = $this->gu->count(array("`gu_email` = :email and `gu_active` = '1'", ':email' => $email));
    return (bool) ($count == 1);
  }

  public function save($email,$gutype,$reason) {
    $valid = $this->readGU($email);
    if (!$valid) {
      $this->gu->reset();
      $this->gu->gu_email = $email;
    } else {
      $this->gu->gu_active = (int) 1;
    }
    $this->gu->gu_api = $this->fat->get('API');
    $this->gu->gu_domain = $this->fat->get('Domain');
    $this->gu->gu_type = $gutype;
    $this->gu->gu_reason = $reason;
    try {
      $this->gu->save();
    } catch (PDOException $e) {
      // $this->gu->erase();
      // $this->gu->skip();
    }
    return true;
  }
}
