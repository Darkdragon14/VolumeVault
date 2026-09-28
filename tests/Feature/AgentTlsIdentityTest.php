<?php

namespace Tests\Feature;

use App\Services\Agents\AgentTlsIdentity;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentTlsIdentityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/volumevault-tls-'.bin2hex(random_bytes(10));
        config(['volumevault.agents.enabled' => true, 'volumevault.agents.url' => 'https://vault.example:8443', 'volumevault.agents.tls_directory' => $this->directory]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('origins')]
    public function test_real_certificates_are_trusted_for_the_origin_and_persist(string $origin, string $host, string $option): void
    {
        config(['volumevault.agents.url' => $origin]);
        $identity = new AgentTlsIdentity;
        $ca = $identity->caCertificate();
        $server = file_get_contents($identity->certificatePath());
        $key = file_get_contents($identity->privateKeyPath());
        $identity->ensure();
        $this->assertSame($ca, $identity->caCertificate());
        $this->assertSame($server, file_get_contents($identity->certificatePath()));
        $this->assertSame($key, file_get_contents($identity->privateKeyPath()));

        $verify = new Process(['openssl', 'verify', '-CAfile', $this->directory.'/ca.crt', '-purpose', 'sslserver', $option, $host, $identity->certificatePath()]);
        $verify->run();
        $this->assertTrue($verify->isSuccessful(), $verify->getErrorOutput());
        $wrongHost = new Process(['openssl', 'verify', '-CAfile', $this->directory.'/ca.crt', '-verify_hostname', 'wrong.example', $identity->certificatePath()]);
        $wrongHost->run();
        $this->assertFalse($wrongHost->isSuccessful());
        $parsed = openssl_x509_parse($server);
        $this->assertEqualsWithDelta(90 * 86400, $parsed['validTo_time_t'] - time(), 10);
        $this->assertEqualsWithDelta(3650 * 86400, openssl_x509_parse($ca)['validTo_time_t'] - time(), 10);
        $this->assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_private($key))['bits']);
        $this->assertSame(0700, fileperms($this->directory) & 0777);
        foreach (['ca.crt', 'ca.key', 'server.crt', 'server.key', '.lock'] as $file) {
            $this->assertSame(0600, fileperms($this->directory.'/'.$file) & 0777);
        }
    }

    public static function origins(): array
    {
        return [
            ['https://vault.example:8443/', 'vault.example', '-verify_hostname'],
            ['https://192.0.2.20', '192.0.2.20', '-verify_ip'],
            ['https://[2001:db8::20]:8443', '2001:db8::20', '-verify_ip'],
        ];
    }

    public function test_renewal_and_hostname_changes_preserve_the_ca_and_server_key(): void
    {
        $identity = new AgentTlsIdentity;
        $ca = $identity->caCertificate();
        $key = file_get_contents($identity->privateKeyPath());
        $first = file_get_contents($identity->certificatePath());
        $this->travel(61)->days();
        $identity->ensure();
        $renewed = file_get_contents($identity->certificatePath());
        $this->assertNotSame($first, $renewed);
        $this->travelBack();
        config(['volumevault.agents.url' => 'https://new.example']);
        $identity->ensure();
        $this->assertSame($ca, $identity->caCertificate());
        $this->assertSame($key, file_get_contents($identity->privateKeyPath()));
        $this->assertSame('DNS:new.example', openssl_x509_parse(file_get_contents($identity->certificatePath()))['extensions']['subjectAltName']);
    }

    public function test_https_handshake_trusts_the_persisted_ca(): void
    {
        $identity = new AgentTlsIdentity;
        $identity->ensure();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process(['openssl', 's_server', '-accept', $address, '-cert', $identity->certificatePath(), '-key', $identity->privateKeyPath(), '-www']);
        $server->start();
        $context = stream_context_create(['ssl' => ['cafile' => $this->directory.'/ca.crt', 'peer_name' => 'vault.example', 'verify_peer' => true, 'verify_peer_name' => true]]);
        $connection = false;

        try {
            for ($attempt = 0; $attempt < 50 && ! $connection; $attempt++) {
                $connection = @stream_socket_client('tls://'.$address, $errorCode, $errorMessage, 1, STREAM_CLIENT_CONNECT, $context);
                if (! $connection) {
                    usleep(20000);
                }
            }
            $this->assertIsResource($connection, $server->getErrorOutput());
            stream_set_timeout($connection, 2);
            fwrite($connection, "GET / HTTP/1.0\r\n\r\n");
            $this->assertStringContainsString('HTTP/1.0 200', fgets($connection));
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
            $server->stop();
        }
    }

    public function test_concurrent_preparations_share_one_identity(): void
    {
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config('.var_export([
            'volumevault.agents.url' => 'https://vault.example',
            'volumevault.agents.tls_directory' => $this->directory,
        ], true).'); echo hash("sha256", (new App\\Services\\Agents\\AgentTlsIdentity)->caCertificate());';
        $processes = [];
        for ($index = 0; $index < 4; $index++) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path());
            $process->start();
            $processes[] = $process;
        }
        $fingerprints = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $fingerprints[] = $process->getOutput();
        }
        $this->assertCount(1, array_unique($fingerprints));
        $this->assertSame(hash('sha256', file_get_contents($this->directory.'/ca.crt')), $fingerprints[0]);
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_origins_fail_before_creating_files(?string $origin): void
    {
        config(['volumevault.agents.url' => $origin]);
        try {
            (new AgentTlsIdentity)->ensure();
            $this->fail('Invalid origin accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->directory);
        }
    }

    public static function invalidOrigins(): array
    {
        return array_map(fn ($url) => [$url], [null, '', 'http://vault.example', 'https://user:secret@vault.example', 'https://vault.example/path', 'https://vault.example?', 'https://vault.example#', "https://vault.example\nDNS:evil.example", 'https://bad_host', 'https://*.example', 'https://999.999.1.1', 'https://[not-ip]', 'https://vault.example:0', 'https://vault.example:65536', 'https://vault.example\\evil', 'https://vault.example,DNS:evil.example']);
    }

    #[DataProvider('brokenCaStates')]
    public function test_broken_ca_state_fails_closed(string $state): void
    {
        $identity = new AgentTlsIdentity;
        $identity->ensure();
        if ($state === 'missing-key') {
            unlink($this->directory.'/ca.key');
        } elseif ($state === 'missing-certificate') {
            unlink($this->directory.'/ca.crt');
        } elseif ($state === 'missing-both') {
            unlink($this->directory.'/ca.key');
            unlink($this->directory.'/ca.crt');
        } else {
            file_put_contents($this->directory.'/ca.key', 'corrupt');
        }
        $before = array_map('file_get_contents', glob($this->directory.'/*'));
        try {
            $identity->ensure();
            $this->fail('Broken CA accepted.');
        } catch (RuntimeException) {
            $this->assertSame($before, array_map('file_get_contents', glob($this->directory.'/*')));
        }
    }

    public static function brokenCaStates(): array
    {
        return [['missing-key'], ['missing-certificate'], ['missing-both'], ['corrupt']];
    }

    public function test_prepare_command_is_opt_in_and_reports_generic_status(): void
    {
        config(['volumevault.agents.enabled' => false]);
        $this->artisan('volumevault:agent-tls:prepare')->expectsOutput('Agent TLS is disabled.')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->directory);
        config(['volumevault.agents.enabled' => true]);
        $this->artisan('volumevault:agent-tls:prepare')->expectsOutput('Agent TLS is ready.')->assertSuccessful();
        config(['volumevault.agents.url' => 'https://secret@vault.example']);
        $this->artisan('volumevault:agent-tls:prepare')->expectsOutput('Agent TLS preparation failed. Check the HTTPS origin and private TLS storage.')->assertFailed();
    }

    public function test_symlink_identity_is_rejected(): void
    {
        mkdir($this->directory, 0700);
        symlink('/dev/null', $this->directory.'/ca.key');
        $this->expectException(RuntimeException::class);
        (new AgentTlsIdentity)->ensure();
    }
}
