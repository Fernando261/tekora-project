<?php

namespace App\Database;

use App\Support\BookingClock;
use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;

final class BookingSQLiteConnector extends SQLiteConnector
{
    public function connect(array $config): PDO
    {
        $pdo = parent::connect($config);
        // The clock belongs to each physical connection, including lazy readers and reconnects.
        BookingClock::install($pdo);

        return $pdo;
    }
}
