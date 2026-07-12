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

        // Database schema is managed by Phinx migrations.

        parent::__construct($dbPDO,'sendlog');
    }

    public function sendlogcount() {
        return $this->count();
    }

    public function recentmonthcount() {
        // get current month
        $month = date('m');
        $year = date('Y');
        // WHERE sl_type = MESSAGE in the current year and month
        $count = $this->count(array("sl_type = :type and EXTRACT(YEAR FROM sl_datesent) = :year and EXTRACT(MONTH FROM sl_datesent) = :month", ':type' => "MESSAGE", ':year' => $year, ':month' => $month));
        return $count;
    }
}