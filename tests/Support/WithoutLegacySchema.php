<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

trait WithoutLegacySchema
{
    private function removeLegacySchema(): void
    {
        // TestCase has already enforced the in-memory connection before migrating.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::withoutForeignKeyConstraints(function () {
            foreach (['likes', 'comments', 'posts', 'reminders', 'emotions', 'citas',
                'profesionista_bloqueos', 'profesionista_disponibilidades', 'profesionistas'] as $table) {
                Schema::drop($table);
                $this->assertFalse(Schema::hasTable($table));
            }
        });
    }
}
