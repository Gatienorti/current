<?php

return ['driver' => 'file', 'lifetime' => 120, 'files' => storage_path('framework/sessions'), 'cookie' => 'lens_lab_session', 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'strict', 'lottery' => [2, 100]];
