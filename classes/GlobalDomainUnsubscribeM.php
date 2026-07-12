<?php
/*

Module: GlobalDomainUnsubscribe model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-25

*/

class GlobalDomainUnsubscribeM extends \DB\SQL\Mapper
{
  public $gdbPDO;

  public function __construct(Base $fat) {
    $gdbhost = $fat->get('gdbhost');
    $gdbuser = $fat->get('gdbuser');
    $gdbpass = $fat->get('gdbpass');
    $gdbname = $fat->get('gdbname');

    $this->gdbPDO = new \DB\SQL("mysql:host={$gdbhost};dbname={$gdbname}",$gdbuser,$gdbpass);

    // 2019.06.19 maximum length of domain name is 253
    $this->gdbPDO->exec("CREATE TABLE IF NOT EXISTS `globaldomainunsubscribe` (
    `gdu_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `gdu_api` varchar(40) NOT NULL,
    `gdu_domain` varchar(253) NOT NULL,
    `gdu_dateunsubscribed` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `gdu_domain_name` varchar(253) DEFAULT '',
    `gdu_type` enum('NOTEXIST','SPAM') DEFAULT 'SPAM',
    `gdu_active` tinyint(1) DEFAULT '1',
    PRIMARY KEY (`gdu_id`),
    UNIQUE KEY `gdu_domain_name` (`gdu_domain_name`),
    KEY `gdu_active` (`gdu_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

    parent::__construct($this->gdbPDO,'globaldomainunsubscribe');
  }
}
