<?php
/*

Module: Sendlog model class
Version: 5.0.1
Author: Richard Catto
Original Creation Date: 2017-07-12

*/

class SendlogM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'sendlog');
    }

    public function sendlogcount(): int
    {
        return (int) $this->count();
    }

    public function recentmonthcount(): int
    {
        return (int) $this->count([
            'sl_type = :type AND EXTRACT(YEAR FROM sl_datesent) = :year AND EXTRACT(MONTH FROM sl_datesent) = :month',
            ':type' => 'MESSAGE',
            ':year' => date('Y'),
            ':month' => date('m'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function groupedByType(): array
    {
        // Aggregate reporting is one of the few cases where a direct grouped
        // query is clearer than reconstructing the result through Mapper rows.
        return $this->db->exec(
            'SELECT sl_type, COUNT(*) AS sl_total
             FROM sendlog
             GROUP BY sl_type
             ORDER BY sl_type'
        );
    }

    /** @return list<array<string,mixed>> */
    public function groupedByMonthAndType(): array
    {
        return $this->db->exec(
            'SELECT EXTRACT(YEAR FROM sl_datesent)::INTEGER AS sl_year,
                    EXTRACT(MONTH FROM sl_datesent)::INTEGER AS sl_month,
                    sl_type,
                    COUNT(*) AS sl_total
             FROM sendlog
             GROUP BY EXTRACT(YEAR FROM sl_datesent), EXTRACT(MONTH FROM sl_datesent), sl_type
             ORDER BY sl_year DESC, sl_month DESC, sl_type'
        );
    }

    /** @return list<string> */
    public function sendTypes(): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string) $row['sl_type'],
            $this->db->exec('SELECT DISTINCT sl_type FROM sendlog ORDER BY sl_type')
        ));
    }
}
