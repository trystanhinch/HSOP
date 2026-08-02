<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 5 — regions hierarchy (structured geo for learning retrieval).
 * Seeds the 10 documented Lower Mainland / Fraser Valley regions from the 6B package appendix.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const INITIAL_REGIONS = [
        'Vancouver',
        'Langley',
        'Surrey',
        'Burnaby',
        'Richmond',
        'Coquitlam',
        'New Westminster',
        'North Vancouver',
        'Abbotsford',
        'Chilliwack',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('regions')) {
            Schema::create('regions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 120)->unique();
                $table->foreignId('parent_region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        foreach (self::INITIAL_REGIONS as $i => $name) {
            $slug = strtolower(str_replace(' ', '-', $name));
            $exists = DB::table('regions')->where('slug', $slug)->exists();
            if ($exists) {
                continue;
            }
            DB::table('regions')->insert([
                'name' => $name,
                'slug' => $slug,
                'parent_region_id' => null,
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
