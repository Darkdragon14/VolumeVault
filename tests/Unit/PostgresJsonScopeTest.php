<?php

namespace Tests\Unit;

use App\Models\BackupRun;
use App\Models\RestoreRun;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresJsonScopeTest extends TestCase
{
    public function test_lifecycle_scopes_use_postgresql_json_length_semantics(): void
    {
        config(['database.connections.pgsql_scope' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'unused',
            'username' => 'unused',
            'password' => 'unused',
        ]]);
        DB::purge('pgsql_scope');

        foreach ([BackupRun::class, RestoreRun::class] as $model) {
            $sql = $model::on('pgsql_scope')->activeOrHoldingContainers()->toSql();

            $this->assertStringContainsString('jsonb_array_length(("stopped_container_ids")::jsonb) > ?', $sql);
            $this->assertStringNotContainsString('"stopped_container_ids" <>', $sql);
        }
    }
}
