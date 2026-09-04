#include "flutter_window.h"

#include <optional>
#include <utility>
#include <variant>

#include "flutter/generated_plugin_registrant.h"
#include "monitoring_input.h"
#include "monitoring_screenshot.h"
#include "monitoring_specs.h"

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
      RegisterMonitoringSpecsChannel(flutter_controller_->engine());

  monitoring_screenshot_channel_ =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          flutter_controller_->engine()->messenger(),
          kMonitoringScreenshotChannel,
          &flutter::StandardMethodCodec::GetInstance());
  monitoring_screenshot_channel_->SetMethodCallHandler(
      [](const flutter::MethodCall<flutter::EncodableValue>& call,
         std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>>
             result) {
        if (call.method_name() != kCaptureScreenshotMethod) {
          result->NotImplemented();
          return;
        }
        std::vector<uint8_t> jpeg_bytes = CapturePrimaryMonitorJpeg();
        if (jpeg_bytes.empty()) {
          result->Success();
          return;
        }
        result->Success(flutter::EncodableValue(std::move(jpeg_bytes)));
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

void FlutterWindow::OnDestroy() {
  if (monitoring_indicator_channel_) {
    monitoring_indicator_channel_ = nullptr;
  }

  if (monitoring_input_channel_) {
    monitoring_input_channel_ = nullptr;
  }

  if (monitoring_screenshot_channel_) {
    monitoring_screenshot_channel_ = nullptr;
  }

  if (flutter_controller_) {
    flutter_controller_ = nullptr;
  }
  monitoring_specs_channel_ = nullptr;

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
  }

  return Win32Window::MessageHandler(hwnd, message, wparam, lparam);
}
