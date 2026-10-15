#ifndef RUNNER_FLUTTER_WINDOW_H_
#define RUNNER_FLUTTER_WINDOW_H_

#include <flutter/dart_project.h>
#include <flutter/flutter_view_controller.h>
#include <flutter/method_channel.h>
#include <flutter/standard_method_codec.h>

#include <memory>

#include "monitoring_input.h"
#include "monitoring_specs.h"
#include "watchdog.h"
#include "win32_window.h"

// A window that does nothing but host a Flutter view.
class FlutterWindow : public Win32Window {
 public:
  // Creates a new FlutterWindow hosting a Flutter view running |project|.
  explicit FlutterWindow(const flutter::DartProject& project);
  virtual ~FlutterWindow();

 protected:
  // Win32Window:
  bool OnCreate() override;
  void OnDestroy() override;
  LRESULT MessageHandler(HWND window, UINT const message, WPARAM const wparam,
                         LPARAM const lparam) noexcept override;

 private:
  // The project to run.
  flutter::DartProject project_;

  // The Flutter instance hosted by this window.
  std::unique_ptr<flutter::FlutterViewController> flutter_controller_;

  // The com.ezbizi.app/monitoring_specs channel. Must be kept alive for as
  // long as the handler should keep responding to readSpecs calls.
  std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
      monitoring_specs_channel_;
  // Serves com.ezbizi.app/monitoring_screenshot's captureScreenshot method.
  // Stored as a member (rather than a local in OnCreate) so it outlives
  // OnCreate and stays registered for the life of the window.
  std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
      monitoring_screenshot_channel_;
  // Serves com.ezbizi.app/monitoring_input's injectInput method. Must be
  // kept alive for as long as the handler should keep applying remote
  // mouse/keyboard input.
  std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
      monitoring_input_channel_;
  // Serves com.ezbizi.app/monitoring_indicator's setControlled method, which
  // raises/clears the "being controlled" tray notice on the base Win32Window.
  // Registered inline (rather than in its own file) because its handler needs
  // `this` to reach the tray; kept alive for the life of the window.
  std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
      monitoring_indicator_channel_;

  // Freeze ping and last-crash pickup (watchdog.h).
  std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
      watchdog_channel_;
};

#endif  // RUNNER_FLUTTER_WINDOW_H_
