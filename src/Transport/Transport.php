<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

interface Transport
{
    /**
     * Ship one envelope. Implementations must never throw.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function send(array $envelope): void;
}
