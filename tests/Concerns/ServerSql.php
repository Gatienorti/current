<?php

namespace Current\Tests\Concerns;

trait ServerSql
{
    protected function serverConnection(): array
    {
        $driver = getenv('CURRENT_ASYNC_DB_DRIVER') ?: 'mysql';
        $postgres = $driver === 'pgsql';
        $connection = ['driver' => $driver, 'host' => getenv('CURRENT_ASYNC_DB_HOST'),
            'port' => (int) (getenv('CURRENT_ASYNC_DB_PORT') ?: ($postgres ? 5432 : 3306)),
            'database' => 'current_async_test', 'username' => $postgres ? 'postgres' : 'root',
            'password' => getenv('CURRENT_ASYNC_DB_PASSWORD'), 'charset' => $postgres ? 'utf8' : 'utf8mb4',
            'prefix' => '', 'strict' => true];
        if ($postgres) { $connection += ['search_path' => 'public', 'sslmode' => 'disable']; }
        else { $connection['collation'] = 'utf8mb4_unicode_ci'; }
        if ($tls = getenv('CURRENT_ASYNC_TLS_DIR')) {
            if ($postgres) {
                $connection = array_replace($connection, ['sslmode' => 'verify-full', 'sslrootcert' => $tls.'/ca.pem', 'sslcert' => $tls.'/client.pem', 'sslkey' => $tls.'/client-key.pem']);
            } else {
                $connection['options'] = [\PDO::MYSQL_ATTR_SSL_CA => $tls.'/ca.pem', \PDO::MYSQL_ATTR_SSL_CERT => $tls.'/client.pem', \PDO::MYSQL_ATTR_SSL_KEY => $tls.'/client-key.pem', \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
            }
        }
        return $connection;
    }

    protected function serverDatabaseName(): string
    {
        $quote = getenv('CURRENT_ASYNC_DB_DRIVER') === 'pgsql' ? '"' : '`';
        return $quote.$this->serverDatabase.$quote;
    }

    protected function postgres(): bool { return $this->store()->connection()->getDriverName() === 'pgsql'; }

    protected function postgresTrigger(string $name, string $table, string $event, string $body): void
    {
        $this->store()->connection()->unprepared('CREATE FUNCTION '.$name.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$body.' RETURN NEW; END $$; CREATE TRIGGER '.$name.' BEFORE '.$event.' ON '.$table.' FOR EACH ROW EXECUTE FUNCTION '.$name.'()');
    }

    protected function dropTestTrigger(string $name, string $table): void
    {
        $db = $this->store()->connection();
        $db->unprepared('DROP TRIGGER '.$name.($this->postgres() ? ' ON '.$table : ''));
        if ($this->postgres()) { $db->unprepared('DROP FUNCTION '.$name.'()'); }
    }
}
