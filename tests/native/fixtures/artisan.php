#!/usr/bin/env php
<?php
$app = require __DIR__.'/boot.php';
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
