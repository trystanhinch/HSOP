<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A-26 polish is frontend-only (Reports/Ledger drill-down).
 * A-32: quote lifecycle vs follow-up task, revision immutability, timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(1)->after('quote_number');
            }
            if (! Schema::hasColumn('quotes', 'parent_quote_id')) {
                $table->unsignedBigInteger('parent_quote_id')->nullable()->after('revision_number');
            }
            if (! Schema::hasColumn('quotes', 'root_quote_id')) {
                $table->unsignedBigInteger('root_quote_id')->nullable()->after('parent_quote_id');
            }
            if (! Schema::hasColumn('quotes', 'is_immutable')) {
                $table->boolean('is_immutable')->default(false)->after('root_quote_id');
            }
            if (! Schema::hasColumn('quotes', 'declined_at')) {
                $table->timestamp('declined_at')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('quotes', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('declined_at');
            }
            if (! Schema::hasColumn('quotes', 'follow_up_due_at')) {
                $table->timestamp('follow_up_due_at')->nullable()->after('expired_at');
            }
            if (! Schema::hasColumn('quotes', 'follow_up_stopped_at')) {
                $table->timestamp('follow_up_stopped_at')->nullable()->after('follow_up_due_at');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE quotes MODIFY COLUMN status ENUM(
                'pricing_requested','pricing_received','draft','internal_review','sent','viewed',
                'follow_up','revision_requested','approved','declined','expired','rejected','revised'
            ) NOT NULL DEFAULT 'draft'");
        }

        // A-32: follow_up was a status — restore to viewed (task state is separate).
        DB::table('quotes')->where('status', 'follow_up')->update(['status' => 'viewed']);
        DB::table('quotes')->where('status', 'rejected')->whereNull('declined_at')->update([
            'status' => 'declined',
            'declined_at' => DB::raw('COALESCE(updated_at, NOW())'),
        ]);
        DB::table('quotes')->whereIn('status', ['sent', 'viewed', 'approved', 'declined', 'expired', 'revision_requested'])
            ->where('is_immutable', false)
            ->update(['is_immutable' => true]);
        DB::table('quotes')->whereNull('revision_number')->orWhere('revision_number', 0)->update(['revision_number' => 1]);
        DB::table('quotes')->whereNull('root_quote_id')->update(['root_quote_id' => DB::raw('id')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        Schema::table('quotes', function (Blueprint $table) {
            foreach ([
                'revision_number', 'parent_quote_id', 'root_quote_id', 'is_immutable',
                'declined_at', 'expired_at', 'follow_up_due_at', 'follow_up_stopped_at',
            ] as $col) {
                if (Schema::hasColumn('quotes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
