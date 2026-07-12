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
    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `smlog` (
    `sml_id` serial,
    `sml_suid` varchar(32) NOT NULL,
    `sml_email` varchar(254) NOT NULL,
    `sml_listname` varchar(50) DEFAULT '',
    `sml_muid` varchar(32) NOT NULL,
    `sml_date_added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sml_date_sent` datetime DEFAULT NULL,
    `sml_reads` int(11) NOT NULL DEFAULT '0',
    `sml_last_read` datetime DEFAULT NULL,
    `sml_likes` int(11) NOT NULL DEFAULT '0',
    `sml_last_like` datetime DEFAULT NULL,
    `sml_dislikes` int(11) NOT NULL DEFAULT '0',
    `sml_last_dislike` datetime DEFAULT NULL,
    `sml_updates` int(11) NOT NULL DEFAULT '0',
    `sml_last_update` datetime DEFAULT NULL,
    `sml_confirms` int(11) NOT NULL DEFAULT '0',
    `sml_confirmed_at` datetime DEFAULT NULL,
    `sml_forwards` int(11) NOT NULL DEFAULT '0',
    `sml_last_forwarded` datetime DEFAULT NULL,
    `sml_bookings` int(11) NOT NULL DEFAULT '0',
    `sml_last_booking` datetime DEFAULT NULL,
    `sml_subscribe` tinyint(1) NOT NULL DEFAULT '0',
    `sml_subscribed_at` datetime DEFAULT NULL,
    `sml_unsubscribe` tinyint(1) NOT NULL DEFAULT '0',
    `sml_unsubscribed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`sml_id`),
    UNIQUE KEY `sml_default` (`sml_suid`,`sml_muid`),
    KEY `sml_suid` (`sml_suid`),
    KEY `sml_muid` (`sml_muid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

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
    $filter = array("`sml_muid` = :muid", ':muid' => $muid);
    $this->erase($filter);
  }

  public function read($suid,$muid) {
    $sub = new SubscribersM($this->fat);
    $sexists = $sub->read($suid);
    $msg = new MessagesM($this->fat);
    $mexists = $msg->read($muid);
    $filter = array("`sml_suid` = :suid and `sml_muid` = :muid", ':suid' => $suid, ':muid' => $muid);
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
