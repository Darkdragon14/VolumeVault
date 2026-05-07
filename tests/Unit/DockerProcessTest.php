<?php

namespace Tests\Unit;

use App\Services\Docker\DockerProcess;
use Tests\TestCase;

class DockerProcessTest extends TestCase
{
    public function test_docker_process_uses_writable_docker_cli_environment(): void
    {
        $result = app(DockerProcess::class)->run([
            PHP_BINARY,
            '-r',
            'echo getenv("HOME")."\n".getenv("DOCKER_CONFIG")."\n".getenv("XDG_CONFIG_HOME");',
        ], 10, [
            'HOME' => '/root',
        ]);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame(
            storage_path('app/docker-cli/home')."\n".
            storage_path('app/docker-cli/config')."\n".
            storage_path('app/docker-cli/config'),
            $result->output,
        );
    }
}
