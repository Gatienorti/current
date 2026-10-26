<?php

namespace HorizonLens\Http;

use HorizonLens\HorizonAdapter;
use HorizonLens\QueueArchitecture;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Throwable;

final class QueuesController
{
    public function __invoke(HorizonAdapter $horizon, QueueArchitecture $architecture)
    {
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

        return view('horizon-lens::queues', ['section' => 'queues', 'supervisorTables' => $tables,
            'environment' => $environment, 'available' => $available, 'partial' => $source['partial'],
            'unmapped' => array_diff_key($source['jobs'], $matched)]);
    }
}
