<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'project_description')) {
                $table->text('project_description')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('leads', 'internal_notes')) {
                $table->text('internal_notes')->nullable()->after('project_description');
            }
            if (! Schema::hasColumn('leads', 'site_visit_time')) {
                $table->time('site_visit_time')->nullable()->after('site_visit_date');
            }
        });

        DB::statement("UPDATE leads SET status = 'quote_needed' WHERE status = 'quoted'");
        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM('new','contacted','site_visit_scheduled','quote_needed','converted','lost') NOT NULL DEFAULT 'new'");

        Schema::table('jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('jobs', 'job_title')) {
                $table->string('job_title')->nullable()->after('id');
            }
            if (! Schema::hasColumn('jobs', 'internal_notes')) {
                $table->text('internal_notes')->nullable()->after('scope_of_work');
            }
            if (! Schema::hasColumn('jobs', 'scheduled_start_time')) {
                $table->time('scheduled_start_time')->nullable()->after('scheduled_start_date');
            }
            if (! Schema::hasColumn('jobs', 'estimated_completion_date')) {
                $table->date('estimated_completion_date')->nullable()->after('scheduled_end_date');
            }
            if (! Schema::hasColumn('jobs', 'schedule_notes')) {
                $table->text('schedule_notes')->nullable()->after('estimated_completion_date');
            }
            if (! Schema::hasColumn('jobs', 'contractor_price_submitted_at')) {
                $table->timestamp('contractor_price_submitted_at')->nullable()->after('contractor_submitted_price');
            }
        });

        DB::statement("UPDATE jobs SET status = 'new_job' WHERE status = 'active'");
        DB::statement("ALTER TABLE jobs MODIFY COLUMN status ENUM('new_job','contractor_assigned','quote_sent','quote_approved','scheduled','in_progress','waiting_on_customer','completed_by_contractor','final_review','completed','cancelled') NOT NULL DEFAULT 'new_job'");

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'quote_number')) {
                $table->string('quote_number')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('quotes', 'scope_of_work')) {
                $table->text('scope_of_work')->nullable();
            }
            if (! Schema::hasColumn('quotes', 'gst_enabled')) {
                $table->boolean('gst_enabled')->default(true);
            }
            if (! Schema::hasColumn('quotes', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('quotes', 'gst_rate')) {
                $table->decimal('gst_rate', 5, 2)->default(5.00);
            }
            if (! Schema::hasColumn('quotes', 'internal_notes')) {
                $table->text('internal_notes')->nullable();
            }
            if (! Schema::hasColumn('quotes', 'customer_notes')) {
                $table->text('customer_notes')->nullable();
            }
            if (! Schema::hasColumn('quotes', 'rejection_reason')) {
                $table->string('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('quotes', 'viewed_at')) {
                $table->timestamp('viewed_at')->nullable();
            }
        });

        DB::statement("UPDATE quotes SET status = 'approved' WHERE status = 'accepted'");
        DB::statement("UPDATE quotes SET status = 'rejected' WHERE status = 'declined'");
        DB::statement("ALTER TABLE quotes MODIFY COLUMN status ENUM('draft','sent','viewed','approved','rejected','revised') NOT NULL DEFAULT 'draft'");

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'quote_id')) {
                $table->foreignId('quote_id')->nullable()->after('job_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->unique();
            }
            if (! Schema::hasColumn('invoices', 'scope_of_work')) {
                $table->text('scope_of_work')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'gst_rate')) {
                $table->decimal('gst_rate', 5, 2)->default(5.00);
            }
            if (! Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
        });

        DB::statement("UPDATE invoices SET status = 'draft' WHERE status = 'unpaid'");
        DB::statement("UPDATE invoices SET status = 'partially_paid' WHERE status = 'partial'");
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','sent','paid','partially_paid','overdue','cancelled') NOT NULL DEFAULT 'draft'");

        if (! Schema::hasTable('quote_items')) {
            Schema::create('quote_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
                $table->string('description');
                $table->decimal('quantity', 8, 2)->default(1);
                $table->string('unit')->nullable();
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('total', 10, 2)->default(0);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('job_updates')) {
            Schema::create('job_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained()->cascadeOnDelete();
                $table->foreignId('posted_by')->constrained('users')->cascadeOnDelete();
                $table->string('poster_role');
                $table->text('update_text');
                $table->enum('visibility', ['customer_visible', 'internal'])->default('customer_visible');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('job_update_photos')) {
            Schema::create('job_update_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_update_id')->constrained('job_updates')->cascadeOnDelete();
                $table->string('file_name');
                $table->string('file_url');
                $table->string('file_size')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'channel')) {
                $table->string('channel')->nullable()->after('job_id');
            }
            if (! Schema::hasColumn('messages', 'receiver_id')) {
                $table->foreignId('receiver_id')->nullable()->after('sender_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('messages', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('visibility');
            }
        });

        Schema::table('contractors', function (Blueprint $table) {
            if (! Schema::hasColumn('contractors', 'admin_notes')) {
                $table->text('admin_notes')->nullable();
            }
        });

        if (Schema::hasTable('contractor_documents')) {
            DB::statement("ALTER TABLE contractor_documents MODIFY COLUMN document_type ENUM('wcb','liability_insurance','business_license','other') NOT NULL");
            if (! Schema::hasColumn('contractor_documents', 'document_label')) {
                Schema::table('contractor_documents', function (Blueprint $table) {
                    $table->string('document_label')->nullable()->after('document_type');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_update_photos');
        Schema::dropIfExists('job_updates');
        Schema::dropIfExists('quote_items');
    }
};
