<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_request_patrocinio_users', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->default(0)->after('points');
            $table->date('period')->nullable()->after('amount')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->after('confirm');
            $table->dateTime('requested_at')->nullable()->after('approved_by');
            $table->dateTime('approved_at')->nullable()->after('requested_at');
            $table->dateTime('paid_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('paid_at');
        });

        DB::table('collection_request_patrocinio_users')->orderBy('id')->each(function ($row) {
            DB::table('collection_request_patrocinio_users')->where('id', $row->id)->update([
                'amount' => $row->points,
                'period' => date('Y-m-01', strtotime($row->created_at)),
                'requested_at' => $row->created_at,
                'approved_at' => (int) $row->state === 2 ? ($row->confirm ?? $row->updated_at) : null,
                'paid_at' => (int) $row->state === 2 ? ($row->confirm ?? $row->updated_at) : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('collection_request_patrocinio_users', function (Blueprint $table) {
            $table->dropIndex(['period']);
            $table->dropColumn(['amount', 'period', 'approved_by', 'requested_at', 'approved_at', 'paid_at', 'rejection_reason']);
        });
    }
};
