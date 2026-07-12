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

    // Database schema is managed by Phinx migrations.

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
    return bin2hex(random_bytes(16));
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