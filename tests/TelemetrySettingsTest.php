<?php

namespace Current\Tests;

use Current\Telemetry\Transport;
use RuntimeException;

final class TelemetrySettingsTest extends TestCase
{
    public function test_configuration_changes_revalidate_and_replace_the_connected_namespace(): void
    {
        $transport = new Transport;
        $first = $transport->settings();
        $client = $transport->client();
        $this->assertSame($client, $transport->client());
        $this->assertSame($first, $transport->settings());

        config()->set('current.telemetry.namespace', 'changed');
        $second = $transport->settings();
        $replacement = $transport->client();
        $this->assertNotSame($first['prefix'], $second['prefix']);
        $this->assertNotSame($client, $replacement);
        $this->assertSame($second['prefix'], $replacement->getOption(\Redis::OPT_PREFIX));

        config()->set('current.telemetry.namespace', 'invalid namespace');
        try {
            $transport->client();
            $this->fail('Cached client bypassed invalid configuration.');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid telemetry namespace.', $e->getMessage());
        }
        config()->set('current.telemetry.namespace', 'changed');
        $this->assertNotSame($replacement, $transport->client());
        $transport->disconnect();
    }

    public function test_changed_limits_apply_to_publication_and_disconnect_reconnects(): void
    {
        $transport = new Transport;
        $client = $transport->client();
        try {
            $transport->emit(['probe' => 1]);
            config()->set('current.telemetry.max_pending', 1);
            try {
                $transport->emit(['probe' => 2]);
                $this->fail('Cached limit allowed a second event.');
            } catch (RuntimeException $e) {
                $this->assertSame('Telemetry buffer full.', $e->getMessage());
            }
            $this->assertSame(1, $transport->client()->xLen('events:v2'));
            config()->set('current.telemetry.max_pending', 2);
            $transport->emit(['probe' => 3]);
            $this->assertSame(2, $transport->client()->xLen('events:v2'));
            $previous = $transport->client();
            $transport->disconnect();
            $this->assertNotSame($previous, $transport->client());
            $this->assertSame(2, $transport->client()->xLen('events:v2'));
        } finally {
            $client->del('events:v2', 'health');
            $transport->disconnect();
        }
    }

    public function test_cached_sql_settings_do_not_hide_unsupported_options_or_connection_changes(): void
    {
        $transport = new Transport;
        $transport->settings();
        foreach (['mysql', 'mariadb'] as $driver) {
            config()->set('database.connections.profile_sql', ['driver'=>$driver, 'host'=>'localhost', 'database'=>'fixture', 'username'=>'fixture']);
            config()->set('current.connection', 'profile_sql');
            $this->assertSame($driver, $transport->settings()['sql']['driver']);
            config()->set('database.connections.profile_sql.options', [\PDO::ATTR_TIMEOUT=>1]);
            try {
                $transport->settings();
                $this->fail('Cached settings hid unsupported PDO options.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('PDO option', $e->getMessage());
            }
        }
    }

    public function test_tls_files_are_rechecked_even_when_configuration_values_are_unchanged(): void
    {
        if (! defined('PDO::MYSQL_ATTR_SSL_CA')) { $this->markTestSkipped('PDO MySQL TLS constants unavailable.'); }
        $file = tempnam(sys_get_temp_dir(), 'current-settings-');
        try {
            config()->set('database.connections.lens', ['driver'=>'mysql', 'host'=>'localhost', 'database'=>'fixture', 'username'=>'fixture', 'options'=>[\PDO::MYSQL_ATTR_SSL_CA=>$file, \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true]]);
            $transport = new Transport;
            $this->assertSame($file, $transport->settings()['sql']['tls']['ca']);
            unlink($file);
            clearstatcache(true, $file);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('certificate files must be readable');
            $transport->settings();
        } finally {
            if (is_file($file)) { unlink($file); }
        }
    }
}
