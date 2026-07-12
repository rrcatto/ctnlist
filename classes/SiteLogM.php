<?php
/*

Module: SiteLog model class
Version: 1.0
Author: Richard Catto
Creation Date: 2017-03-02

Description:
models the site_log table

*/

class SiteLogM extends \DB\SQL\Mapper {

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `sitelog` (
    `sl_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `sl_email` varchar(254) DEFAULT NULL,
    `sl_url` varchar(253) DEFAULT NULL,
    `sl_ip` varchar(45) DEFAULT NULL,
    `sl_host` varchar(253) DEFAULT NULL,
    `sl_xfwdfor` varchar(45) DEFAULT NULL,
    `sl_agent` varchar(254) DEFAULT NULL,
    `sl_logged_in` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `sl_logged_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`sl_id`),
    KEY `sitelog_at` (`sl_logged_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

    parent::__construct($dbPDO,'sitelog');
  }
}
