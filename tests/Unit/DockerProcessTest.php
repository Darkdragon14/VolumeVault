<?php

namespace Tests\Unit;

use App\Services\Docker\DockerProcess;
use Illuminate\Support\Facades\File;
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

    public function test_docker_process_uses_the_configured_docker_host_and_removes_inherited_client_configuration(): void
    {
        config(['volumevault.docker_host' => 'tcp://docker.example.test:2375']);

        $dockerClientVariables = [
            'DOCKER_CONTEXT',
            'DOCKER_TLS',
            'DOCKER_TLS_VERIFY',
            'DOCKER_CERT_PATH',
        ];
        $previousEnvironment = [];

        foreach ($dockerClientVariables as $variable) {
            $previousEnvironment[$variable] = getenv($variable);
            putenv("{$variable}=inherited");
        }

        try {
            $result = app(DockerProcess::class)->run([
                PHP_BINARY,
                '-r',
                'echo getenv("DOCKER_HOST"); foreach (["DOCKER_CONTEXT", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"] as $variable) { echo "\n".(getenv($variable) === false ? "unset" : getenv($variable)); }',
            ], 10, [
                'DOCKER_HOST' => 'unix:///tmp/ignored.sock',
            ]);

            $this->assertSame(0, $result->exitCode);
            $this->assertSame(
                "tcp://docker.example.test:2375\nunset\nunset\nunset\nunset",
                $result->output,
            );
        } finally {
            foreach ($previousEnvironment as $variable => $value) {
                putenv($value === false ? $variable : "{$variable}={$value}");
            }
        }
    }

    public function test_docker_process_can_stream_an_input_file_to_stdin(): void
    {
        $inputPath = sys_get_temp_dir().'/volumevault-docker-process-stdin-'.uniqid();
        File::put($inputPath, 'archive-bytes');

        try {
            $result = app(DockerProcess::class)->runWithInputFile([
                PHP_BINARY,
                '-r',
                'echo strtoupper(file_get_contents("php://stdin"));',
            ], $inputPath, 10);

            $this->assertSame(0, $result->exitCode);
            $this->assertSame('ARCHIVE-BYTES', $result->output);
        } finally {
            File::delete($inputPath);
        }
    }

    public function test_docker_process_reports_progress_while_a_silent_process_runs(): void
    {
        $heartbeats = 0;
        $dockerProcess = app(DockerProcess::class);

        $result = $dockerProcess->whileMonitoring(
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
            fn () => $dockerProcess->run([
                PHP_BINARY,
                '-r',
                'usleep(2200000); echo "done";',
            ], 10),
            1,
        );

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('done', $result->output);
        $this->assertGreaterThanOrEqual(3, $heartbeats);
    }

    public function test_monitoring_state_is_restored_when_the_initial_callback_fails(): void
    {
        $dockerProcess = app(DockerProcess::class);

        try {
            $dockerProcess->whileMonitoring(
                fn () => throw new \RuntimeException('heartbeat failed'),
                fn () => $this->fail('The operation must not run after a failed heartbeat.'),
            );
            $this->fail('The heartbeat exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('heartbeat failed', $exception->getMessage());
        }

        $result = $dockerProcess->run([PHP_BINARY, '-r', 'echo "ok";'], 10);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->output);
    }
}
