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
    $dbPDO->exec("CREATE TABLE IF NOT EXISTS `users` (
    `u_id` int(11) NOT NULL AUTO_INCREMENT,
    `u_uniqid` varchar(32) NOT NULL,
    `u_identifier` varchar(100) NOT NULL,
    `u_session_token` varchar(100) DEFAULT NULL,
    `u_suid` varchar(32) GENERATED ALWAYS AS (md5(`u_email`)) STORED,
    `u_email` varchar(254) DEFAULT NULL,
    `u_username` varchar(100) DEFAULT NULL,
    `u_password` varchar(100) DEFAULT NULL,
    `u_last_login` datetime DEFAULT NULL,
    `u_ip` varchar(100) DEFAULT NULL,
    `u_xfwdfor` varchar(100) DEFAULT NULL,
    `u_admin` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `u_provider` varchar(100) DEFAULT NULL,
    `u_fname` varchar(100) DEFAULT NULL,
    `u_lname` varchar(100) DEFAULT NULL,
    `u_gender` varchar(30) DEFAULT NULL,
    `u_birthday` varchar(10) DEFAULT NULL,
    `u_url` varchar(253) DEFAULT NULL,
    `u_phone` varchar(20) DEFAULT NULL,
    `u_photo` varchar(253) DEFAULT NULL,
    `u_business` varchar(100) DEFAULT NULL,
    `u_province` varchar(100) DEFAULT '',
    `u_country` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`u_id`),
    UNIQUE KEY `u_uniqid` (`u_uniqid`),
    UNIQUE KEY `u_identifier` (`u_identifier`),
    UNIQUE KEY `u_session_token` (`u_session_token`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=latin1;");

    parent::__construct($dbPDO,'users');
  }

  protected function GetToken() {
    return hash('md5', uniqid(mt_rand(), true));
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
