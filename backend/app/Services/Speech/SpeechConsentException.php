<?php

namespace App\Services\Speech;

/**
 * Thrown when audio would be stored for a learner who has not consented.
 *
 * Voice is biometric-adjacent personal data, so consent is a precondition of
 * storage rather than a setting checked afterwards (spec 45).
 */
class SpeechConsentException extends \RuntimeException
{
    public function __construct(string $message = 'Speech recording requires explicit consent.')
    {
        parent::__construct($message);
    }
}
