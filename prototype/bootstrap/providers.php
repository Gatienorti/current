<?php

use Current\CurrentServiceProvider;
use Current\Prototype\PrototypeServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

return [HorizonServiceProvider::class, CurrentServiceProvider::class, PrototypeServiceProvider::class];
