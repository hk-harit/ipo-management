<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            // Grey market premium — what the unlisted share is changing hands at
            // over the issue price. The single biggest hint at listing gains.
            $table->decimal('gmp', 10, 2)->nullable()->after('price_max');
            $table->decimal('gmp_percent', 8, 2)->nullable()->after('gmp');
            $table->timestamp('gmp_updated_at')->nullable()->after('gmp_percent');

            // How many times over each category has been bid for. Retail is the
            // one that decides whether a retail application stands a chance.
            $table->decimal('subscription_retail', 10, 2)->nullable()->after('issue_size');
            $table->decimal('subscription_total', 10, 2)->nullable()->after('subscription_retail');
            $table->decimal('subscription_qib', 10, 2)->nullable()->after('subscription_total');
            $table->decimal('subscription_nii', 10, 2)->nullable()->after('subscription_qib');
            $table->unsignedBigInteger('subscription_applications')->nullable()->after('subscription_nii');
            $table->timestamp('subscription_updated_at')->nullable()->after('subscription_applications');
        });
    }

    public function down(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            $table->dropColumn([
                'gmp', 'gmp_percent', 'gmp_updated_at',
                'subscription_retail', 'subscription_total', 'subscription_qib',
                'subscription_nii', 'subscription_applications', 'subscription_updated_at',
            ]);
        });
    }
};
