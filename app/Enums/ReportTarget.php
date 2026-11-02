<?php

namespace App\Enums;

enum ReportTarget: string
{
    case Post = 'post';
    case Creator = 'creator';
    case Product = 'product';
}
