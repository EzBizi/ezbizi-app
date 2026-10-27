#include "watchdog.h"

#include <flutter/standard_method_codec.h>
#include <windows.h>
#include <shlobj.h>

#include <atomic>
#include <cstdio>
#include <fstream>
#include <sstream>
#include <string>

namespace {

constexpr char kWatchdogChannel[] = "com.ezbizi.app/watchdog";

// Built once at install, so the crash filter does no allocation or lookup of
// its own while the process is already failing.
wchar_t g_marker_path[MAX_PATH] = {0};

// Set by MarkShutdownStarted; read by the crash filter, lock-free.
std::atomic<bool> g_shutdown_started{false};

bool MarkerPath(wchar_t* out) {
  PWSTR base = nullptr;
  if (FAILED(SHGetKnownFolderPath(FOLDERID_LocalAppData, 0, nullptr, &base))) {
    return false;
  }
  std::wstring dir = std::wstring(base) + L"\\EzBizi";
  CoTaskMemFree(base);
  CreateDirectoryW(dir.c_str(), nullptr);  // fine if it already exists
  std::wstring path = dir + L"\\last_crash.txt";
  if (path.size() >= MAX_PATH) return false;
  wcscpy_s(out, MAX_PATH, path.c_str());
  return true;
}

LONG WINAPI WriteCrashMarker(EXCEPTION_POINTERS* info) {
  if (g_marker_path[0] != 0 && info != nullptr && info->ExceptionRecord != nullptr) {
    const auto* rec = info->ExceptionRecord;

    // Which module the faulting address is in — the engine, a plugin, ours.
    char module[MAX_PATH] = "unknown";
    HMODULE owner = nullptr;
    if (GetModuleHandleExA(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS |
                               GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
                           static_cast<LPCSTR>(rec->ExceptionAddress), &owner)) {
      GetModuleFileNameA(owner, module, MAX_PATH);
    }
    const char* name = strrchr(module, '\\');
    name = name ? name + 1 : module;

    char line[512];
    SYSTEMTIME now;
    GetSystemTime(&now);
    _snprintf_s(line, sizeof(line), _TRUNCATE,
                "%sexception 0x%08lX at %p in %s (offset 0x%llX), %04u-%02u-%02u %02u:%02u:%02u UTC",
                g_shutdown_started.load() ? "during shutdown: " : "",
                rec->ExceptionCode, rec->ExceptionAddress, name,
                owner ? static_cast<unsigned long long>(
                            reinterpret_cast<const char*>(rec->ExceptionAddress) -
                            reinterpret_cast<const char*>(owner))
                      : 0ULL,
                now.wYear, now.wMonth, now.wDay, now.wHour, now.wMinute, now.wSecond);

    HANDLE file = CreateFileW(g_marker_path, GENERIC_WRITE, 0, nullptr, CREATE_ALWAYS,
                              FILE_ATTRIBUTE_NORMAL, nullptr);
    if (file != INVALID_HANDLE_VALUE) {
      DWORD written = 0;
      WriteFile(file, line, static_cast<DWORD>(strlen(line)), &written, nullptr);
      CloseHandle(file);
    }
  }
  return EXCEPTION_CONTINUE_SEARCH;  // let Windows Error Reporting do the rest
}

}  // namespace

void MarkShutdownStarted() {
  g_shutdown_started.store(true);
}

void InstallCrashMarker() {
  if (MarkerPath(g_marker_path)) {
    SetUnhandledExceptionFilter(WriteCrashMarker);
  }
}

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterWatchdogChannel(flutter::FlutterEngine* engine) {
  auto channel = std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
      engine->messenger(), kWatchdogChannel,
      &flutter::StandardMethodCodec::GetInstance());

  channel->SetMethodCallHandler(
      [](const flutter::MethodCall<flutter::EncodableValue>& call,
         std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>> result) {
        if (call.method_name() == "ping") {
          // Answered inline, on the platform thread: that is the measurement.
          result->Success();
          return;
        }
        if (call.method_name() == "takeLastCrash") {
          if (g_marker_path[0] == 0) {
            result->Success();
            return;
          }
          std::ifstream in(g_marker_path);
          if (!in) {
            result->Success();
            return;
          }
          std::stringstream text;
          text << in.rdbuf();
          in.close();
          DeleteFileW(g_marker_path);
          result->Success(flutter::EncodableValue(text.str()));
          return;
        }
        result->NotImplemented();
      });

  return channel;
}
