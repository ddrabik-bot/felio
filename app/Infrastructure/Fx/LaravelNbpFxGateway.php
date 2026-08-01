<?php

namespace App\Infrastructure\Fx;

use App\Domain\Fx\NbpFxGateway;
use App\Domain\Fx\NbpFxHttpResponse;
use App\Domain\Fx\NbpFxTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class LaravelNbpFxGateway implements NbpFxGateway
{
    public function get(string $url): NbpFxHttpResponse
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('Felio-NBP-FX/1.0')
                ->timeout(15)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new NbpFxTransportException($exception->getMessage(), previous: $exception);
        }

        return new NbpFxHttpResponse($response->status(), $response->body(), $response->headers());
    }
}
