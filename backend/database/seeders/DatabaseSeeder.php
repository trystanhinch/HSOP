<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SettingsSeeder::class);
        $this->call(Milestone4Seeder::class);
        $this->call(MessageTemplateSeeder::class);
        $this->call(DemoSeeder::class);
    }
}
