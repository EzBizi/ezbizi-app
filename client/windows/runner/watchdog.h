#ifndef RUNNER_WATCHDOG_H_
#define RUNNER_WATCHDOG_H_

#include <flutter/flutter_engine.h>
#include <flutter/method_channel.h>
#include <flutter/encodable_value.h>

#include <memory>

// The app reporting its own problems (2026-10-15). Two pieces, both tiny:
//
//  * InstallCrashMarker — an unhandled native exception writes one line to
//    %LOCALAPPDATA%\EzBizi\last_crash.txt (code, address, module) before the
//    process dies; the next launch reads it and reports it.
//  * the `com.ezbizi.app/watchdog` channel — `ping` answers immediately ON
//    the platform thread, so a slow answer means that thread was blocked (the
//    10-06 freeze); `takeLastCrash` returns and deletes the marker.
void InstallCrashMarker();

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterWatchdogChannel(flutter::FlutterEngine* engine);

#endif  // RUNNER_WATCHDOG_H_
