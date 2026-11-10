<?php

namespace App\Services;

use Illuminate\Database\Connection;

/** Temporary integrity bridge, not a dependency on the old domain models. */
class LegacyPhotoReferences
{
    public function mayReference(Connection $connection, string $pattern): bool
    {
        // Inspect the writer's schema, even when the connection has read replicas.
        // No cached absence: partially retired schemas may change between requests.
        $schemaConnection = clone $connection;
        $schemaConnection->setReadPdo($connection->getPdo());
        $schema = $schemaConnection->getSchemaBuilder();

        foreach (['posts' => 'media_path', 'comments' => 'media_path', 'emotions' => 'image_path'] as $table => $column) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            if ($connection->table($table)->useWritePdo()
                ->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern])->exists()) {
                return true;
            }
        }

        // Errors deliberately propagate: the caller must retain the file whenever
        // absence cannot be established, including a table disappearing mid-read.
        return false;
    }
}
