<?php

namespace App\Trading\Exceptions;

use RuntimeException;

/**
 * Any failure talking to an exchange: network error, API error response,
 * rejected order, unknown symbol. The message is safe to log (never contains
 * API secrets).
 */
class ExchangeException extends RuntimeException
{
}
