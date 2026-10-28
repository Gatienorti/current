@if(config('current.native.registry.enabled', true) === true)
<div data-live-region="native-inventory">
@php
$instances = \Current\Native\Registry::withoutLocal($nativeRegistry['instances'], $nativeHealth);
@endphp
@if(!$nativeRegistry['available'] || $instances || !$nativeHealth['available'] || $nativeRegistry['invalid'])
<section class="panel">
<div class="panel-heading"><h2>Worker instances</h2></div>
@if(!$nativeRegistry['available'])
<div class="empty-state"><h3>Shared worker status unavailable</h3></div>
@elseif(!$instances)
<div class="empty-state"><h3>No recent worker instances</h3></div>
@else
<div class="table-scroll"><table><thead><tr><th>Instance / host</th><th>State</th><th>Pool</th><th>Live processes</th><th>Allocation</th><th>Limits</th></tr></thead><tbody>
@foreach($instances as $instance)
@foreach($instance['pools'] as $pool)
<tr>
<td>@if($loop->first)<strong>{{ $instance['instance'] }}</strong><div class="subtle">{{ $instance['host'] }}</div>@endif</td>
<td>@if($loop->first){{ ucfirst($instance['state']) }}@endif</td>
<td>{{ ($pool['group'] ?? '') ?: $pool['name'] }}@if($pool['queue'] ?? '')<div class="subtle">{{ $pool['queue'] }}</div>@endif</td><td>{{ $pool['running'] ?? 'Unknown' }}</td><td>{{ $pool['workers'] }}</td><td>{{ $pool['min'] }}–{{ $pool['max'] }}</td>
</tr>
@endforeach
@endforeach
</tbody></table></div>
@endif
@if($nativeRegistry['invalid'])<div class="panel-footnote">Some worker observations could not be read.</div>@endif
</section>
@endif
</div>
@endif
