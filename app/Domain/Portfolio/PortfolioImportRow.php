<?php

namespace App\Domain\Portfolio;

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Valuation\Decimal;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class PortfolioImportRow
{
    /** @param array<string, mixed> $rawValues */
    private function __construct(
        public string $sourceRowIdentity,
        public SourceRowStatus $status,
        public ?CanonicalInstrument $instrument,
        public ?string $quantity,
        public ?int $averageCostPlnGrosze,
        public DateTimeImmutable $asOf,
        public array $rawValues,
        public ?string $diagnostic,
    ) {
        if (trim($sourceRowIdentity) === '') {
            throw new InvalidArgumentException('sourceRowIdentity must not be empty.');
        }

        self::assertRawValuesContainNoFloats($rawValues);

        if ($status === SourceRowStatus::Valid) {
            if ($instrument === null || $quantity === null) {
                throw new InvalidArgumentException('A valid source row requires canonical instrument and quantity.');
            }

            Decimal::positive($quantity, 'quantity');

            if ($diagnostic !== null) {
                throw new InvalidArgumentException('A valid source row must not include a diagnostic.');
            }
        }

        if ($status === SourceRowStatus::Rejected && ($instrument !== null || $quantity !== null || $averageCostPlnGrosze !== null || trim((string) $diagnostic) === '')) {
            throw new InvalidArgumentException('A rejected source row requires a diagnostic and cannot normalize a position.');
        }
    }

    /** @param array<string, mixed> $rawValues */
    public static function valid(string $sourceRowIdentity, CanonicalInstrument $instrument, string $quantity, ?int $averageCostPlnGrosze, DateTimeImmutable $asOf, array $rawValues): self
    {
        return new self($sourceRowIdentity, SourceRowStatus::Valid, $instrument, $quantity, $averageCostPlnGrosze, $asOf, $rawValues, null);
    }

    /** @param array<string, mixed> $rawValues */
    public static function rejected(string $sourceRowIdentity, DateTimeImmutable $asOf, array $rawValues, string $diagnostic): self
    {
        return new self($sourceRowIdentity, SourceRowStatus::Rejected, null, null, null, $asOf, $rawValues, $diagnostic);
    }

    /** @param array<string, mixed> $rawValues */
    public static function deterministicIdentity(array $rawValues, string $sourceRowReference): string
    {
        if (trim($sourceRowReference) === '') {
            throw new InvalidArgumentException('sourceRowReference must not be empty.');
        }

        self::assertRawValuesContainNoFloats($rawValues);

        return hash('sha256', $sourceRowReference."\x1f".self::canonicalJson($rawValues));
    }

    /** @param array<string, mixed> $values */
    private static function assertRawValuesContainNoFloats(array $values): void
    {
        array_walk_recursive($values, static function (mixed $value): void {
            if (is_float($value)) {
                throw new InvalidArgumentException('Raw source values must not contain floats.');
            }
        });
    }

    /** @param array<string, mixed> $values */
    private static function canonicalJson(array $values): string
    {
        ksort($values);
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = json_decode(self::canonicalJson($value), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        unset($value);

        try {
            return json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Raw source values must be JSON serializable.', previous: $exception);
        }
    }
}
