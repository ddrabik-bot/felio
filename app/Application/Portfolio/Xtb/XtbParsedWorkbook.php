<?php

namespace App\Application\Portfolio\Xtb;

final readonly class XtbParsedWorkbook
{
    /** @param list<XtbParsedRow> $rows */
    public function __construct(
        public string $accountReference,
        public string $product,
        public array $rows,
    ) {}
}
