<?php

namespace Tests\Feature;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\RunBackupContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class NewVolumeRestoreOwnershipDockerTest extends TestCase
{
    public function test_created_helper_pins_volume_before_start_and_exposes_a_prior_replacement(): void
    {
        if (getenv('VOLUMEVAULT_RESTORE_OWNERSHIP_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Opt in to isolated privileged DinD with VOLUMEVAULT_RESTORE_OWNERSHIP_DOCKER_TEST=1.');
        }

        $image = RunBackupContainer::IMAGE;
        $dind = getenv('TEST_DIND_IMAGE') ?: 'docker:27-dind';
        foreach ([$image, $dind] as $required) {
            $this->assertTrue($this->docker(['image', 'inspect', $required])->isSuccessful(), 'Preload required image: '.$required);
        }

        $engine = 'vv-restore-ownership-'.bin2hex(random_bytes(8));
        try {
            $this->mustDocker(['run', '--pull=never', '--detach', '--privileged', '--network', 'none', '--name', $engine, '-e', 'DOCKER_TLS_CERTDIR=', $dind, '--tls=false']);
            $ready = false;
            for ($attempt = 0; $attempt < 60; $attempt++) {
                if ($this->docker(['exec', $engine, 'docker', 'info'])->isSuccessful()) {
                    $ready = true;
                    break;
                }
                usleep(500000);
            }
            $this->assertTrue($ready, 'Isolated Docker daemon did not become ready.');
            $archive = tmpfile();
            $this->assertIsResource($archive);
            try {
                $save = new Process(['docker', 'image', 'save', $image]);
                $save->setTimeout(120)->run(function (string $type, string $chunk) use ($archive): void {
                    if ($type === Process::OUT) {
                        fwrite($archive, $chunk);
                    }
                });
                $this->assertTrue($save->isSuccessful());
                rewind($archive);
                $load = new Process(['docker', 'exec', '-i', $engine, 'docker', 'load']);
                $load->setInput($archive)->setTimeout(120)->mustRun();
            } finally {
                fclose($archive);
            }

            $prefix = ['exec', $engine, 'docker'];
            $nonce = bin2hex(random_bytes(32));
            $label = CreateDockerVolume::RESTORE_OWNERSHIP_LABEL;
            $this->mustDocker([...$prefix, 'volume', 'create', '--label', $label.'='.$nonce, 'owned']);
            $id = trim($this->mustDocker([...$prefix, 'create', '--mount', 'type=volume,source=owned,target=/restore,volume-nocopy', '--entrypoint', 'sh', $image, '-c', 'echo restored > /restore/marker']));

            $removal = $this->docker([...$prefix, 'volume', 'rm', '--force', 'owned']);
            $this->assertFalse($removal->isSuccessful());
            $this->assertStringContainsString('in use', strtolower($removal->getErrorOutput()));
            $volume = json_decode($this->mustDocker([...$prefix, 'volume', 'inspect', 'owned']), true);
            $this->assertSame($nonce, $volume[0]['Labels'][$label]);
            $this->mustDocker([...$prefix, 'start', '--attach', $id]);
            $this->mustDocker([...$prefix, 'rm', '--force', $id]);
            $this->assertSame("restored\n", $this->mustDocker([...$prefix, 'run', '--rm', '-v', 'owned:/restore:ro', '--entrypoint', 'cat', $image, '/restore/marker']));

            // Replace the unused target in the window before helper creation.
            $this->mustDocker([...$prefix, 'volume', 'rm', 'owned']);
            $this->mustDocker([...$prefix, 'volume', 'create', '--label', $label.'=foreign', 'owned']);
            $this->mustDocker([...$prefix, 'run', '--rm', '-v', 'owned:/restore', '--entrypoint', 'sh', $image, '-c', 'echo sentinel > /restore/marker']);
            $id = trim($this->mustDocker([...$prefix, 'create', '--mount', 'type=volume,source=owned,target=/restore,volume-nocopy', '--entrypoint', 'sh', $image, '-c', 'echo clobbered > /restore/marker']));
            $volume = json_decode($this->mustDocker([...$prefix, 'volume', 'inspect', 'owned']), true);
            $this->assertNotSame($nonce, $volume[0]['Labels'][$label]);
            $this->mustDocker([...$prefix, 'rm', '--force', $id]);
            $this->assertSame("sentinel\n", $this->mustDocker([...$prefix, 'run', '--rm', '-v', 'owned:/restore:ro', '--entrypoint', 'cat', $image, '/restore/marker']));
        } finally {
            $this->mustDocker(['rm', '--force', '--volumes', $engine]);
        }
    }

    private function docker(array $arguments): Process
    {
        $process = new Process(['docker', ...$arguments]);
        $process->setTimeout(120)->run();

        return $process;
    }

    private function mustDocker(array $arguments): string
    {
        $process = $this->docker($arguments);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}
