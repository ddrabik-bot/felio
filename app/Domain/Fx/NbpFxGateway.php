<?php

namespace App\Domain\Fx;

interface NbpFxGateway
{
    public function get(string $url): NbpFxHttpResponse;
}
