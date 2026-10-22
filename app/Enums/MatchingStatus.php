<?php

namespace App\Enums;

enum MatchingStatus: string
{
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Ambiguous = 'ambiguous';
}
