#include "monitoring_input.h"

#include <windows.h>

#include <cmath>
#include <cstdint>
#include <string>
#include <vector>

namespace {

using flutter::EncodableList;
using flutter::EncodableMap;
using flutter::EncodableValue;

// --- Safe field getters. All return a default/false on a missing key or an
// unexpected type instead of throwing, so one malformed event never takes
// down the rest of the batch. ---

const EncodableValue* Find(const EncodableMap& map, const char* key) {
  auto it = map.find(EncodableValue(key));
  return it == map.end() ? nullptr : &it->second;
}

std::string GetString(const EncodableMap& map, const char* key) {
  const EncodableValue* v = Find(map, key);
  if (!v) return std::string();
  if (const auto* s = std::get_if<std::string>(v)) {
    return *s;
  }
  return std::string();
}

// Numbers cross the channel as int32, int64 or double depending on how Dart
// encoded them; accept all three and normalize to double.
bool GetDouble(const EncodableMap& map, const char* key, double* out) {
  const EncodableValue* v = Find(map, key);
  if (!v) return false;
  if (const auto* d = std::get_if<double>(v)) {
    *out = *d;
    return true;
  }
  if (const auto* i32 = std::get_if<int32_t>(v)) {
    *out = *i32;
    return true;
  }
  if (const auto* i64 = std::get_if<int64_t>(v)) {
    *out = static_cast<double>(*i64);
    return true;
  }
  return false;
}

bool GetInt(const EncodableMap& map, const char* key, int64_t* out) {
  double d = 0;
  if (!GetDouble(map, key, &d)) return false;
  *out = static_cast<int64_t>(d);
  return true;
}

bool GetBool(const EncodableMap& map, const char* key, bool* out) {
  const EncodableValue* v = Find(map, key);
  if (!v) return false;
  if (const auto* b = std::get_if<bool>(v)) {
    *out = *b;
    return true;
  }
  return false;
}

double ClampUnit(double v) {
  if (!(v >= 0.0)) return 0.0;  // Also folds NaN to 0.
  if (v > 1.0) return 1.0;
  return v;
}

// Appends one absolute mouse-move INPUT. x/y are normalized 0..1 against the
// PRIMARY monitor and mapped to the 0..65535 absolute coordinate space that
// MOUSEEVENTF_ABSOLUTE expects.
//
// ponytail: primary-monitor only, on purpose — matches the primary-monitor
// capture in monitoring_screenshot.cpp, so no multi-monitor coordinate
// translation is needed here.
void AppendMouseMove(const EncodableMap& event, std::vector<INPUT>* out) {
  double x = 0, y = 0;
  if (!GetDouble(event, "x", &x) || !GetDouble(event, "y", &y)) return;
  x = ClampUnit(x);
  y = ClampUnit(y);

  INPUT input = {};
  input.type = INPUT_MOUSE;
  input.mi.dx = static_cast<LONG>(std::lround(x * 65535.0));
  input.mi.dy = static_cast<LONG>(std::lround(y * 65535.0));
  input.mi.dwFlags = MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE;
  out->push_back(input);
}

void AppendMouseButton(const EncodableMap& event, std::vector<INPUT>* out) {
  bool down = false;
  if (!GetBool(event, "down", &down)) return;
  std::string button = GetString(event, "button");

  DWORD flag;
  if (button == "left") {
    flag = down ? MOUSEEVENTF_LEFTDOWN : MOUSEEVENTF_LEFTUP;
  } else if (button == "right") {
    flag = down ? MOUSEEVENTF_RIGHTDOWN : MOUSEEVENTF_RIGHTUP;
  } else if (button == "middle") {
    flag = down ? MOUSEEVENTF_MIDDLEDOWN : MOUSEEVENTF_MIDDLEUP;
  } else {
    return;  // Unknown button: skip this event.
  }

  INPUT input = {};
  input.type = INPUT_MOUSE;
  input.mi.dwFlags = flag;
  out->push_back(input);
}

void AppendScroll(const EncodableMap& event, std::vector<INPUT>* out) {
  int64_t dy = 0;
  if (!GetInt(event, "dy", &dy)) return;

  INPUT input = {};
  input.type = INPUT_MOUSE;
  input.mi.dwFlags = MOUSEEVENTF_WHEEL;
  input.mi.mouseData = static_cast<DWORD>(static_cast<LONG>(dy));
  out->push_back(input);
}

void AppendKey(const EncodableMap& event, std::vector<INPUT>* out) {
  int64_t code = 0;
  bool down = false;
  if (!GetInt(event, "code", &code) || !GetBool(event, "down", &down)) return;
  if (code < 0 || code > 0xFF) return;  // Not a valid virtual-key byte.

  INPUT input = {};
  input.type = INPUT_KEYBOARD;
  input.ki.wVk = static_cast<WORD>(code);
  input.ki.dwFlags = down ? 0 : KEYEVENTF_KEYUP;
  out->push_back(input);
}

// Types Unicode text as a keydown+keyup pair per UTF-16 code unit (surrogate
// pairs naturally become two units, which Windows accepts one at a time via
// KEYEVENTF_UNICODE).
void AppendText(const EncodableMap& event, std::vector<INPUT>* out) {
  const EncodableValue* v = Find(event, "text");
  const std::string* text = v ? std::get_if<std::string>(v) : nullptr;
  if (!text || text->empty()) return;

  int wide_len = ::MultiByteToWideChar(CP_UTF8, 0, text->c_str(),
                                       static_cast<int>(text->size()),
                                       nullptr, 0);
  if (wide_len <= 0) return;
  std::wstring wide(static_cast<size_t>(wide_len), L'\0');
  ::MultiByteToWideChar(CP_UTF8, 0, text->c_str(),
                        static_cast<int>(text->size()), &wide[0], wide_len);

  for (wchar_t code_unit : wide) {
    INPUT down = {};
    down.type = INPUT_KEYBOARD;
    down.ki.wVk = 0;
    down.ki.wScan = static_cast<WORD>(code_unit);
    down.ki.dwFlags = KEYEVENTF_UNICODE;
    out->push_back(down);

    INPUT up = down;
    up.ki.dwFlags = KEYEVENTF_UNICODE | KEYEVENTF_KEYUP;
    out->push_back(up);
  }
}

// Builds the full INPUT batch for one event list. Each event is independent:
// a malformed or unrecognized one is skipped rather than failing the call.
std::vector<INPUT> BuildInputs(const EncodableList& events) {
  std::vector<INPUT> inputs;
  inputs.reserve(events.size());

  for (const EncodableValue& item : events) {
    const auto* event = std::get_if<EncodableMap>(&item);
    if (!event) continue;

    std::string type = GetString(*event, "type");
    if (type == "mouseMove") {
      AppendMouseMove(*event, &inputs);
    } else if (type == "mouseButton") {
      AppendMouseButton(*event, &inputs);
    } else if (type == "scroll") {
      AppendScroll(*event, &inputs);
    } else if (type == "key") {
      AppendKey(*event, &inputs);
    } else if (type == "text") {
      AppendText(*event, &inputs);
    }
    // Unknown type: skip silently, don't fail the batch.
  }

  return inputs;
}

void HandleInjectInput(
    const flutter::MethodCall<EncodableValue>& call,
    std::unique_ptr<flutter::MethodResult<EncodableValue>> result) {
  const EncodableValue* args = call.arguments();
  const EncodableList* events =
      args ? std::get_if<EncodableList>(args) : nullptr;
  if (!events) {
    // Missing/malformed argument: nothing applied, not an error.
    result->Success(EncodableValue(static_cast<int64_t>(0)));
    return;
  }

  std::vector<INPUT> inputs = BuildInputs(*events);
  if (!inputs.empty()) {
    ::SendInput(static_cast<UINT>(inputs.size()), inputs.data(),
               sizeof(INPUT));
  }

  result->Success(EncodableValue(static_cast<int64_t>(inputs.size())));
}

}  // namespace

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringInputChannel(flutter::FlutterEngine* engine) {
  auto channel =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          engine->messenger(), "com.ezbizi.app/monitoring_input",
          &flutter::StandardMethodCodec::GetInstance());
  channel->SetMethodCallHandler(
      [](const flutter::MethodCall<EncodableValue>& call,
         std::unique_ptr<flutter::MethodResult<EncodableValue>> result) {
        if (call.method_name() == "injectInput") {
          HandleInjectInput(call, std::move(result));
        } else {
          result->NotImplemented();
        }
      });
  return channel;
}
