<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        $this->call(EmotionSeeder::class);
        
        // No cambiar una cuenta existente al volver a ejecutar el seeder.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['name' => 'Test User', 'email' => 'test@example.com']),
        );
    }

    
}
