<?php

namespace App\Legacy;

use Base;

/*

Module: GlobalDomainUnsubscribeController class
Version: 5.0
Author: Richard Catto
Original Creation Date: 2017-07-27

*/

class GlobalDomainUnsubscribeController extends Controller
{
  public $fat;
  public $gdu;

  function __construct(Base $fat) {
    $this->fat = $fat;
    // Null when SUPPRESSION_PROVIDER=none (development only).
    $this->gdu = $fat->get('gdbPDO') instanceof \DB\SQL ? new GlobalDomainUnsubscribeM($fat) : null;
  }

  public function readGDU($domain) {
    if ($this->gdu === null) {
      return false;
    }
    $this->gdu->load(array('gdu_domain_name = :domain', ':domain' => $domain));
    return $this->gdu->valid();
  }

  public function IsUnsubscribed($domain) {
    if ($this->gdu === null) {
      return false;
    }
    $count = $this->gdu->count(array('gdu_domain_name = :domain and gdu_active = :active', ':domain' => $domain, ':active' => 1));
    return (bool) ($count == 1);
  }

  public function save($domain_name,$gdutype) {
    if ($this->gdu === null) {
      error_log('Global domain unsubscribe not recorded: no suppression provider is configured.');
      return false;
    }
    $valid = $this->readGDU($domain_name);
    if (!$valid) {
      $this->gdu->reset();
      $this->gdu->gdu_domain_name = $domain_name;
    } else {
      $this->gdu->gdu_active = (int) 1;
    }
    $this->gdu->gdu_api = $this->fat->get('API');
    $this->gdu->gdu_domain = $this->fat->get('Domain');
    $this->gdu->gdu_type = $gdutype;
    $this->gdu->save();
    return true;
  }
}