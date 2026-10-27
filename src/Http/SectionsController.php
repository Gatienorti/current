<?php

namespace Current\Http;

use Current\HorizonAdapter;
use Illuminate\Http\Request;

final class SectionsController
{
    public function horizon(Request $request, string $section, string $metricKind = 'jobs')
    {
        if (\Current\ExecutionBackend::native()) {
            $filters = $section === 'batches' ? $request->validate([
                'query' => ['nullable', 'string', 'max:100'],
                'before_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/D'],
            ]) : [];
            return view('current::horizon-section', app(\Current\Native\Sections::class)->page($section, $metricKind, $filters));
        }
        return view('current::horizon-section', app(HorizonAdapter::class)->page($section, $metricKind));
    }

    public function planned(string $area)
    {
        return view('current::planned', ['area' => $area]);
    }
}
