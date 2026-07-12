
<?php
/*

Module: Queue model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-07

*/

class QueueM extends \DB\SQL\Mapper {

  protected $dbPDO;

  public function __construct(Base $fat) {
    $this->dbPDO = $fat->get('dbPDO');

    // 2019.06.19 maximum size of email address is 254
    $this->dbPDO->exec("CREATE TABLE IF NOT EXISTS `queue` (
    `q_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `q_muid` varchar(32) NOT NULL,
    `q_subject` varchar(200) DEFAULT NULL,
    `q_suid` varchar(32) NOT NULL,
    `q_email` varchar(254) NOT NULL,
    `q_listname` varchar(50) NOT NULL DEFAULT '',
    `q_date_added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `q_last_interacted` datetime DEFAULT NULL,
    `q_mpriority` int(11) NOT NULL DEFAULT '0',
    `q_spriority` int(11) NOT NULL DEFAULT '0',
    PRIMARY KEY (`q_id`),
    KEY `q_default` (`q_mpriority`,`q_last_interacted`,`q_spriority`,`q_id`),
    KEY `q_priority` (`q_mpriority`,`q_last_interacted`,`q_spriority`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

    parent::__construct($this->dbPDO,'queue');
  }

  public function queuecount() {
    return $this->count();
  }

}
