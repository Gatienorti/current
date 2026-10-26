<?php

namespace Current\Http;

use Current\HorizonAdapter;

final class SectionsController
{
    public function horizon(string $section, HorizonAdapter $adapter, string $metricKind = 'jobs')
    {
        return view('current::horizon-section', $adapter->page($section, $metricKind));
    }

    public function planned(string $area)
    {
        return view('current::planned', ['area' => $area]);
    }
}
