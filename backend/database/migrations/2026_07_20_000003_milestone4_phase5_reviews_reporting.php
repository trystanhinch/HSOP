<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'review_request_sent_at')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->timestamp('review_request_sent_at')->nullable()->after('customer_accepted_completion_at');
            });
        }

        if (! Schema::hasTable('review_feedback')) {
            Schema::create('review_feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedTinyInteger('star_rating');
                $table->text('comment')->nullable();
                $table->string('issue_category', 40)->nullable();
                $table->string('photo_url')->nullable();
                $table->string('follow_up_status', 40)->nullable();
                $table->text('resolution_notes')->nullable();
                $table->boolean('google_review_shown')->default(false);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->unique('job_id');
            });
        }

        if (! Schema::hasTable('ai_ops_reports')) {
            Schema::create('ai_ops_reports', function (Blueprint $table) {
                $table->id();
                $table->date('report_date');
                $table->string('period', 20); // daily|weekly
                $table->longText('summary_text');
                $table->json('raw_metrics')->nullable();
                $table->string('provider', 40)->nullable();
                $table->timestamps();

                $table->unique(['report_date', 'period']);
            });
        }

        $templates = [
            [
                'event_key' => 'review_request_customer',
                'channel' => 'sms',
                'label' => 'Review request (customer)',
                'body' => 'Hi {{customer_name}}, thanks for choosing ServiceOP! Please rate your experience (1-5 stars): {{review_url}}',
                'variables' => json_encode(['customer_name', 'review_url', 'address']),
            ],
        ];

        foreach ($templates as $tpl) {
            if (! DB::table('message_templates')->where('event_key', $tpl['event_key'])->exists()) {
                DB::table('message_templates')->insert([
                    ...$tpl,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_ops_reports');
        Schema::dropIfExists('review_feedback');

        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'review_request_sent_at')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropColumn('review_request_sent_at');
            });
        }
    }
};
