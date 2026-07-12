<?php
/*

Module: Messages model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-03

*/

class MessagesM extends \DB\SQL\Mapper {

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    // 2019.06.19 change m_from to m_from_name
    // add m_from_address, m_max_send
    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `messages` (
    `m_id` int(11) NOT NULL AUTO_INCREMENT,
    `m_uniqid` varchar(32) DEFAULT NULL,
    `m_t_id` int(11) NOT NULL DEFAULT '0',
    `m_from_name` varchar(100) DEFAULT NULL,
    `m_from_address` varchar(254) DEFAULT NULL,
    `m_subject` varchar(200) DEFAULT NULL,
    `m_priority` int(11) NOT NULL DEFAULT '0',
    `m_listname` varchar(50) NOT NULL DEFAULT 'ALL',
    `m_html` longtext,
    `m_text` longtext,
    `m_datesent` datetime DEFAULT NULL,
    `m_queued` int(11) NOT NULL DEFAULT '0',
    `m_sent` int(11) NOT NULL DEFAULT '0',
    `m_max_send` int(11) NOT NULL DEFAULT '0',
    `m_reads` int(11) NOT NULL DEFAULT '0',
    `m_last_read` datetime DEFAULT NULL,
    `m_likes` int(11) NOT NULL DEFAULT '0',
    `m_last_like` datetime DEFAULT NULL,
    `m_dislikes` int(11) NOT NULL DEFAULT '0',
    `m_last_dislike` datetime DEFAULT NULL,
    `m_bounces` int(11) DEFAULT '0',
    `m_a_id` int(11) NOT NULL DEFAULT '0',
    PRIMARY KEY (`m_id`),
    UNIQUE KEY `m_uniqid` (`m_uniqid`),
    KEY `m_a_id` (`m_a_id`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;");

    parent::__construct($dbPDO,'messages');
  }

  public function msgcount() {
      return $this->count();
  }

  public function read($muid) {
    $this->load(array('m_uniqid = :muid', ':muid' => $muid));
    return $this->valid();
  }

  public function clrmsgstats($muid) {
    $valid = $this->read($muid);
    $this->m_queued = 0;
    $this->m_sent = 0;
    $this->m_reads = 0;
    $this->m_likes = 0;
    $this->m_dislikes = 0;
    $this->m_bounces = 0;
    $this->save();
  }

  public function loadByAid($aid) {
    $this->load(array('m_a_id = :aid', ':aid' => $aid));
    return $this->valid();
  }

  public function loadByMid($mid) {
    $this->load(array('m_id = :mid', ':mid' => $mid));
    return $this->valid();
  }

  protected function GetToken() {
    return hash('md5', uniqid(mt_rand(), true));
  }

  // ensure that the generated token is unique. uses recursion
  public function CreateMUID() {
    $token = $this->GetToken();
    $count = $this->count(array("m_uniqid = :token",':token' => $token));
    if ($count > 0) {
      return $this->CreateMUID();
    }
    return $token;
  }
}
