<?php

namespace App\Platform\Http\Outbound;

use RuntimeException;

/**
 * A service failed repeatedly; calls are refused until its cool-down ends.
 */
class CircuitOpen extends RuntimeException {}
