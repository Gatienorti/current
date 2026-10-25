<?php

use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/bootstrap.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->handleRequest(Request::capture());
