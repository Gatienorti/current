<?php

use HorizonLens\LensServiceProvider;
use HorizonLens\Prototype\PrototypeServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

return [HorizonServiceProvider::class, LensServiceProvider::class, PrototypeServiceProvider::class];
