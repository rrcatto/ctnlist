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

    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `templates` (
    `t_id` int(11) NOT NULL auto_increment,
    `t_name` varchar(100) default NULL,
    `t_html` longtext,
    `t_text` longtext,
    PRIMARY KEY (`t_id`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;");

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
