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
}
