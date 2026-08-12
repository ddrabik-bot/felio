<?php

namespace App\Application\Scheduling;

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRatePersistenceService;
use App\Domain\Fx\FxRateProvider;
use App\Domain\MarketData\AvailabilityStatus;
use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\MarketData\MarketDataPersistenceService;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\MarketData\YahooFinanceInstrumentMapper;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class MarketDataRefreshService
{
    public function __construct(
        private MarketDataProvider $marketDataProvider,
        private FxRateProvider $fxRateProvider,
        private MarketDataPersistenceService $marketDataPersistence,
        private FxRatePersistenceService $fxRatePersistence,
        private YahooFinanceInstrumentMapper $mapper,
    ) {}

    public function refresh(DateTimeImmutable $now): void
    {
        $date = $now->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        $currencies = [];

        foreach ($this->activeInstruments() as $instrument) {
            $mapping = $this->mapper->map(new CanonicalInstrument($instrument));
            if ($mapping === null) {
                $this->record($date, 'instrument', $instrument, 'unavailable', 'provider_mapping_missing');
                continue;
            }

            try {
                $result = $this->marketDataProvider->fetch($mapping);
            } catch (\Throwable $exception) {
                $this->record($date, 'instrument', $instrument, 'unavailable', 'provider_exception');
                continue;
            }

            $this->record($date, 'instrument', $instrument, $result->status->value, $result->error?->diagnostic);
            if ($result->status !== AvailabilityStatus::Available || $result->snapshot === null) {
                continue;
            }

            $this->marketDataPersistence->persist($result, $date);
            if ($result->snapshot->quoteCurrency !== 'PLN') {
                $currencies[$result->snapshot->quoteCurrency] = true;
            }
        }

        foreach (array_keys($currencies) as $currency) {
            try {
                $result = $this->fxRateProvider->current($currency, $now);
            } catch (\Throwable $exception) {
                $this->record($date, 'fx', $currency, 'unavailable', 'provider_exception');
                continue;
            }

            $this->record($date, 'fx', $currency, $result->availability->value, $result->reason);
            if ($result->availability !== FxRateAvailability::Unavailable) {
                $this->fxRatePersistence->persist($result);
            }
        }
    }

    /** @return list<string> */
    private function activeInstruments(): array
    {
        return DB::table('portfolio_import_source_rows as rows')
            ->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')
            ->join('portfolio_accounts as accounts', 'accounts.id', '=', 'batches.portfolio_account_id')
            ->where('accounts.is_active', true)->where('rows.status', 'valid')->whereNotNull('rows.canonical_instrument')
            ->distinct()->orderBy('rows.canonical_instrument')->pluck('rows.canonical_instrument')->map(static fn ($value): string => (string) $value)->all();
    }

    private function record(string $date, string $type, string $subject, string $availability, ?string $reason): void
    {
        DB::table('market_data_refresh_outcomes')->upsert([['run_date' => $date, 'subject_type' => $type, 'subject' => $subject, 'availability' => $availability, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]], ['run_date', 'subject_type', 'subject'], ['availability', 'reason', 'updated_at']);
    }
}
