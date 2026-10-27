#include "flutter_window.h"

#include <functional>
#include <memory>
#include <mutex>
#include <optional>
#include <string>
#include <thread>
#include <utility>
#include <variant>

#include <flutter/method_result_functions.h>

#include "flutter/generated_plugin_registrant.h"
#include "monitoring_input.h"
#include "monitoring_screenshot.h"
#include "monitoring_specs.h"
#include "platform_task.h"
#include "watchdog.h"

namespace {

constexpr char kMonitoringScreenshotChannel[] =
    "com.ezbizi.app/monitoring_screenshot";
constexpr char kCaptureScreenshotMethod[] = "captureScreenshot";

constexpr char kMonitoringIndicatorChannel[] =
    "com.ezbizi.app/monitoring_indicator";
constexpr char kSetControlledMethod[] = "setControlled";

}  // namespace

FlutterWindow::FlutterWindow(const flutter::DartProject& project)
    : project_(project) {}

FlutterWindow::~FlutterWindow() {}

bool FlutterWindow::OnCreate() {
  if (!Win32Window::OnCreate()) {
    return false;
  }

  RECT frame = GetClientArea();

  // The size here must match the window dimensions to avoid unnecessary surface
  // creation / destruction in the startup path.
  flutter_controller_ = std::make_unique<flutter::FlutterViewController>(
      frame.right - frame.left, frame.bottom - frame.top, project_);
  // Ensure that basic setup of the controller was successful.
  if (!flutter_controller_->engine() || !flutter_controller_->view()) {
    return false;
  }
  RegisterPlugins(flutter_controller_->engine());
  monitoring_specs_channel_ =
      RegisterMonitoringSpecsChannel(flutter_controller_->engine(),
                                     GetHandle());

  monitoring_screenshot_channel_ =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          flutter_controller_->engine()->messenger(),
          kMonitoringScreenshotChannel,
          &flutter::StandardMethodCodec::GetInstance());
  HWND window = GetHandle();
  monitoring_screenshot_channel_->SetMethodCallHandler(
      [window](const flutter::MethodCall<flutter::EncodableValue>& call,
               std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>>
                   result) {
        if (call.method_name() != kCaptureScreenshotMethod) {
          result->NotImplemented();
          return;
        }
        // No new worker once the window has begun to close: its reply could
        // outlive the engine (platform_task.h).
        if (PlatformShuttingDown()) {
          result->Error("shutting_down", "The app is closing.");
          return;
        }

        // OFF THE PLATFORM THREAD, the same reason as ReadSpecs: BitBlt +
        // GetDIBits + WIC JPEG on a busy or large display can take long enough
        // to freeze the window, and this fires every 5 min and every ~8 s while
        // someone watches live. shared_ptr so the completion can live in a
        // std::function (which must be copyable).
        std::shared_ptr<flutter::MethodResult<flutter::EncodableValue>> shared =
            std::move(result);

        std::thread([shared, window, worker = std::make_shared<InFlightWorker>()]() {
          static std::mutex capture_mutex;
          std::lock_guard<std::mutex> lock(capture_mutex);

          std::string reason;
          std::vector<uint8_t> jpeg_bytes = CapturePrimaryMonitorJpeg(&reason);

          PostTaskToPlatform(window, [shared, jpeg_bytes = std::move(jpeg_bytes),
                                      reason]() mutable {
            // A failed capture is an ERROR carrying the reason slug, not
            // Success(null): a machine that can never capture must not look
            // like one that just has not yet — see monitoring_screenshot.h.
            if (jpeg_bytes.empty()) {
              shared->Error(reason.empty() ? "capture_failed" : reason,
                            "The primary monitor could not be captured.");
              return;
            }
            shared->Success(flutter::EncodableValue(std::move(jpeg_bytes)));
          });
        }).detach();
      });

  monitoring_input_channel_ =
      RegisterMonitoringInputChannel(flutter_controller_->engine());

  // The "being controlled" indicator. Registered inline, capturing `this`, so
  // its handler can reach SetControlledNotice on the base Win32Window (the tray
  // icon lives there); the screenshot channel above is the same inline shape.
  monitoring_indicator_channel_ =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          flutter_controller_->engine()->messenger(),
          kMonitoringIndicatorChannel,
          &flutter::StandardMethodCodec::GetInstance());
  monitoring_indicator_channel_->SetMethodCallHandler(
      [this](const flutter::MethodCall<flutter::EncodableValue>& call,
             std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>>
                 result) {
        if (call.method_name() != kSetControlledMethod) {
          result->NotImplemented();
          return;
        }
        // The one argument is a bool; anything else is treated as "not
        // controlled" rather than throwing back across the channel.
        bool active = false;
        if (const auto* value = std::get_if<bool>(call.arguments())) {
          active = *value;
        }
        this->SetControlledNotice(active);
        result->Success();
      });

  watchdog_channel_ = RegisterWatchdogChannel(flutter_controller_->engine());

  SetChildContent(flutter_controller_->view()->GetNativeWindow());

  flutter_controller_->engine()->SetNextFrameCallback([&]() {
    this->Show();
  });

  // Flutter can complete the first frame before the "show window" callback is
  // registered. The following call ensures a frame is pending to ensure the
  // window is shown. It is a no-op if the first frame hasn't completed yet.
  flutter_controller_->ForceRedraw();

  return true;
}

