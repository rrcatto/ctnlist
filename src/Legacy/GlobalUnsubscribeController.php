<?php

namespace App\Legacy;

use Base;

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
    // Null when SUPPRESSION_PROVIDER=none (development only).
    $this->gu = $fat->get('gdbPDO') instanceof \DB\SQL ? new GlobalUnsubscribeM($fat) : null;
  }

  public function readGU($email) {
    if ($this->gu === null) {
      return false;
    }
    $this->gu->load(array('gu_email = :email', ':email' => $email));
    return $this->gu->valid();
  }

  public function IsUnsubscribed($email) {
    if ($this->gu === null) {
      return false;
    }
    $count = $this->gu->count(array('gu_email = :email and gu_active = :active', ':email' => $email, ':active' => 1));
    return (bool) ($count == 1);
  }

  public function save($email,$gutype,$reason) {
    if ($this->gu === null) {
      error_log('Global unsubscribe not recorded: no suppression provider is configured.');
      return false;
    }
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