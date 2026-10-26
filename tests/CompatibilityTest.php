<?php

namespace Current\Tests;

use Current\HorizonAdapter;
use Current\Prototype\Jobs\PaymentJob;

final class CompatibilityTest extends TestCase
{
    public function test_installed_horizon_contracts_return_observed_data_without_mocked_repositories(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();

        $adapter = app(HorizonAdapter::class);
        $snapshot = $adapter->dashboard();
        foreach (['metrics', 'counts', 'masters', 'supervisors', 'wait', 'workload'] as $source) {
            $this->assertTrue($snapshot[$source]['available'], "Installed Horizon source is unreadable: {$source}");
        }
        $this->assertSame(1, (int) $snapshot['counts']['data']['recent']);
        foreach (['jobs' => PaymentJob::class, 'queues' => 'lens-demo'] as $kind => $expectedName) {
            $page = $adapter->page('metrics', $kind);
            $this->assertTrue($page['available']);
            $row = collect($page['rows'])->first(fn ($row) => $row['cells'][0] === $expectedName);
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row['cells'][1]);
            $this->assertStringEndsWith(' ms', $row['cells'][2]);
        }
    }
}
