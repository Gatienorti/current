<?php

use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';
$app = bootAdaptLab();
$app->handleRequest(Request::capture());
