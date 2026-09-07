<?php
namespace App\Services;

/** A deliberately public validation message, never a DB/driver diagnostic. */
final class LedgerInputException extends \RuntimeException
{
    public function __construct(public readonly string $publicMessage, public readonly int $httpStatus = 422)
    {
        parent::__construct($publicMessage);
    }
}
