<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmotionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('emotions')->insert([
            [
                'name' => 'Calma',
                'slug' => 'calma',
                'image_path' => 'emotions/calma.png',
                'subtitle' => 'Respira, estás a salvo aquí.',
                'accent' => '#B79CFF',
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ansiedad',
                'slug' => 'ansiedad',
                'image_path' => 'emotions/ansiedad.png',
                'subtitle' => 'Vamos paso a paso.',
                'accent' => '#7CE7D4',
                'order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Amor',
                'slug' => 'amor',
                'image_path' => 'emotions/amor.png',
                'subtitle' => 'El amor es importante en la vida <3.',
                'accent' => '#fc5656',
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Enojo',
                'slug' => 'enojo',
                'image_path' => 'emotions/enojo.png',
                'subtitle' => 'El enojo es normal, pero puedes controlarlo.',
                'accent' => '#fa3314',
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Felicidad',
                'slug' => 'felicidad',
                'image_path' => 'emotions/felicidad.png',
                'subtitle' => 'La felicidad es una elección diaria, que se encuentra en las pequeñas cosas.',
                'accent' => '#ebd834',
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}