<?php

return ['name' => 'Vehify', 'env' => 'local', 'debug' => true, 'url' => 'http://127.0.0.1:8097', 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'key' => 'base64:'.base64_encode(hash('sha256', 'horizon-lens-isolated-prototype-only', true)), 'cipher' => 'AES-256-CBC'];
