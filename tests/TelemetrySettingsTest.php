<?php

namespace Current\Tests;

use Current\Telemetry\Transport;
use RuntimeException;

final class TelemetrySettingsTest extends TestCase
{
    public function test_collect_rejects_a_binary_without_postgres_support_before_starting_it(): void
    {
        config()->set('database.connections.profile_pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'fixture', 'username' => 'fixture', 'sslmode' => 'disable']);
        config()->set('current.connection', 'profile_pg');
        config()->set('current.telemetry.driver', 'redis');
        $binary = tempnam(sys_get_temp_dir(), 'current-no-postgres-');
        file_put_contents($binary, '#!'.PHP_BINARY."\n<?php echo json_encode(['identity_protocol'=>2,'maintenance_protocol'=>1,'monitored_retention'=>1,'redis_tls'=>1]);\n");
        chmod($binary, 0700);
        config()->set('current.telemetry.binary', $binary);
        try {
            $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('current:collect', ['--once' => true]));
            $this->assertStringContainsString('Upgrade the collector binary for PostgreSQL support', \Illuminate\Support\Facades\Artisan::output());
        } finally { unlink($binary); }
    }

    public function test_postgres_requires_explicit_transport_and_rechecks_certificate_files(): void
    {
        $connection = ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'fixture', 'username' => 'fixture', 'sslmode' => 'disable'];
        config()->set('database.connections.profile_pg', $connection);
        config()->set('current.connection', 'profile_pg');
        $transport = new Transport;
        $settings = $transport->settings()['sql'];
        $this->assertSame('pgsql', $settings['driver']);
        $this->assertSame(5432, $settings['port']);
        $this->assertFalse($settings['tls']['enabled']);
        foreach ([['sslmode' => 'prefer'], ['sslmode' => 'require'], ['sslmode' => 'verify-ca'], ['search_path' => 'tenant'], ['options' => [\PDO::ATTR_TIMEOUT => 1]], ['read' => ['host' => 'replica']]] as $override) {
            config()->set('database.connections.profile_pg', array_replace($connection, $override));
            try { $transport->settings(); $this->fail('Unsupported PostgreSQL settings were accepted.'); }
            catch (RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
        $file = tempnam(sys_get_temp_dir(), 'current-pg-ca-'); file_put_contents($file, 'test-only placeholder');
        try {
            config()->set('database.connections.profile_pg', array_replace($connection, ['sslmode' => 'verify-full', 'sslrootcert' => $file]));
            $this->assertSame(realpath($file), $transport->settings()['sql']['tls']['ca']);
            unlink($file);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('certificate files must be readable');
            $transport->settings();
        } finally { if (is_file($file)) { unlink($file); } }
    }

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
