<?php

/*
 * Camera bridge (docs/superpowers/specs/2026-10-20-camera-bridge-design.md).
 * The relay URL is the box's MediaMTX; the deploy desk sets it.
 */
return [
    // How long GET monitoring/bridge/work holds before answering unchanged.
    'long_poll_seconds' => (int) env('CAMERA_BRIDGE_LONG_POLL', 25),

    // Where a bridge publishes (RTMPS) and how long its token lives.
    'publish_url' => env('CAMERA_RELAY_PUBLISH_URL', 'rtmps://cams.ezbizi.com:1936'),
    'publish_ttl_seconds' => 600,

    // Where a viewer's MJPEG comes from (the box's go2rtc) and how long it lives.
    'play_url' => env('CAMERA_RELAY_PLAY_URL', 'https://cams.ezbizi.com'),
    'play_ttl_seconds' => 300,

    // The box's own go2rtc API (loopback): turns relay paths into MJPEG.
    'box_go2rtc' => env('CAMERA_BOX_GO2RTC', 'http://127.0.0.1:1984'),

    // Signs relay tokens and authenticates the relay's own hooks to Laravel.
    'relay_secret' => env('CAMERA_RELAY_SECRET'),

    // A viewer's want lapses unless renewed (the watch endpoint renews it).
    'want_ttl_seconds' => 60,
];
