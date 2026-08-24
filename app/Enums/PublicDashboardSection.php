<?php

namespace App\Enums;

enum PublicDashboardSection: string
{
    case Metrics = 'metrics';
    case Traffic = 'traffic';
    case Pages = 'pages';
    case Acquisition = 'acquisition';
    case Audience = 'audience';
}
