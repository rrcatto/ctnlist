<?php
/*

Module: Users model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-06-20

*/

class UsersM extends \DB\SQL\Mapper
{

  public function __construct(Base $fat) {
    $dbPDO = $fat->get('dbPDO');

    //2019.06.19 maximum size of email address is 254

    // Database schema is managed by Phinx migrations.

    parent::__construct($dbPDO,'users');
  }

  protected function GetToken() {
    return bin2hex(random_bytes(16));
  }

  // ensure that the generated token is unique. uses recursion
  public function CreateToken($token_field) {
    $token = $this->GetToken();
    $count = $this->count(array("{$token_field} = :token",':token' => $token));
    if ($count > 0) {
      return $this->CreateToken($token_field);
    }
    return $token;
  }

}