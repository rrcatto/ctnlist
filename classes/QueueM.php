
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

    // Database schema is managed by Phinx migrations.

    parent::__construct($this->dbPDO,'queue');
  }

  public function queuecount() {
    return $this->count();
  }

}
