<?php

namespace App\Application\Portfolio;

use App\Domain\Portfolio\PortfolioValuationReadRepository;
use App\Domain\Valuation\Decimal;
use App\Domain\Valuation\ValuationInput;
use App\Domain\Valuation\ValuationService;
use DateTimeImmutable;
use OverflowException;

final readonly class PortfolioValuationService
{
    public function __construct(
        private PortfolioValuationReadRepository $repository,
        private ValuationService $valuationService,
    ) {}

    public function read(DateTimeImmutable $valuationDate, int $portfolioAccountId): PortfolioValuationReadModel
    {
        $rows = [];
        $totalPlnGrosze = 0;

        foreach ($this->repository->positionsAsOf($valuationDate, $portfolioAccountId) as $position) {
            $price = $this->repository->priceFor($position->canonicalInstrument, $valuationDate);
            $fxRate = $price->currency === 'PLN' ? null : $this->repository->fxRateFor($price->currency, $valuationDate);
            $result = $this->valuationService->value(new ValuationInput($position->quantity, $price, $fxRate));
            $plnGrosze = $result->marketValuePln === null ? null : Decimal::plnToGrosze($result->marketValuePln);

            $rows[] = new PortfolioValuationRow($position->id, $position->accountId, $position->canonicalInstrument, $position->quantity, $position->averageCostPlnGrosze, $price, $fxRate, $result->availability, $plnGrosze, $result->diagnostics);

            if ($plnGrosze !== null) {
                $totalPlnGrosze = self::checkedAddGrosze($totalPlnGrosze, $plnGrosze);
            }
        }

        return new PortfolioValuationReadModel($valuationDate, $rows, $totalPlnGrosze);
    }

    private static function checkedAddGrosze(int $total, int $value): int
    {
        if (($value > 0 && $total > PHP_INT_MAX - $value) || ($value < 0 && $total < PHP_INT_MIN - $value)) {
            throw new OverflowException('Portfolio PLN grosze total exceeds PHP integer range.');
        }

        return $total + $value;
    }
}
