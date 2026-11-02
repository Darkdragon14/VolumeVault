<?php

namespace Tests\Feature;

use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Support\SshHostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SftpBackupDockerTest extends TestCase
{
    use RefreshDatabase;

    private const SFTP_IMAGE = 'atmoz/sftp:alpine';

    private const MARKER = 'volumevault-isolated-sftp-backup-marker';

    private string $network = '';

    private string $server = '';

    private string $volume = '';

    private string $host = '';

    private string $hostKey = '';

    private string $password = '';

    /** @var list<BackupRun> */
    private array $runs = [];

    protected function setUp(): void
    {
        if (getenv('VOLUMEVAULT_SFTP_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Opt in with VOLUMEVAULT_SFTP_DOCKER_TEST=1; requires local Docker and preloaded images.');
        }

        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        if ((new ExecutableFinder)->find('docker') === null) {
            $this->markTestSkipped('Docker CLI is required.');
        }
        if (! $this->docker(['info'])->isSuccessful()) {
            $this->markTestSkipped('A working local Docker CLI/socket is required.');
        }
        foreach ([self::SFTP_IMAGE, RunBackupContainer::IMAGE] as $image) {
            if (! $this->docker(['image', 'inspect', $image])->isSuccessful()) {
                $this->markTestSkipped('Preload required image (tests never pull): '.$image);
            }
        }

        $prefix = 'vv-sftp-test-'.bin2hex(random_bytes(10));
        $this->network = $prefix.'-net';
        $this->server = $prefix.'-server';
        $this->volume = $prefix.'-source';
        $this->password = bin2hex(random_bytes(20));

        // Blank lets the CLI use its local socket WITHOUT mounting that socket in Offen.
        // Even a read-only socket would let Offen stop production containers.
        config([
            'volumevault.docker_host' => '',
            'volumevault.docker_network' => $this->network,
        ]);

        $this->mustDocker(['network', 'create', '--internal', $this->network]);
        $this->mustDocker(['volume', 'create', $this->volume]);
        $this->mustDocker([
            'run', '--rm', '--pull=never', '--network', 'none', '--name', $this->volume.'-seed',
            '-v', $this->volume.':/source', '--entrypoint', 'sh', RunBackupContainer::IMAGE,
            '-c', 'printf %s "$1" > /source/marker.txt', '--', self::MARKER,
        ]);

        // One algorithm ensures PHP's probe and Offen see the same fixture host key.
        $sshdConfig = "HostKey /etc/ssh/ssh_host_ed25519_key\nSubsystem sftp internal-sftp\nPasswordAuthentication yes\nChrootDirectory %h\nForceCommand internal-sftp\nPermitRootLogin no\n";
        $this->mustDocker([
            'run', '--detach', '--pull=never', '--network', $this->network,
            '--name', $this->server, '--entrypoint', 'sh', self::SFTP_IMAGE,
            '-c', 'printf %s "$1" > /etc/ssh/sshd_config; shift; exec /entrypoint "$@"',
            '--', $sshdConfig, 'backup:'.$this->password.':1001:1001:upload',
        ]);

        $ready = false;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $key = $this->docker(['exec', $this->server, 'cat', '/etc/ssh/ssh_host_ed25519_key.pub']);
            $this->host = trim($this->mustDocker(['inspect', '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', $this->server]));
            $socket = $this->host !== '' ? @fsockopen($this->host, 22, $error, $message, 0.5) : false;
            if ($key->isSuccessful() && is_resource($socket)) {
                fclose($socket);
                $this->hostKey = SshHostKey::publicKey($key->getOutput());
                $ready = $this->hostKey !== '';
                break;
            }
            if (is_resource($socket)) {
                fclose($socket);
            }
            usleep(200000);
        }
        $this->assertTrue($ready, 'Disposable SFTP server unreachable; run the PHPUnit container with --network host.');
        config(['volumevault.ssrf.allowed_ips' => [$this->host]]);
    }

    protected function tearDown(): void
    {
        try {
            // Exact identities only: no prune or discovery of unrelated resources.
            foreach ($this->runs as $run) {
                $name = $run->fresh()?->docker_container_id;
                if (is_string($name) && $name !== '') {
                    $this->docker(['rm', '--force', '--volumes', $name]);
                }
            }
            if ($this->server !== '') {
                $this->docker(['rm', '--force', '--volumes', $this->server]);
            }
            if ($this->volume !== '') {
                $this->docker(['rm', '--force', $this->volume.'-seed']);
                $this->docker(['volume', 'rm', $this->volume]);
            }
            if ($this->network !== '') {
                $this->docker(['network', 'rm', $this->network]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_password_backup_with_full_host_key_uploads_the_actual_source_archive(): void
    {
        $this->assertUploadedArchive($this->backupRun($this->hostKey));
    }

    public function test_password_backup_with_fingerprint_probes_the_real_server_and_uploads(): void
    {
        $this->assertUploadedArchive($this->backupRun(SshHostKey::fingerprint($this->hostKey)));
    }

    public function test_private_key_backup_with_full_host_key_uploads(): void
    {
        $key = RSA::createKey(2048);
        $this->mustDocker([
            'exec', $this->server, 'sh', '-c',
            'mkdir -p /home/backup/.ssh; printf "%s\n" "$1" > /home/backup/.ssh/authorized_keys; chown -R 1001:1001 /home/backup/.ssh; chmod 700 /home/backup/.ssh; chmod 600 /home/backup/.ssh/authorized_keys',
            '--', $key->getPublicKey()->toString('OpenSSH'),
        ]);

        $this->assertUploadedArchive($this->backupRun($this->hostKey, ['private_key' => $key->toString('PKCS8')]));
    }

    public function test_wrong_full_host_key_is_refused_by_offen_without_uploading(): void
    {
        $wrongKey = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $run = $this->backupRun($wrongKey);
        $result = $this->action()->handle($run);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('host key', strtolower($result->output.$result->errorOutput));
        $this->assertNotNull($run->fresh()->docker_container_id, 'Refusal must come from the real Offen helper.');
        $this->assertNoUploads();
    }

    public function test_missing_pin_fails_before_creating_a_backup_helper_or_uploading(): void
    {
        $run = $this->backupRun(null);

        try {
            $this->action()->handle($run);
            $this->fail('A missing pin must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Configure a valid SFTP', $exception->getMessage());
        }

        $this->assertNull($run->fresh()->docker_container_id);
        $this->assertNoUploads();
    }

    /** @param array<string, string>|null $credentials */
    private function backupRun(?string $pin, ?array $credentials = null): BackupRun
    {
        $destination = BackupDestination::create([
            'name' => 'Disposable SFTP integration destination',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => $this->host, 'port' => 22, 'remote_path' => '/upload', 'host_key' => $pin],
            'secrets' => ['user' => 'backup', ...($credentials ?? ['password' => $this->password])],
        ]);
        $job = BackupJob::create([
            'name' => 'Disposable source',
            'volume_name' => $this->volume,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'stop_containers_before_backup' => false,
        ]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_filename' => 'integration.tar.gz',
        ]);
        $this->runs[] = $run;

        return $run;
    }

    private function assertUploadedArchive(BackupRun $run): void
    {
        $result = $this->action()->handle($run);
        $this->assertTrue($result->successful(), $result->output.$result->errorOutput);
        $this->assertSame('integration.tar.gz', trim($this->mustDocker([
            'exec', $this->server, 'find', '/home/backup/upload', '-type', 'f', '-exec', 'basename', '{}', ';',
        ])));
        $this->assertSame(self::MARKER, $this->mustDocker([
            'exec', $this->server, 'tar', '-xzOf', '/home/backup/upload/integration.tar.gz',
            'backup/'.$this->volume.'/marker.txt',
        ]));
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
    }

    private function assertNoUploads(): void
    {
        $this->assertSame('', trim($this->mustDocker([
            'exec', $this->server, 'find', '/home/backup/upload', '-type', 'f',
        ])));
    }

    private function action(): RunBackupContainer
    {
        // Real Docker execution with an extra fail-closed mount guard and no-pull policy.
        $docker = new class($this->volume, $this->network) extends DockerProcess
        {
            public function __construct(private string $volume, private string $network) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (in_array($command[1] ?? '', ['run', 'create'], true)) {
                    $expectedMount = $this->volume.':/backup/'.$this->volume.':ro';
                    $mounts = [];
                    foreach ($command as $index => $argument) {
                        if (in_array($argument, ['-v', '--mount', '--volume'], true)) {
                            $mounts[] = $command[$index + 1] ?? '';
                        }
                    }
                    $networkIndex = array_search('--network', $command, true);
                    if ($mounts !== [$expectedMount] || ($environment['DOCKER_HOST'] ?? null) !== ''
                        || $networkIndex === false || $command[$networkIndex + 1] !== $this->network
                        || end($command) !== RunBackupContainer::IMAGE) {
                        throw new RuntimeException('Unsafe integration helper: refusing unexpected mounts, Docker host, network, or image.');
                    }
                    array_splice($command, 2, 0, ['--pull=never']);
                    // Even an empty DOCKER_HOST makes Offen require the Docker API.
                    // Omit only orchestration configuration in this socket-free fixture.
                    foreach ($command as $index => $argument) {
                        if ($argument === '--env' && ($command[$index + 1] ?? '') === 'DOCKER_HOST') {
                            array_splice($command, $index, 2);
                            break;
                        }
                    }
                    unset($environment['DOCKER_HOST']);
                }

                return parent::run($command, $timeout === 0 ? 120 : $timeout, $environment);
            }
        };

        return new RunBackupContainer($docker);
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments): Process
    {
        $process = new Process(['docker', '--host', 'unix:///var/run/docker.sock', ...$arguments], null, [
            'DOCKER_HOST' => false,
            'DOCKER_CONTEXT' => false,
            'DOCKER_TLS' => false,
            'DOCKER_TLS_VERIFY' => false,
            'DOCKER_CERT_PATH' => false,
        ]);
        $process->setTimeout(120)->run();

        return $process;
    }

    /** @param list<string> $arguments */
    private function mustDocker(array $arguments): string
    {
        $process = $this->docker($arguments);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}
