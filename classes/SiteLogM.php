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

    // Database schema is managed by Phinx migrations.

    parent::__construct($dbPDO,'sitelog');
  }
}