<?php

namespace App\Application\Portfolio\Xtb;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class XtbXlsxParser
{
    private const MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function parse(string $path): XtbParsedWorkbook
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The XTB workbook must be a readable local file.');
        }

        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new InvalidArgumentException('The XTB workbook is not a valid XLSX archive.');
        }

        try {
            $sharedStrings = $this->sharedStrings($archive);
            $sheets = $this->sheets($archive, $sharedStrings);
        } finally {
            $archive->close();
        }

        foreach (['Cash Operations', 'Closed Positions'] as $requiredSheet) {
            if (! array_key_exists($requiredSheet, $sheets)) {
                throw new InvalidArgumentException("The XTB workbook is missing the {$requiredSheet} sheet.");
            }
        }

        $cash = $sheets['Cash Operations'];
        $closed = $sheets['Closed Positions'];
        $accountReference = $this->metadata($cash, 'account');
        $product = $this->metadata($cash, 'product');

        if ($accountReference === null || $product === null) {
            throw new InvalidArgumentException('The XTB workbook is missing account or product metadata.');
        }

        $closedAccount = $this->metadata($closed, 'account');
        $closedProduct = $this->metadata($closed, 'product');
        if (($closedAccount !== null && $closedAccount !== $accountReference) || ($closedProduct !== null && $closedProduct !== $product)) {
            throw new InvalidArgumentException('The XTB workbook sheets have inconsistent account or product metadata.');
        }

        return new XtbParsedWorkbook($accountReference, $product, [
            ...$this->cashRows($cash),
            ...$this->closedRows($closed),
        ]);
    }

    /** @return array<string, list<array<string, string>>> */
    private function sheets(ZipArchive $archive, array $sharedStrings): array
    {
        $workbook = $this->xml($archive, 'xl/workbook.xml');
        $relationships = $this->xml($archive, 'xl/_rels/workbook.xml.rels');
        $relationshipTargets = [];
        foreach ($relationships->xpath('//*[local-name() = "Relationship"]') ?: [] as $relationship) {
            $relationshipTargets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $result = [];
        foreach ($workbook->xpath('//*[local-name() = "sheet"]') ?: [] as $sheet) {
            $attributes = $sheet->attributes(self::RELATIONSHIP_NAMESPACE);
            $relationshipId = (string) $attributes['id'];
            $target = $relationshipTargets[$relationshipId] ?? null;
            if ($target === null) {
                throw new RuntimeException('The XTB workbook contains an unresolved sheet relationship.');
            }

            $result[(string) $sheet['name']] = $this->rows($this->xml($archive, 'xl/'.ltrim($target, '/')), $sharedStrings);
        }

        return $result;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $archive): array
    {
        if ($archive->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];
        foreach ($this->xml($archive, 'xl/sharedStrings.xml')->xpath('//*[local-name() = "si"]') ?: [] as $string) {
            $strings[] = trim(implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, iterator_to_array($string->xpath('.//*[local-name() = "t"]') ?: []))));
        }

        return $strings;
    }

    /** @return list<array<string, string>> */
    private function rows(SimpleXMLElement $sheet, array $sharedStrings): array
    {
        $rows = [];
        foreach ($sheet->xpath('//*[local-name() = "sheetData"]/*[local-name() = "row"]') ?: [] as $row) {
            $values = ['__row' => (string) $row['r']];
            foreach ($row->xpath('./*[local-name() = "c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                $column = preg_replace('/[0-9]+/', '', $reference);
                if ($column === null) {
                    continue;
                }
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $sharedStrings[(int) $cell->v] ?? '',
                    'inlineStr' => trim(implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, iterator_to_array($cell->xpath('.//*[local-name() = "t"]') ?: [])))),
                    default => (string) $cell->v,
                };
                $values[$column] = trim($value);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    /** @param list<array<string, string>> $rows */
    private function metadata(array $rows, string $key): ?string
    {
        foreach (array_slice($rows, 0, 4) as $row) {
            foreach ($row as $column => $value) {
                if ($column === '__row' || $this->headerKey($value) !== $key) {
                    continue;
                }
                $next = $row[$this->nextColumn($column)] ?? null;

                return $next === null || $next === '' ? null : $next;
            }
        }

        return null;
    }

    /** @param list<array<string, string>> $rows @return list<XtbParsedRow> */
    private function cashRows(array $rows): array
    {
        [$headers, $firstDataRow] = $this->headers($rows, ['timestamp', 'operation', 'symbol']);
        $parsed = [];
        foreach (array_slice($rows, $firstDataRow) as $row) {
            $values = $this->values($row, $headers);
            if ($values === []) {
                continue;
            }
            $asOf = $this->asOf($values['timestamp'] ?? null);
            $operation = $this->operation($values['operation'] ?? null);
            $diagnostic = match (true) {
                $asOf === null => 'invalid_or_missing_timestamp',
                $operation === null => 'unsupported_cash_operation',
                ($values['symbol'] ?? '') === '' => 'missing_instrument_symbol',
                ! $this->decimal($values['volume'] ?? null) => 'invalid_or_missing_volume',
                ! $this->decimal($values['price'] ?? null) => 'invalid_or_missing_price',
                default => null,
            };
            $parsed[] = new XtbParsedRow('Cash Operations', 'Cash Operations:'.$row['__row'], $operation, $values['symbol'] ?? null, $values['comment'] ?? null, $values['volume'] ?? null, $values['price'] ?? null, $asOf ?? new DateTimeImmutable('@0'), $values, $diagnostic);
        }

        return $parsed;
    }

    /** @param list<array<string, string>> $rows @return list<XtbParsedRow> */
    private function closedRows(array $rows): array
    {
        [$headers, $firstDataRow] = $this->headers($rows, ['position', 'symbol']);
        $parsed = [];
        foreach (array_slice($rows, $firstDataRow) as $row) {
            $values = $this->values($row, $headers);
            if ($values === []) {
                continue;
            }
            $asOf = $this->asOf($values['close_timestamp'] ?? null) ?? new DateTimeImmutable('@0');
            $parsed[] = new XtbParsedRow('Closed Positions', 'Closed Positions:'.$row['__row'], null, $values['symbol'] ?? null, $values['comment'] ?? null, $values['volume'] ?? null, $values['close_price'] ?? null, $asOf, $values, 'unsupported_closed_position');
        }

        return $parsed;
    }

    /** @param list<array<string, string>> $rows @param list<string> $required @return array{array<string, string>, int} */
    private function headers(array $rows, array $required): array
    {
        foreach ($rows as $index => $row) {
            $headers = [];
            foreach ($row as $column => $value) {
                if ($column !== '__row' && ($key = $this->headerKey($value)) !== null) {
                    $headers[$key] = $column;
                }
            }
            if (array_diff($required, array_keys($headers)) === []) {
                return [$headers, $index + 1];
            }
        }
        throw new InvalidArgumentException('The XTB worksheet has no supported header row.');
    }

    /** @param array<string, string> $row @param array<string, string> $headers @return array<string, string> */
    private function values(array $row, array $headers): array
    {
        $values = [];
        foreach ($headers as $key => $column) {
            $values[$key] = $row[$column] ?? '';
        }

        return array_filter($values, static fn (string $value): bool => $value !== '');
    }

    private function headerKey(string $value): ?string
    {
        return match (mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 'UTF-8')) {
            'account', 'account number' => 'account',
            'product', 'product name' => 'product',
            'time', 'date', 'date/time', 'transaction time' => 'timestamp',
            'close time' => 'close_timestamp',
            'operation', 'transaction type', 'type' => 'operation',
            'symbol', 'ticker', 'instrument' => 'symbol',
            'comment', 'description' => 'comment',
            'volume', 'quantity' => 'volume',
            'price' => 'price',
            'open price' => 'open_price',
            'close price' => 'close_price',
            'position', 'position number', 'position id' => 'position',
            'amount', 'profit', 'net profit' => 'amount',
            'currency' => 'currency',
            'commission' => 'commission',
            'swap' => 'swap',
            default => null,
        };
    }

    private function operation(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value), 'UTF-8')) {
            'buy', 'purchase', 'kupno' => 'buy',
            'sell', 'sale', 'sprzedaż' => 'sell',
            default => null,
        };
    }

    private function decimal(?string $value): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return preg_match('/^[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) === 1 && bccomp($value, '0', 20) === 1 ? $value : null;
    }

    private function asOf(?string $serial): ?DateTimeImmutable
    {
        $serial = str_replace(',', '.', trim((string) $serial));
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?$/', $serial, $matches) !== 1) {
            return null;
        }
        $seconds = isset($matches[2]) ? (int) bcmul('0.'.$matches[2], '86400', 0) : 0;

        return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC')))->add(new DateInterval('P'.$matches[1].'D'))->add(new DateInterval('PT'.$seconds.'S'));
    }

    private function nextColumn(string $column): string
    {
        return chr(ord($column) + 1);
    }

    private function xml(ZipArchive $archive, string $name): SimpleXMLElement
    {
        $contents = $archive->getFromName($name);
        if ($contents === false || ($xml = simplexml_load_string($contents)) === false) {
            throw new InvalidArgumentException('The XTB workbook contains invalid XML.');
        }
        $xml->registerXPathNamespace('main', self::MAIN_NAMESPACE);

        return $xml;
    }
}
