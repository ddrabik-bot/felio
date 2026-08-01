<?php

namespace App\Domain\MarketData;

final readonly class YahooFinanceMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private YahooFinanceGateway $gateway,
        private MarketDataRetryPolicy $retryPolicy = new MarketDataRetryPolicy,
    ) {}

    public function availability(ProviderInstrumentMapping $mapping): InstrumentAvailability
    {
        $result = $this->fetch($mapping);

        return match ($result->status) {
            AvailabilityStatus::Available => InstrumentAvailability::available($mapping),
            AvailabilityStatus::NoData => InstrumentAvailability::noData($mapping),
            AvailabilityStatus::Unavailable => InstrumentAvailability::unavailable($mapping, $result->error),
        };
    }

    public function fetch(ProviderInstrumentMapping $mapping): InstrumentMarketData
    {
        $attempt = 0;

        do {
            $attempt++;

            try {
                $payload = $this->gateway->fetch($mapping->symbol->value);

                return InstrumentMarketData::available($mapping, new MarketDataSnapshot(
                    $payload->exchange,
                    $payload->quoteCurrency,
                    $payload->dailyOhlc,
                    $payload->dividends,
                    $payload->splits,
                    $payload->retrievedAt,
                    $payload->sourceTimezone,
                    $payload->providerVersion,
                ));
            } catch (YahooFinanceGatewayException $exception) {
                $error = $this->classify($exception);
            } catch (\Throwable $exception) {
                $error = MarketDataError::from(MarketDataErrorCategory::InvalidResponse, $exception->getMessage());
            }
        } while ($this->retryPolicy->shouldRetry($error, $attempt));

        if ($error->category === MarketDataErrorCategory::InstrumentNotFound) {
            return InstrumentMarketData::noData($mapping, $error);
        }

        return InstrumentMarketData::unavailable($mapping, $error);
    }

    /**
     * @param  list<ProviderInstrumentMapping>  $mappings
     * @return list<InstrumentMarketData>
     */
    public function fetchMany(array $mappings): array
    {
        return array_map($this->fetch(...), $mappings);
    }

    private function classify(YahooFinanceGatewayException $exception): MarketDataError
    {
        $category = match (true) {
            $exception instanceof YahooFinanceTransportException => MarketDataErrorCategory::Transport,
            $exception instanceof YahooFinanceRateLimitException => MarketDataErrorCategory::RateLimited,
            $exception instanceof YahooFinanceNoDataException => MarketDataErrorCategory::InstrumentNotFound,
            default => MarketDataErrorCategory::InvalidResponse,
        };

        return MarketDataError::from($category, $exception->getMessage());
    }
}
