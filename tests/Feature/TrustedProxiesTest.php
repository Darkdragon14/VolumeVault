<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    public function test_trusted_proxy_https_header_generates_https_vite_assets_without_trusting_forwarded_host(): void
    {
        $this->setTrustedProxies('172.18.0.0/16');
        $this->refreshApplication();
        $this->artisan('migrate');

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders([
                'X-Forwarded-Host' => 'evil.example.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('http://volumevault.example.com/onboarding');

        $response->assertOk();
        $response->assertSee('href="https://volumevault.example.com/build/assets/', false);
        $response->assertSee('src="https://volumevault.example.com/build/assets/', false);
        $response->assertDontSee('https://evil.example.com/build/assets/', false);
    }

    public function test_untrusted_proxy_https_header_does_not_change_vite_asset_scheme(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders([
                'X-Forwarded-Host' => 'volumevault.example.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('http://volumevault.example.com/onboarding');

        $response->assertOk();
        $response->assertSee('href="http://volumevault.example.com/build/assets/', false);
        $response->assertSee('src="http://volumevault.example.com/build/assets/', false);
    }

    private function setTrustedProxies(string $trustedProxies): void
    {
        putenv("TRUSTED_PROXIES={$trustedProxies}");
        $_ENV['TRUSTED_PROXIES'] = $trustedProxies;
        $_SERVER['TRUSTED_PROXIES'] = $trustedProxies;
    }
}
