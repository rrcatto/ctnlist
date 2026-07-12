<?php
/*

Module: GlobalDomainUnsubscribeController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-27

*/

class GlobalDomainUnsubscribeController extends Controller
{
  public $fat;
  public $gdu;

  // $dbconn is a mysqli connection object to an open MySQL database
  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->gdu = new GlobalDomainUnsubscribeM($fat);
  }

  public function readGDU($domain) {
    $this->gdu->load(array('`gdu_domain_name` = :domain', ':domain' => $domain));
    return $this->gdu->valid();
  }

  public function IsUnsubscribed($domain) {
    $count = $this->gdu->count(array("`gdu_domain_name` = :domain and `gdu_active` = '1'", ':domain' => $domain));
    return (bool) ($count == 1);
  }

  public function save($domain_name,$gdutype) {
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
