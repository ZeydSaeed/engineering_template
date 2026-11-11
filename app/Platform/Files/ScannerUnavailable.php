<?php

namespace App\Platform\Files;

use RuntimeException;

/**
 * The scanner could not give a verdict. Never treated as clean.
 */
class ScannerUnavailable extends RuntimeException {}
