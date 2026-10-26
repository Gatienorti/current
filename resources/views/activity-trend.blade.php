@php $max = max(1, max(array_column($series, 'total'))); $step = 600 / count($series); @endphp
<div class="activity-trend"><div class="activity-legend"><span>Observed volume</span><span class="failure-legend">Failures</span></div>
<svg viewBox="0 0 600 130" role="img" aria-label="{{ $trendLabel }} observed volume and failures over time. Exact counts are in the expandable trend data table.">
@foreach($series as $i => $point)
<g><title>{{ gmdate('M j H:i', (int)$point['at']) }} UTC: {{ $point['total'] }} observed, {{ $point['failures'] }} failures{{ $point['duration'] !== null ? ', average '.number_format($point['duration'], 1).' ms' : '' }}</title><rect class="activity-bar" x="{{ $i * $step + 2 }}" y="{{ 115 - $point['total'] / $max * 100 }}" width="{{ max(1, $step - 4) }}" height="{{ $point['total'] / $max * 100 }}" rx="2"/><rect class="activity-failed-bar" x="{{ $i * $step + 2 }}" y="{{ 115 - $point['failures'] / $max * 100 }}" width="{{ max(1, $step - 4) }}" height="{{ $point['failures'] / $max * 100 }}" rx="2"/></g>
@endforeach
<line x1="0" y1="116" x2="600" y2="116" stroke="currentColor" opacity=".15"/>
</svg><div class="activity-axis"><span>{{ gmdate('M j H:i', (int)$series[0]['at']) }}</span><span>Peak {{ $max === 1 && array_sum(array_column($series, 'total')) === 0 ? 0 : $max }} / bucket · UTC</span><span>{{ gmdate('M j H:i', (int)$series[count($series)-1]['at']) }}</span></div>
@unless($authorization)
@php $hasDuration = count(array_filter($series, fn ($point) => $point['duration'] !== null)) > 0; $maxDuration = max(1, max(array_map(fn ($point) => $point['duration'] ?? 0, $series))); @endphp
<div class="activity-legend" style="margin-top:16px"><span>Average observed duration</span><span>{{ $hasDuration ? 'Peak '.number_format(max(array_map(fn ($point) => $point['duration'] ?? 0, $series)), 1).' ms' : 'No duration observations' }}</span></div>
<svg viewBox="0 0 600 65" role="img" aria-label="{{ $trendLabel }} average duration per bucket, excluding missing durations">
@foreach($series as $i => $point)
@if($point['duration'] !== null)
<g><title>{{ gmdate('M j H:i', (int)$point['at']) }} UTC: {{ number_format($point['duration'], 1) }} ms average</title><rect class="activity-bar" x="{{ $i * $step + 2 }}" y="{{ 55 - $point['duration'] / $maxDuration * 45 }}" width="{{ max(1, $step - 4) }}" height="{{ max(1, $point['duration'] / $maxDuration * 45) }}" rx="2"/></g>
@endif
@endforeach
</svg>
@endunless
<details><summary>Trend data{{ !$authorization ? ' & average duration' : '' }}</summary><div class="table-wrap"><table><thead><tr><th>Bucket start · UTC</th><th>Observed</th><th>Failures</th>@unless($authorization)<th>Average duration</th>@endunless</tr></thead><tbody>@foreach($series as $point)<tr><td>{{ gmdate('M j H:i', (int)$point['at']) }}</td><td>{{ $point['total'] }}</td><td>{{ $point['failures'] }}</td>@unless($authorization)<td>{{ $point['duration'] !== null ? number_format($point['duration'], 1).' ms' : '—' }}</td>@endunless</tr>@endforeach</tbody></table></div></details></div>
