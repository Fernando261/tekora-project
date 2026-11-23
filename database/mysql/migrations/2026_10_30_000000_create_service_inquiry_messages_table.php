<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Include the indexes used by the read-marker migration in the same DDL.
        DB::statement(<<<'SQL'
            CREATE TABLE service_inquiry_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_inquiry_id BIGINT UNSIGNED NOT NULL,
                author_user_id BIGINT UNSIGNED NOT NULL,
                idempotency_key CHAR(36) COLLATE utf8mb4_bin NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL,
                CONSTRAINT inquiry_messages_inquiry_fk FOREIGN KEY (service_inquiry_id) REFERENCES service_inquiries (id) ON DELETE RESTRICT,
                CONSTRAINT inquiry_messages_author_fk FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                UNIQUE KEY inquiry_messages_one_submission (service_inquiry_id, author_user_id, idempotency_key),
                UNIQUE KEY inquiry_messages_identity (service_inquiry_id, id),
                KEY inquiry_messages_conversation_order (service_inquiry_id, created_at, id),
                KEY inquiry_messages_received_order (service_inquiry_id, author_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiry_messages');
    }
};
