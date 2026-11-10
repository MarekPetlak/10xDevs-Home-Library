<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a concurrent request created the same author or tag first.
 */
final class BookConflictException extends \DomainException
{
}
