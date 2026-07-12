<?php
/*

Module: Smlog model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-09

*/

class SmlogM extends \DB\SQL\Mapper {
  public $fat;
  public $subscriber, $message;

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');
    $this->fat = $fat;

    // 2018 change sml_date_queued to sml_date_added
    // 2019.06.19 maximum size of email address is 254

    // Database schema is managed by Phinx migrations.

    parent::__construct($dbPDO,'smlog');
  }

  // redundant
  public function setSubscriber(SubscribersController $subscriber) {
    $this->subscriber = $subscriber;
  }

  // redundant
  public function setMessage(MessagesController $message) {
    $this->message = $message;
  }

  public function deletemsg($muid) {
    $filter = array("sml_muid = :muid", ':muid' => $muid);
    $this->erase($filter);
  }

  public function read($suid,$muid) {
    $sub = new SubscribersM($this->fat);
    $sexists = $sub->read($suid);
    $msg = new MessagesM($this->fat);
    $mexists = $msg->read($muid);
    $filter = array("sml_suid = :suid and sml_muid = :muid", ':suid' => $suid, ':muid' => $muid);
    $this->load($filter);
    if ($this->dry()) {
      if ($mexists) {
        if ($sexists) {
          $this->reset();
          $this->sml_suid = $suid;
          $this->sml_muid = $muid;
          $this->sml_email = $sub->s_email;
          $this->sml_listname = $sub->s_subscribedby;
          $this->save();
          return true;
        }
      }
    } else {
      $this->sml_listname = $sub->s_subscribedby;
      $this->save();
    }
    return $this->valid();
  }
}