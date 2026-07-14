<?php
/*

Module: GlobalUnsubscribeM model class
Version: 5.0
Author: Richard Catto
Original Creation Date: 2017-07-25

*/

class GlobalUnsubscribeM extends \DB\SQL\Mapper
{
    public \DB\SQL $gdbPDO;

    public function __construct(Base $fat)
    {
        $gdbPDO = $fat->get('gdbPDO');

        if (!$gdbPDO instanceof \DB\SQL) {
            throw new RuntimeException(
                'The global suppression database connection has not been initialised.'
            );
        }

        $this->gdbPDO = $gdbPDO;

        parent::__construct($this->gdbPDO, 'globalunsubscribe');
    }
}