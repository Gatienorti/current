<?php

namespace Current\Native;

/** Safe operator-facing maintenance failure; never contains backend error text. */
final class MaintenanceException extends \RuntimeException {}
