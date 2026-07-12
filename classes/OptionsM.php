<?php
/*

Module: Options model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-06-30

Description:
models the Options table

*/

class OptionsM extends \DB\SQL\Mapper
{

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `options` (
    `o_id` int(11) NOT NULL auto_increment,
    `o_key` varchar(100) NOT NULL,
    `o_value` varchar(255) default NULL,
    PRIMARY KEY  (`o_id`),
    UNIQUE KEY `o_key` (`o_key`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;");

    parent::__construct($dbPDO,'options');
  }

}
