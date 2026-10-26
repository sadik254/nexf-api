<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->whereNotNull('roster_archived_at')
            ->update([
                'crm_status' => 'suspended',
                'roster_archived_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The old archive state deliberately has no replacement: suspended
        // customers must remain visible so an administrator can restore them.
    }
};
