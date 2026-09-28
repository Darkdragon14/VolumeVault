<?php

namespace App\Services\Agents;

use OpenSSLAsymmetricKey;
use RuntimeException;

class AgentTlsIdentity
{
    public function certificatePath(): string
    {
        return $this->directory().'/server.crt';
    }

    public function privateKeyPath(): string
    {
        return $this->directory().'/server.key';
    }

    public function caCertificate(): string
    {
        $this->ensure();

        return $this->read($this->directory().'/ca.crt');
    }

    public function ensure(): void
    {
        $host = $this->host();
        $directory = $this->directory();
        $mask = umask(0077);
        $lock = null;

        try {
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) || ! @chmod($directory, 0700)) {
                throw new RuntimeException('Cannot secure agent TLS directory.');
            }

            $lockPath = $directory.'/.lock';
            if (is_link($lockPath) || ($lock = @fopen($lockPath, 'c')) === false || ! @chmod($lockPath, 0600) || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock agent TLS identity.');
            }
            clearstatcache();

            $caCertificatePath = $directory.'/ca.crt';
            $caKeyPath = $directory.'/ca.key';
            foreach ([$caCertificatePath, $caKeyPath, $this->certificatePath(), $this->privateKeyPath()] as $path) {
                if (is_link($path) || (file_exists($path) && (! is_file($path) || ! @chmod($path, 0600)))) {
                    throw new RuntimeException('Cannot secure agent TLS identity.');
                }
            }

            if (file_exists($caCertificatePath) !== file_exists($caKeyPath)) {
                throw new RuntimeException('Agent TLS CA is incomplete; restore the original CA identity.');
            }

            if (! file_exists($caCertificatePath)) {
                if (file_exists($this->certificatePath()) || file_exists($this->privateKeyPath())) {
                    throw new RuntimeException('Agent TLS CA is missing; restore the original CA identity.');
                }

                $caKey = $this->newKey();
                $caCertificate = $this->issue($caKey, null, $caKey, null, 3650);
                $this->writeKey($caKeyPath, $caKey);
                $this->write($caCertificatePath, $caCertificate);
            }

            $caCertificate = $this->read($caCertificatePath);
            $caKey = @openssl_pkey_get_private($this->read($caKeyPath));
            $ca = @openssl_x509_parse($caCertificate);
            if (! $caKey || ! $ca || ! @openssl_x509_check_private_key($caCertificate, $caKey)
                || @openssl_x509_verify($caCertificate, $caCertificate) !== 1
                || ! str_contains($ca['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                || $ca['validFrom_time_t'] > time() || $ca['validTo_time_t'] <= now()->addDays(90)->timestamp) {
                throw new RuntimeException('Agent TLS CA is invalid or expiring; restore or explicitly rotate the CA identity.');
            }

            $serverKey = file_exists($this->privateKeyPath())
                ? @openssl_pkey_get_private($this->read($this->privateKeyPath())) : $this->newKey();
            if (! $serverKey) {
                throw new RuntimeException('Agent TLS private key is invalid.');
            }

            $certificate = file_exists($this->certificatePath()) ? $this->read($this->certificatePath()) : '';
            $parsed = $certificate !== '' ? @openssl_x509_parse($certificate) : false;
            $san = filter_var($host, FILTER_VALIDATE_IP) ? 'IP Address:'.inet_ntop(inet_pton($host)) : 'DNS:'.$host;
            $actualSan = $parsed['extensions']['subjectAltName'] ?? '';
            if (str_starts_with($actualSan, 'IP Address:')) {
                $ip = substr($actualSan, 11);
                $actualSan = filter_var($ip, FILTER_VALIDATE_IP) ? 'IP Address:'.inet_ntop(inet_pton($ip)) : '';
            }

            if ($parsed && $parsed['validTo_time_t'] > now()->addDays(30)->timestamp
                && $parsed['validFrom_time_t'] <= time() && $actualSan === $san
                && @openssl_x509_check_private_key($certificate, $serverKey)
                && @openssl_x509_verify($certificate, $caCertificate) === 1) {
                return;
            }

            $certificate = $this->issue($serverKey, $caCertificate, $caKey, $host, 90);
            if (! file_exists($this->privateKeyPath())) {
                $this->writeKey($this->privateKeyPath(), $serverKey);
            }
            $this->write($this->certificatePath(), $certificate);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            umask($mask);
        }
    }

    private function directory(): string
    {
        return rtrim(config('volumevault.agents.tls_directory', storage_path('app/private/agent-tls')), '/');
    }

    private function host(): string
    {
        $url = config('volumevault.agents.url');
        $parts = is_string($url) && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url) ? parse_url($url) : false;
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new RuntimeException('Agent URL must be a valid HTTPS origin.');
        }

        $host = strtolower($parts['host'] ?? '');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new RuntimeException('Agent URL must contain a valid hostname or IP address.');
            }
        } elseif (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || preg_match('/^[0-9.]+$/', $host))) {
            throw new RuntimeException('Agent URL must contain a valid hostname or IP address.');
        }

        return $host;
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if (! $key) {
            throw new RuntimeException('Cannot generate agent TLS key.');
        }

        return $key;
    }

    private function issue(OpenSSLAsymmetricKey $key, ?string $issuer, OpenSSLAsymmetricKey $issuerKey, ?string $host, int $days): string
    {
        $extensions = $host === null
            ? "basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n"
            : "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=".(filter_var($host, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:').$host."\n";
        $path = $this->directory().'/.openssl-'.bin2hex(random_bytes(12));
        $this->write($path, "[req]\ndistinguished_name=dn\n[dn]\n[extensions]\nsubjectKeyIdentifier=hash\n".$extensions);

        try {
            $options = ['config' => $path, 'digest_alg' => 'sha256', 'x509_extensions' => 'extensions'];
            $csr = openssl_csr_new(['commonName' => $host === null ? 'VolumeVault Agent CA' : 'VolumeVault Agent Server'], $key, $options);
            $certificate = $csr ? openssl_csr_sign($csr, $issuer, $issuerKey, $days, $options, random_int(1, PHP_INT_MAX)) : false;
            if (! $certificate || ! openssl_x509_export($certificate, $pem)) {
                throw new RuntimeException('Cannot issue agent TLS certificate.');
            }

            return $pem;
        } finally {
            @unlink($path);
        }
    }

    private function writeKey(string $path, OpenSSLAsymmetricKey $key): void
    {
        if (! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Cannot export agent TLS key.');
        }
        $this->write($path, $pem);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read agent TLS identity.');
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
        try {
            if (@file_put_contents($temporary, $contents) !== strlen($contents) || ! @chmod($temporary, 0600) || ! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot persist agent TLS identity.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
