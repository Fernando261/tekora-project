<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL limits identifiers to 64 characters. Keep the composite FK intact.
        DB::statement(<<<'SQL'
            CREATE TABLE service_inquiry_reads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_inquiry_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                last_read_message_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT inquiry_reads_inquiry_fk FOREIGN KEY (service_inquiry_id) REFERENCES service_inquiries (id) ON DELETE RESTRICT,
                CONSTRAINT inquiry_reads_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT inquiry_reads_message_fk FOREIGN KEY (service_inquiry_id, last_read_message_id)
                    REFERENCES service_inquiry_messages (service_inquiry_id, id) ON DELETE RESTRICT,
                UNIQUE KEY inquiry_reads_one_participant (service_inquiry_id, user_id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiry_reads');
    }
};
