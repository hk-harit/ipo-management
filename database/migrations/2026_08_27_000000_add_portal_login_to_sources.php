<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Credentials for the source's own read-only portal. A source may log in
            // with either their mobile or their email — whichever the admin filled in.
            // Both are globally unique because they identify one login across the
            // whole app, not just within one admin's tenant.
            $table->string('mobile', 20)->nullable()->unique()->after('relation');
            $table->string('email')->nullable()->unique()->after('mobile');
            $table->string('password')->nullable()->after('email');

            // Off by default: a source only gets in once the admin deliberately
            // enables the portal and sets a password.
            $table->boolean('portal_enabled')->default(false)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('portal_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique(['mobile']);
            $table->dropUnique(['email']);
            $table->dropColumn(['mobile', 'email', 'password', 'portal_enabled', 'last_login_at']);
        });
    }
};
