#ifndef RUNNER_MONITORING_SPECS_H_
#define RUNNER_MONITORING_SPECS_H_

#include <flutter/flutter_engine.h>
#include <flutter/method_channel.h>
#include <flutter/standard_method_codec.h>

#include <windows.h>

#include <memory>

// Registers the com.ezbizi.app/monitoring_specs channel on the engine and
// returns the channel, which the caller MUST keep alive (the handler is only
// live while the channel object exists).
//
// [window] is the FlutterWindow's HWND: ReadSpecs runs on a worker thread (its
// Win32 calls can stall and must never block the window's own thread), and its
// result is posted back to the platform thread through that HWND. See
// platform_task.h.
std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringSpecsChannel(flutter::FlutterEngine* engine, HWND window);

#endif  // RUNNER_MONITORING_SPECS_H_
