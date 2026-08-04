<?php

namespace App\Application\Portfolio\Xtb;

final readonly class XtbImportResult
{
    public function __construct(
        public int $validRows,
        public int $rejectedRows,
    ) {}
}
