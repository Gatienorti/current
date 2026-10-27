<?php

namespace Current\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class InstallCollectorCommand extends Command
{
    protected $signature = 'current:collector-install {binary : Downloaded Linux collector binary} {--sha256= : Expected SHA-256 from the trusted release checksum file} {--force : Replace an existing collector after stopping it}';

    protected $description = 'Verify and install a downloaded Current telemetry collector.';

    public function handle(): int
    {
        $temporary = null;
        try {
            $source = $this->argument('binary');
            $expected = strtolower((string) $this->option('sha256'));
            if (PHP_OS_FAMILY !== 'Linux' || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! is_file($source) || filesize($source) > 64 * 1024 * 1024) {
                throw new \RuntimeException('Use a Linux binary and its trusted 64-character SHA-256 checksum.');
            }
            $target = config('current.telemetry.binary');
            if (file_exists($target) && ! $this->option('force')) {
                throw new \RuntimeException('Collector already exists. Stop it, then use --force to replace it.');
            }
            $directory = dirname($target);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true)) {
                throw new \RuntimeException('Could not create collector directory.');
            }
            $temporary = tempnam($directory, '.collector-');
            if (! copy($source, $temporary) || ! hash_equals($expected, hash_file('sha256', $temporary))) {
                throw new \RuntimeException('Collector checksum mismatch; nothing was installed.');
            }
            chmod($temporary, 0750);
            $check = new Process([$temporary, '--version']);
            $check->setTimeout(5);
            $check->run();
            $info = json_decode($check->getOutput(), true);
            $arch = match (php_uname('m')) {
                'x86_64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => null
            };
            if (! $check->isSuccessful() || ($info['name'] ?? null) !== 'current-collector' || ($info['protocol'] ?? null) !== 1 || ($info['identity_protocol'] ?? null) !== 2 || ($info['maintenance_protocol'] ?? null) !== 1 || ($info['os'] ?? null) !== 'linux' || ($info['arch'] ?? null) !== $arch) {
                throw new \RuntimeException('Collector platform or protocol is incompatible.');
            }
            if (! rename($temporary, $target)) {
                throw new \RuntimeException('Could not install collector.');
            }
            $temporary = null;
            $this->info('Collector verified and installed. Async recording remains opt-in.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Collector installation failed.');

            return self::FAILURE;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
