<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The same migration identity as SQLite, selected explicitly by the MySQL lab.
        // One DDL statement keeps the table and its uniqueness constraint together.
        DB::statement(<<<'SQL'
            CREATE TABLE service_inquiries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_id BIGINT UNSIGNED NOT NULL,
                client_user_id BIGINT UNSIGNED NOT NULL,
                service_title VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                status VARCHAR(9) COLLATE utf8mb4_0900_bin NOT NULL DEFAULT 'pending',
                accepted_at TIMESTAMP NULL,
                rejected_at TIMESTAMP NULL,
                cancelled_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                active_slot TINYINT GENERATED ALWAYS AS
                    (CASE WHEN status IN ('pending', 'accepted') THEN 1 ELSE NULL END) STORED INVISIBLE,
                CONSTRAINT inquiry_status_valid CHECK (status IN ('pending','accepted','rejected','cancelled','completed')),
                CONSTRAINT service_inquiries_service_id_foreign FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                CONSTRAINT service_inquiries_client_user_id_foreign FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                UNIQUE KEY service_inquiries_one_active (service_id, client_user_id, active_slot),
                KEY service_inquiries_client_user_id_status_id_index (client_user_id, status, id),
                KEY service_inquiries_service_id_status_id_index (service_id, status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiries');
    }
};
