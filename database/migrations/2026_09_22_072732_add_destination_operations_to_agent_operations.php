<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_operations', 'backup_destination_id')) {
                $table->foreignId('backup_destination_id')->nullable();
            }
            if (! Schema::hasColumn('agent_operations', 'destination_action')) {
                $table->string('destination_action')->nullable();
            }
            if (! Schema::hasColumn('agent_operations', 'locator_fingerprint')) {
                $table->string('locator_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('agent_operations', 'result')) {
                $table->text('result')->nullable();
            }
        });

        Schema::table('agent_operations', function (Blueprint $table): void {
            if (! collect(Schema::getForeignKeys('agent_operations'))->contains('columns', ['backup_destination_id'])) {
                $table->foreign('backup_destination_id')->references('id')->on('backup_destinations')->nullOnDelete();
            }
            if (! Schema::hasIndex('agent_operations', ['backup_destination_id', 'destination_action', 'status'])) {
                $table->index(['backup_destination_id', 'destination_action', 'status'], $this->destinationIndexName());
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_operations', function (Blueprint $table): void {
            $table->dropForeign(['backup_destination_id']);
            $table->dropIndex($this->destinationIndexName());
            $table->dropColumn(['backup_destination_id', 'destination_action', 'locator_fingerprint', 'result']);
        });
    }

    private function destinationIndexName(): string
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'agent_operations_destination_action_status_index'
            : 'agent_operations_backup_destination_id_destination_action_status_index';
    }
};
