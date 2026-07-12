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

    // Database schema is managed by Phinx migrations.

    parent::__construct($dbPDO,'options');
  }

}