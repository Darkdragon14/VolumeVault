<?php

namespace Tests\Unit;

use App\Models\NotificationChannel;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ShoutrrrUrlBuilderTest extends TestCase
{
    private ShoutrrrUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ShoutrrrUrlBuilder;
    }

    public function test_discord_extracts_id_and_token_from_webhook_url(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_DISCORD, [
            'webhook_url' => 'https://discord.com/api/webhooks/123456/secret-token',
        ]);

        $this->assertSame('discord://secret-token@123456?username=VolumeVault&splitLines=No', $url);
    }

    public function test_discord_honours_custom_username(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_DISCORD, [
            'webhook_url' => 'https://discord.com/api/webhooks/123456/secret-token',
            'username' => 'Backups Bot',
        ]);

        $this->assertStringContainsString('username=Backups%20Bot', $url);
    }

    public function test_discord_rejects_a_url_without_a_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_DISCORD, ['webhook_url' => 'https://discord.com']);
    }

    public function test_discord_rejects_a_url_missing_id_or_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_DISCORD, ['webhook_url' => 'https://discord.com/api/webhooks/123456']);
    }

    public function test_telegram_builds_url_from_token_and_chats(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_TELEGRAM, [
            'token' => '987:abc',
            'chats' => '@channel',
        ]);

        $this->assertSame('telegram://987%3Aabc@telegram?chats=%40channel', $url);
    }

    public function test_telegram_requires_token_and_chats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_TELEGRAM, ['token' => 'only-token']);
    }

    public function test_ntfy_defaults_host_and_scheme(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_NTFY, ['topic' => 'alerts']);

        $this->assertSame('ntfy://ntfy.sh/alerts?scheme=https&title=VolumeVault', $url);
    }

    public function test_ntfy_includes_basic_auth_when_credentials_are_present(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_NTFY, [
            'topic' => 'alerts',
            'host' => 'https://push.example.com/',
            'username' => 'user',
            'password' => 'p@ss',
        ]);

        $this->assertSame('ntfy://user:p%40ss@push.example.com/alerts?scheme=https&title=VolumeVault', $url);
    }

    public function test_ntfy_requires_a_topic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_NTFY, ['topic' => '']);
    }

    public function test_gotify_builds_url_from_host_and_token(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_GOTIFY, [
            'host' => 'https://gotify.example.com/',
            'token' => 'app-token',
        ]);

        $this->assertSame('gotify://gotify.example.com/app-token?title=VolumeVault&priority=5', $url);
    }

    public function test_gotify_requires_host_and_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_GOTIFY, ['host' => 'https://gotify.example.com']);
    }

    public function test_smtp_builds_url_with_auth_and_recipients(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_SMTP, [
            'host' => 'smtp.example.com',
            'from' => 'vault@example.com',
            'to' => 'ops@example.com',
            'username' => 'vault@example.com',
            'password' => 'secret',
            'port' => 2525,
        ]);

        $this->assertStringStartsWith('smtp://vault%40example.com:secret@smtp.example.com:2525/?', $url);
        $this->assertStringContainsString('from=vault%40example.com', $url);
        $this->assertStringContainsString('to=ops%40example.com', $url);
    }

    public function test_smtp_defaults_to_port_587_without_auth(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_SMTP, [
            'host' => 'smtp.example.com',
            'from' => 'vault@example.com',
            'to' => 'ops@example.com',
        ]);

        $this->assertStringStartsWith('smtp://smtp.example.com:587/?', $url);
    }

    public function test_smtp_unencrypted_adds_encryption_none_and_disables_starttls(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_SMTP, [
            'host' => 'smtp.example.com',
            'from' => 'vault@example.com',
            'to' => 'ops@example.com',
            'port' => 25,
            'unencrypted' => true,
        ]);

        $this->assertStringContainsString('encryption=None', $url);
        $this->assertStringContainsString('usestarttls=No', $url);
    }

    public function test_smtp_stays_encrypted_by_default(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_SMTP, [
            'host' => 'smtp.example.com',
            'from' => 'vault@example.com',
            'to' => 'ops@example.com',
        ]);

        $this->assertStringNotContainsString('encryption=', $url);
        $this->assertStringNotContainsString('usestarttls=', $url);
    }

    public function test_smtp_unencrypted_false_does_not_add_encryption(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_SMTP, [
            'host' => 'smtp.example.com',
            'from' => 'vault@example.com',
            'to' => 'ops@example.com',
            'unencrypted' => false,
        ]);

        $this->assertStringNotContainsString('encryption=', $url);
    }

    public function test_smtp_requires_host_from_and_to(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_SMTP, ['host' => 'smtp.example.com', 'from' => 'vault@example.com']);
    }

    public function test_advanced_passes_through_a_valid_shoutrrr_url(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_ADVANCED, [
            'url' => 'slack://token@channel',
        ]);

        $this->assertSame('slack://token@channel', $url);
    }

    public function test_advanced_rejects_a_value_that_is_not_a_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_ADVANCED, ['url' => 'not-a-url']);
    }

    public function test_webhook_builds_json_map_of_generic_urls_per_event(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_WEBHOOK, [
            'start_url' => 'https://hc-ping.com/uuid/start',
            'success_url' => 'https://hc-ping.com/uuid',
            'fail_url' => 'https://hc-ping.com/uuid/fail',
        ]);

        $this->assertSame([
            'start' => 'generic+https://hc-ping.com/uuid/start',
            'success' => 'generic+https://hc-ping.com/uuid',
            'fail' => 'generic+https://hc-ping.com/uuid/fail',
        ], json_decode($url, true));
    }

    public function test_webhook_keeps_only_the_filled_urls(): void
    {
        $url = $this->builder->build(NotificationChannel::SERVICE_WEBHOOK, [
            'success_url' => 'http://example.test/hook',
            'fail_url' => '',
        ]);

        $this->assertSame(['success' => 'generic+http://example.test/hook'], json_decode($url, true));
    }

    public function test_webhook_rejects_a_non_http_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_WEBHOOK, ['success_url' => 'ftp://example.test/hook']);
    }

    public function test_webhook_requires_at_least_one_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(NotificationChannel::SERVICE_WEBHOOK, ['start_url' => '', 'success_url' => '', 'fail_url' => '']);
    }

    public function test_webhook_rejects_malformed_urls(): void
    {
        // Scheme-prefix checks alone would accept these; only structural validation
        // (FILTER_VALIDATE_URL) rejects an empty host, a query-only URL or whitespace.
        foreach (['https://', 'https://?x=1', 'https://exa mple.com', 'http://', 'not-a-url'] as $bad) {
            try {
                $this->builder->build(NotificationChannel::SERVICE_WEBHOOK, ['success_url' => $bad]);
                $this->fail("Expected an invalid webhook URL to be rejected: {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unsupported_service_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build('myspace', []);
    }
}
