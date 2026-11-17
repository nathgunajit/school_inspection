<?php

namespace App\Enums;

enum EvidenceStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Failed = 'failed';
}
