<?php

namespace Current\Http;

use Current\HorizonAdapter;
use Current\QueueArchitecture;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Throwable;

final class QueuesController
{
    public function __invoke(QueueArchitecture $architecture)
    {
        if (\Current\ExecutionBackend::native()) { return $this->native($architecture); }
        $horizon = app(HorizonAdapter::class);
        $environment = config('horizon.env') ?: app()->environment();
        $available = true;
        $tables = [];
        $source = $architecture->jobs();
        $matched = [];
        $live = $horizon->queueTopology();
        try {
            $plan = ProvisioningPlan::get('current-inventory');
            $options = collect($plan->parsed)->first(fn ($_, $key) => Str::is($key, $environment), collect());
            foreach ($options as $name => $option) {
                $queues = array_values(array_filter(explode(',', $option->queue)));
                $rows = [];
                $allocated = [];
                foreach ($queues as $queue) {
                    $classes = [];
                    foreach ($source['jobs'] as $class => $job) {
                        if (! $job['dynamic'] && $job['connection'] === $option->connection && in_array($queue, $job['queues'], true)) {
                            $classes[$job['class']] = $job;
                            $matched[$class] = true;
                        }
                    }
                    foreach (($live['data'][$option->connection.':'.$queue]['supervisors'] ?? []) as $supervisor) {
                        if ($supervisor['name'] === $name) {
                            $allocated[$supervisor['full_name']] = $supervisor['workers'];
                        }
                    }
                    $rows[] = ['queue' => $queue, 'connection' => $option->connection, 'classes' => array_values($classes)];
                }
                $auto = $option->balance === 'auto';
                $workers = $auto && (count($queues) > 1 || $option->minProcesses !== $option->maxProcesses)
                    ? (count($queues) > 1 ? 'Min '.$option->minProcesses.' / queue · Max '.$option->maxProcesses.' total' : $option->minProcesses.'–'.$option->maxProcesses.' workers')
                    : $option->maxProcesses.' '.($option->maxProcesses === 1 ? 'worker' : 'workers');
                $tables[] = ['name' => $name, 'connection' => $option->connection, 'queues' => $rows,
                    'workers' => $workers, 'balance' => $option->balance ?: 'disabled',
                    'allocated' => $live['available'] && $allocated ? array_sum($allocated) : null];
            }
        } catch (Throwable) {
            $available = false;
        }

        return view('current::queues', ['section' => 'queues', 'supervisorTables' => $tables,
            'environment' => $environment, 'available' => $available, 'partial' => $source['partial'],
            'unmapped' => array_diff_key($source['jobs'], $matched)]);
    }
    private function native(QueueArchitecture $architecture)
    {
        $source = $architecture->jobs(); $tables = []; $matched = []; $available = true;
        $health = app(\Current\Native\Supervisor::class)->snapshot();
        $live = collect($health['pools']);
        try {
            foreach (app(\Current\Native\Plan::class)->pools() as $pool) {
                $rows = [];
                foreach ($pool['queues'] as $queue) {
                    $classes = [];
                    foreach ($source['jobs'] as $class => $job) {
                        if (! $job['dynamic'] && $job['connection'] === $pool['connection'] && in_array($queue, $job['queues'], true)) {
                            $classes[] = $job; $matched[$class] = true;
                        }
                    }
                    $rows[] = ['queue' => $queue, 'connection' => $pool['connection'], 'classes' => $classes];
                }
                $balanced = in_array($pool['balance'], ['simple', 'auto'], true);
                $members = $live->filter(fn ($entry) => ($balanced ? ($entry['group'] ?? '') : $entry['name']) === $pool['name']);
                $allocation = $members->isNotEmpty() && $members->every(fn ($entry) => $entry['running'] !== null) ? $members->sum('running') : null;
                $tables[] = ['name' => $pool['name'], 'connection' => $pool['connection'], 'queues' => $rows,
                    'workers' => $balanced ? 'Min '.$pool['min_workers'].' / queue · Max '.$pool['max_workers'].' total' : $pool['workers'].' workers · Limits '.$pool['min_workers'].'–'.$pool['max_workers'], 'balance' => $pool['balance'],
                    'allocated' => $allocation, 'allocation_label' => 'Live workers'];
            }
        } catch (Throwable) { $available = false; }
        return view('current::queues', ['section' => 'queues', 'supervisorTables' => $tables, 'environment' => app()->environment(),
            'available' => $available, 'partial' => $source['partial'], 'unmapped' => array_diff_key($source['jobs'], $matched)]);
    }

}
