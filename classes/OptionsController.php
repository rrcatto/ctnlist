<?php
/*

Module: optiona class
Author: Richard Catto
Creation Date: 2016-07-20

*/

class OptionsController extends Controller {

  public $option;

	// $dbconn is a mysqli connection object to an open MySQL database
  function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');
    $this->option = new OptionsM($fat);
  }

  function __destruct() {
  }

  public function SetOption($key,$val) {
    $this->option->load(array('o_key = :key', ':key' => $key));
    $this->option->o_key = $key;
    $this->option->o_value = $val;
    $this->option->save();
    return true;
  }

  public function GetOption($key) {
    $this->option->load(array('o_key = :key', ':key' => $key));
    $val = $this->option->o_value;
    return $val;
  }

  public function ExistsOption($key) {
    $count = (bool) ($this->option->count(array('o_key = :key', ':key' => $key)) <> 0);
    return $count;
  }
}
