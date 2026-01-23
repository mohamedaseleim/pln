<?php

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I57_RemoveScheduledTask extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('scheduled_tasks')
            ->where('class_name', '=', 'plugins.generic.pln.classes.tasks.Depositor')
            ->delete();
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
