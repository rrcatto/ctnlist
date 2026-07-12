<?php
/*

Module: Sendlog model class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-12

*/

class SendlogM extends \DB\SQL\Mapper
{

    public function __construct(Base $fat) {
        $dbPDO = $fat->get('dbPDO');

        // 2019.06.19 maximum size of email address is 254
        $dbPDO->exec("CREATE TABLE IF NOT EXISTS `sendlog` (
        `sl_id` serial,
        `sl_suid` varchar(32) GENERATED ALWAYS AS (md5(`sl_email`)) STORED,
        `sl_muid` varchar(32) NOT NULL DEFAULT '',
        `sl_datesent` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `sl_type` varchar(20) NOT NULL,
        `sl_email` varchar(254) NOT NULL DEFAULT '',
        `sl_listname` varchar(50) NOT NULL DEFAULT '',
        `sl_subject` varchar(200) NOT NULL DEFAULT '',
        PRIMARY KEY (`sl_id`),
        KEY `sl_type` (`sl_type`),
        KEY `sl_email` (`sl_email`),
        KEY `sl_datesent` (`sl_datesent`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

        parent::__construct($dbPDO,'sendlog');
    }

    public function sendlogcount() {
        return $this->count();
    }

    public function recentmonthcount() {
        // get current month
        $month = date('m');
        $year = date('Y');
        // WHERE sl_type = MESSAGE AND MONTH(sl_datesent) = current month
        // $this->sl_year = "YEAR(sl_datesent)";
        // $this->sl_month = "MONTH(sl_datesent)";
        $count = $this->count(array("sl_type = :type and YEAR(sl_datesent) = :year and MONTH(sl_datesent) = :month", ':type' => "MESSAGE", ':year' => $year, ':month' => $month));
        return $count;
    }
}
