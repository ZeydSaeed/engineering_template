<?php

namespace App\Platform\Identity\Contracts;

/**
 * Implemented by the user model so the platform can require a second
 * factor without knowing how it is stored (ADR-0015).
 */
interface HasSecondFactor
{
    /**
     * A confirmed authenticator app or at least one passkey.
     */
    public function hasSecondFactor(): bool;
}
