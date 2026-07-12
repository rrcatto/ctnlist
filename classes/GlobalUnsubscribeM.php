<?php
/*

Module: GlobalUnsubscribeM model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-25

*/

class GlobalUnsubscribeM extends \DB\SQL\Mapper
{
  public $gdbPDO;

  public function __construct(Base $fat) {
    $gdbhost = $fat->get('gdbhost');
    $gdbuser = $fat->get('gdbuser');
    $gdbpass = $fat->get('gdbpass');
    $gdbname = $fat->get('gdbname');

    $this->gdbPDO = new \DB\SQL("mysql:host={$gdbhost};dbname={$gdbname}",$gdbuser,$gdbpass);

    // 2019.06.19 maximum size of email address is 254
    // maximum size of user name is 64
    // maximum size of domain name is 253
    $this->gdbPDO->exec("CREATE TABLE IF NOT EXISTS `globalunsubscribe` (
    `gu_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `gu_suid` varchar(32) GENERATED ALWAYS AS (md5(`gu_email`)) STORED,
    `gu_api` varchar(40) NOT NULL,
    `gu_domain` varchar(253) NOT NULL,
    `gu_dateunsubscribed` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `gu_email` varchar(254) NOT NULL,
    `gu_email_user` varchar(64) GENERATED ALWAYS AS (SUBSTRING_INDEX(`gu_email`,'@',1)) STORED,
    `gu_email_domain` varchar(253) GENERATED ALWAYS AS  (SUBSTRING_INDEX(`gu_email`,'@',-1)) STORED,
    `gu_type` enum('ADMIN','BOUNCE','BOUNCE-ADMIN','INVALID','SPAM','SPAM-ADMIN','USER') NOT NULL,
    `gu_reason` varchar(255) DEFAULT NULL,
    `gu_active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`gu_id`),
    UNIQUE KEY `gu_email` (`gu_email`),
    UNIQUE KEY `gu_suid` (`gu_suid`),
    KEY `gu_email_user` (`gu_email_user`,`gu_email_domain`),
    KEY `gu_email_domain` (`gu_email_domain`),
    KEY `gu_reason` (`gu_reason`),
    KEY `gu_type` (`gu_type`),
    KEY `gu_active` (`gu_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

    parent::__construct($this->gdbPDO,'globalunsubscribe');
  }
}