namespace {

// Run the window's messages until [done] or [timeout_ms], so the engine can
// deliver replies (and posted worker completions) while we wait. A WM_QUIT
// seen here is put back for the main loop.
void PumpUntil(const std::function<bool()>& done, ULONGLONG timeout_ms) {
  const ULONGLONG deadline = ::GetTickCount64() + timeout_ms;
  MSG msg;
  while (!done() && ::GetTickCount64() < deadline) {
    if (::PeekMessage(&msg, nullptr, 0, 0, PM_REMOVE)) {
      if (msg.message == WM_QUIT) {
        ::PostQuitMessage(static_cast<int>(msg.wParam));
        return;
      }
      ::TranslateMessage(&msg);
      ::DispatchMessage(&msg);
    } else {
      ::MsgWaitForMultipleObjects(0, nullptr, FALSE, 10, QS_ALLINPUT);
    }
  }
}

}  // namespace

/*
 * Shutdown, in this order (2026-10-27).
 *
 * "The app closed unexpectedly last time": 27 users, every version, nearly all
 * while the updater closed the app. An access violation at
 * flutter_windows.dll+0x14BBD — FlutterDesktopMessengerSetCallback per the
 * 3.41.6 engine PDB. ~FlutterWindowsEngine detaches its messenger and THEN
 * runs the plugin destructors; webview_windows' ~WebviewBridge calls
 * SetMethodCallHandler(nullptr) and dereferences the null engine. Anything that
 * touches the messenger after the engine starts going away does the same.
 *
 *  1. Mark shutdown: the crash marker now says "during shutdown", and no
 *     handler starts new background work.
 *  2. Ask Dart to release its WebViews (`prepareToExit`, lib/core/
 *     exit_hooks.dart) and wait for the answer, bounded. A WebView disposed
 *     now is erased by its plugin while the engine is whole.
 *  3. Wait, bounded, for in-flight worker threads, so their replies land on a
 *     live engine (platform_task.h covers one that is later still).
 *  4. Unregister every channel handler of ours while the engine is alive.
 *  5. Only then destroy the controller, and with it the engine.
 */
void FlutterWindow::OnDestroy() {
  MarkShutdownStarted();
  PlatformShuttingDown().store(true);

  if (flutter_controller_) {
    if (watchdog_channel_) {
      auto answered = std::make_shared<bool>(false);
      watchdog_channel_->InvokeMethod(
          "prepareToExit", nullptr,
          std::make_unique<
              flutter::MethodResultFunctions<flutter::EncodableValue>>(
              [answered](const flutter::EncodableValue*) { *answered = true; },
              [answered](const std::string&, const std::string&,
                         const flutter::EncodableValue*) { *answered = true; },
              [answered]() { *answered = true; }));
      PumpUntil([answered]() { return *answered; }, 3000);
    }

    PumpUntil([]() { return InFlightWorkers().load() == 0; }, 1500);

    for (auto* channel :
         {&monitoring_indicator_channel_, &monitoring_input_channel_,
          &monitoring_screenshot_channel_, &monitoring_specs_channel_,
          &watchdog_channel_}) {
      if (*channel) {
        (*channel)->SetMethodCallHandler(nullptr);
        *channel = nullptr;
      }
    }

    flutter_controller_ = nullptr;
  }

  Win32Window::OnDestroy();
}

LRESULT
FlutterWindow::MessageHandler(HWND hwnd, UINT const message,
                              WPARAM const wparam,
                              LPARAM const lparam) noexcept {
  // Give Flutter, including plugins, an opportunity to handle window messages.
  if (flutter_controller_) {
    std::optional<LRESULT> result =
        flutter_controller_->HandleTopLevelWindowProc(hwnd, message, wparam,
                                                      lparam);
    if (result) {
      return *result;
    }
  }

  switch (message) {
    case WM_FONTCHANGE:
      flutter_controller_->engine()->ReloadSystemFonts();
      break;
    case kWmRunOnPlatform: {
      // A worker thread finished a monitoring read and posted its completion
      // here, where result->Success/Error is allowed to run (the platform
      // thread). See platform_task.h.
      auto* task = reinterpret_cast<std::function<void()>*>(lparam);
      if (task != nullptr) {
        (*task)();
        delete task;
      }
      return 0;
    }
  }

  return Win32Window::MessageHandler(hwnd, message, wparam, lparam);
}
