<?php

namespace HorizonLens\Http;

use HorizonLens\HorizonAdapter;

final class SectionsController
{
    public function horizon(string $section, HorizonAdapter $adapter, string $metricKind = 'jobs')
    {
        return view('horizon-lens::horizon-section', $adapter->page($section, $metricKind));
    }

    public function planned(string $area)
    {
        return view('horizon-lens::planned', ['area' => $area]);
    }
}
