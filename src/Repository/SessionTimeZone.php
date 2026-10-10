<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Runs each database session in PHP's timezone (APP_TIMEZONE, which the
 * Kernel sets). PostgreSQL itself runs in UTC, so without this the columns
 * filled by DEFAULT CURRENT_TIMESTAMP (consent events, memberships, the
 * queue, smlog, roles) would be two hours behind the times PHP writes.
 */
#[AsMiddleware]
final class SessionTimeZone implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $connection->exec('SET TIME ZONE ' . $connection->quote(date_default_timezone_get()));
                return $connection;
            }
        };
    }
}
