<?php
/*

Module: Templates model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-05

*/

class TemplatesM extends \DB\SQL\Mapper {

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    // Database schema is managed by Phinx migrations.

    parent::__construct($dbPDO,'templates');
  }

  public function templatecount() {
      return $this->count();
  }

  public function read($tid) {
    $this->load(array('t_id = :tid',':tid' => $tid));
    return $this->valid();
  }
}