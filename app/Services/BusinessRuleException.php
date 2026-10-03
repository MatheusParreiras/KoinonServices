<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule (area already
 * booked, package already picked up, wrong pickup code...).
 *
 * Thrown by services, caught by controllers and shown to the user. The
 * message must therefore be safe to display. $status is the HTTP code for
 * JSON clients: 409 for conflicts with the current state, 422 for invalid data.
 */
final class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly ?string $field = null
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** The form field the error belongs to, or null for a general error. */
    public function field(): ?string
    {
        return $this->field;
    }
}
