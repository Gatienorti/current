<?php

namespace Current\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'current:install {--force : Run migrations in production}';

    protected $description = 'Prepare Current history storage and run only its migrations.';

    public function handle(): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Use --force when installing Current in production.');

            return self::FAILURE;
        }
        $connection = config('current.connection');
        $database = config("database.connections.{$connection}");
        if (! is_array($database)) {
            $this->error('Configure CURRENT_CONNECTION as a valid dedicated database connection.');

            return self::FAILURE;
        }
        if (($database['driver'] ?? '') === 'sqlite') {
            $path = $database['database'] ?? '';
            if ($path === ':memory:' || $path === '') {
                $this->error('Current needs persistent history storage.');

                return self::FAILURE;
            }
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            if (! is_file($path)) {
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    return self::FAILURE;
                }
                fclose($handle);
                chmod($path, 0600);
            }
        }
        if (! is_file(config_path('current.php'))) {
            copy(__DIR__.'/../../config/current.php', config_path('current.php'));
            $this->info('Published config/current.php.');
        }
        $exit = $this->call('migrate', [
            '--database' => $connection,
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true, '--force' => (bool) $this->option('force'),
        ]);
        if ($exit !== self::SUCCESS) {
            return $exit;
        }
        $this->info('Current is ready at /'.trim(config('current.path'), '/').'.');
        $this->line('Access requires an authenticated user allowed by your Horizon gate.');
        $this->line('Run Laravel’s scheduler for hourly pruning; restart long-lived queue workers after installation.');
        $this->line('Job data is redacted before storage. Review config/current.php for capture and tag settings.');

        return self::SUCCESS;
    }
}
