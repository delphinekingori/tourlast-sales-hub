<?php

namespace App\Integrations\Mpesa;

use RuntimeException;

/**
 * M-Pesa refused a request or could not be reached. The message is safe to
 * show to staff.
 */
class MpesaException extends RuntimeException {}
