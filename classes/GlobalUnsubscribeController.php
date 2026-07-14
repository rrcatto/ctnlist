<?php
/*

Module: GlobalUnsubscribeController class
Version: 5.0
Author: Richard Catto
Original Creation Date: 2017-07-27

*/

class GlobalUnsubscribeController extends Controller
{
  public $fat;
  public $gu;

  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->gu = new GlobalUnsubscribeM($fat);
  }

  public function readGU($email) {
    $this->gu->load(array('gu_email = :email', ':email' => $email));
    return $this->gu->valid();
  }

  public function RetrieveGU($suid) {
    $this->gu->load(array('gu_suid = :suid', ':suid' => $suid));
    return $this->gu->valid();
  }

  public function getEmail($suid) {
    if ($this->RetrieveGU($suid)) {
      return $this->gu->gu_email;
    }
    return '';
  }

  public function IsUnsubscribed($email) {
    $count = $this->gu->count(array('gu_email = :email and gu_active = :active', ':email' => $email, ':active' => 1));
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
      return true;
    } catch (\Throwable $e) {
      error_log('Global unsubscribe save failed: ' . $e->getMessage());
      return false;
    }
  }
}
