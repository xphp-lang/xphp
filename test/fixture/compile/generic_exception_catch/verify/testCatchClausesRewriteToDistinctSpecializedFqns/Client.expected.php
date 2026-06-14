<?php

declare (strict_types=1);
namespace App\GenericExceptionCatch;

use App\GenericExceptionCatch\Errors\HttpError;
use App\GenericExceptionCatch\Models\Forbidden;
use App\GenericExceptionCatch\Models\NotFound;
class Client
{
    public function raise(string $which): void
    {
        if ($which === 'forbidden') {
            throw new \XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_06f918bacae3fd07cbcf951547a0b2cd134791ea546f4e217ed92646c23312fb('api key revoked');
        }
        throw new \XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_ecf2ea0572da7e267540a6732b45a57cd872d497de0ce6e93c5606d12dc408b8('missing');
    }
    /**
     * The generic type discriminates: a thrown `HttpError<Forbidden>` must
     * fall through the textually-first `HttpError<NotFound>` arm and land on
     * the `HttpError<Forbidden>` arm. Catching by generic specialization is
     * the whole point of the fixture.
     */
    public function classify(string $which): string
    {
        try {
            $this->raise($which);
        } catch (\XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_ecf2ea0572da7e267540a6732b45a57cd872d497de0ce6e93c5606d12dc408b8 $e) {
            return 'not-found:' . $e->detail;
        } catch (\XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_06f918bacae3fd07cbcf951547a0b2cd134791ea546f4e217ed92646c23312fb $e) {
            return 'forbidden:' . $e->detail;
        }
        return 'none';
    }
    /**
     * Bare `catch (HttpError $e)` (no type args) catches any `HttpError<*>`
     * specialization via the marker interface every specialization implements.
     */
    public function catchAny(string $which): string
    {
        try {
            $this->raise($which);
        } catch (HttpError $e) {
            return $e::class;
        }
        return 'none';
    }
    /**
     * A union of two specializations matches when the thrown error is either.
     */
    public function catchUnion(string $which): string
    {
        try {
            $this->raise($which);
        } catch (\XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_ecf2ea0572da7e267540a6732b45a57cd872d497de0ce6e93c5606d12dc408b8|\XPHP\Generated\App\GenericExceptionCatch\Errors\HttpError\T_06f918bacae3fd07cbcf951547a0b2cd134791ea546f4e217ed92646c23312fb $e) {
            return 'union:' . $e->detail;
        }
        return 'none';
    }
}
