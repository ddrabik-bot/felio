<?php

namespace App\Domain\Portfolio;

enum SourceRowStatus: string
{
    case Valid = 'valid';
    case Rejected = 'rejected';
}
