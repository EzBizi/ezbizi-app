#ifndef RUNNER_MONITORING_INPUT_H_
#define RUNNER_MONITORING_INPUT_H_

#include <flutter/flutter_engine.h>
#include <flutter/method_channel.h>
#include <flutter/standard_method_codec.h>

#include <memory>

// Registers the com.ezbizi.app/monitoring_input channel on the engine and
// returns the channel, which the caller MUST keep alive (the handler is only
// live while the channel object exists).
std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringInputChannel(flutter::FlutterEngine* engine);

#endif  // RUNNER_MONITORING_INPUT_H_
