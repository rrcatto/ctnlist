<?php
/*

Module: Archives model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-03

*/

class ArchivesM extends \DB\SQL\Mapper
{

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `archives` (
    `a_id` int(11) NOT NULL auto_increment,
    `a_subject` varchar(200) default NULL,
    `a_html` longtext,
    `a_datecreated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `a_viewed` int(11) NOT NULL default '0',
    PRIMARY KEY (`a_id`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;");

    parent::__construct($dbPDO,'archives');
  }

}
