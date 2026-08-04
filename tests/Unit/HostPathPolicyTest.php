<?php

namespace Tests\Unit;

use App\Services\BackupSources\HostPathPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class HostPathPolicyTest extends TestCase
{
    public function test_empty_allowlist_rejects_every_path_fail_closed(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $policy = new HostPathPolicy;

        $this->assertFalse($policy->isAllowed('/srv/data'));
        $this->assertFalse($policy->isAllowed('/etc'));

        $message = $policy->validationError('/srv/data');
        $this->assertNotNull($message);
        $this->assertStringContainsString('VOLUMEVAULT_HOST_PATH_ALLOWLIST', (string) $message);
    }

    public function test_path_outside_the_configured_prefixes_is_rejected(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv/data']]);
        $policy = new HostPathPolicy;

        $this->assertFalse($policy->isAllowed('/etc'));
        $this->assertFalse($policy->isAllowed('/srv/data-secret')); // prefix must end on a boundary
        $this->assertNotNull($policy->validationError('/etc'));
    }

    public function test_path_inside_a_configured_prefix_is_allowed(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv/data']]);
        $policy = new HostPathPolicy;

        $this->assertTrue($policy->isAllowed('/srv/data'));
        $this->assertTrue($policy->isAllowed('/srv/data/nested'));
        $this->assertNull($policy->validationError('/srv/data/nested'));
    }

    public function test_assert_valid_at_runtime_throws_for_a_path_outside_the_allowlist(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv/data']]);
        $policy = new HostPathPolicy;

        $this->expectException(InvalidArgumentException::class);
        $policy->assertValidAtRuntime('/etc');
    }

    public function test_assert_valid_at_runtime_rejects_a_symlink_resolving_outside_the_allowlist(): void
    {
        $base = sys_get_temp_dir().'/volumevault-hostpath-'.uniqid();
        $allowed = $base.'/allowed';
        $secret = $base.'/secret';
        $link = $allowed.'/link';

        mkdir($allowed, 0700, true);
        mkdir($secret, 0700, true);
        symlink($secret, $link);

        // Only the "allowed" subtree is allowlisted (raw + canonical form, so a
        // legit path inside it would pass); the symlink target is a sibling that
        // escapes it.
        config([
            'volumevault.docker_host' => 'tcp://docker-proxy.example.test:2375',
            'volumevault.host_path_allowlist' => array_unique([$allowed, realpath($allowed)]),
        ]);
        $policy = new HostPathPolicy;

        try {
            // The configured string is inside the allowlist, but realpath() resolves
            // the symlink to the secret directory outside it — the run-time check
            // must catch this TOCTOU-style swap and reject.
            $this->expectException(InvalidArgumentException::class);
            $policy->assertValidAtRuntime($link);
        } finally {
            unlink($link);
            rmdir($secret);
            rmdir($allowed);
            rmdir($base);
        }
    }

    public function test_unavailable_host_path_remains_lexically_valid(): void
    {
        $allowed = sys_get_temp_dir().'/volumevault-unavailable-hostpath-'.uniqid();
        $path = $allowed.'/data';

        config([
            'volumevault.docker_host' => 'tcp://docker.example.test:2375',
            'volumevault.host_path_allowlist' => [$allowed],
        ]);

        $this->assertFalse(realpath($path));
        (new HostPathPolicy)->assertValidAtRuntime($path);
        $this->addToAssertionCount(1);
    }
}
