@if(is_array($jsonValue) || is_object($jsonValue))
    @php($jsonEntries = is_object($jsonValue) ? get_object_vars($jsonValue) : $jsonValue)
    <details class="json-branch" @if($jsonKey === null) open @endif>
        <summary>@include('horizon-lens::icon', ['iconName' => 'chevron-right', 'iconClass' => 'json-chevron'])@if($jsonKey !== null)<span class="json-key">{{ json_encode((string) $jsonKey, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>: @endif<span class="json-structure">{{ is_object($jsonValue) ? '{' : '[' }} {{ count($jsonEntries) }} {{ is_object($jsonValue) ? 'keys' : 'items' }} {{ is_object($jsonValue) ? '}' : ']' }}</span></summary>
        <div class="json-children">@foreach($jsonEntries as $entryKey => $entryValue)@include('horizon-lens::json-node', ['jsonValue' => $entryValue, 'jsonKey' => $entryKey])@endforeach</div>
    </details>
@else
    <div class="json-value">@if($jsonKey !== null)<span class="json-key">{{ json_encode((string) $jsonKey, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>: @endif<span class="json-scalar {{ is_string($jsonValue) ? 'json-string' : 'json-literal' }} {{ $jsonValue === '[REDACTED]' ? 'json-redacted' : '' }}">{{ json_encode($jsonValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span></div>
@endif
