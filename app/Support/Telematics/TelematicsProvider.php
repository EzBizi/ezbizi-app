<?php

namespace App\Support\Telematics;

use App\Models\CameraEvent;
use App\Models\TelematicsConnection;

/** One vendor (Smartcar for Ford, Samsara, Motive...). Readings go through TelematicsProviders::ingest*. */
interface TelematicsProvider
{
    /** The connect flow's self-check: ['ok' => bool, 'detail' => string, 'devices' => [['external_id', 'label', 'kind'], ...]]. */
    public function check(TelematicsConnection $connection): array;

    /** ['url' => string, 'expires_at' => CarbonInterface] for the clip, signed now. */
    public function clipUrl(TelematicsConnection $connection, CameraEvent $event): array;
}
