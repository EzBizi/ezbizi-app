#include "monitoring_specs.h"

#include <windows.h>
#include <winternl.h>
#include <wlanapi.h>

#include <cstdint>
#include <string>

namespace {

using flutter::EncodableMap;
using flutter::EncodableValue;

typedef LONG(WINAPI* RtlGetVersionPtr)(PRTL_OSVERSIONINFOW);

EncodableMap ReadSpecs() {
  EncodableMap m;

  // RAM: total physical memory, in GB.
  MEMORYSTATUSEX mem{};
  mem.dwLength = sizeof(mem);
  if (GlobalMemoryStatusEx(&mem)) {
    m[EncodableValue("ram_gb")] = EncodableValue(
        static_cast<int64_t>(mem.ullTotalPhys / (1024ULL * 1024 * 1024)));
  }

  // Disk free space on the system drive, in GB.
  wchar_t sysdir[MAX_PATH] = {0};
  if (GetWindowsDirectoryW(sysdir, MAX_PATH) >= 3) {
    sysdir[3] = L'\0';  // Truncate "C:\Windows" down to "C:\".
    ULARGE_INTEGER freeBytes{};
    if (GetDiskFreeSpaceExW(sysdir, &freeBytes, nullptr, nullptr)) {
      m[EncodableValue("disk_free_gb")] = EncodableValue(static_cast<int64_t>(
          freeBytes.QuadPart / (1024ULL * 1024 * 1024)));
    }
  }

  // OS version, via RtlGetVersion (GetVersionEx is deprecated/lies since
  // Windows 8.1 unless the exe carries a matching manifest).
  RTL_OSVERSIONINFOW vi{};
  vi.dwOSVersionInfoSize = sizeof(vi);
  if (HMODULE ntdll = GetModuleHandleW(L"ntdll.dll")) {
    auto rtl = reinterpret_cast<RtlGetVersionPtr>(
        GetProcAddress(ntdll, "RtlGetVersion"));
    if (rtl && rtl(&vi) == 0) {
      std::string name = vi.dwBuildNumber >= 22000 ? "Windows 11" : "Windows 10";
      m[EncodableValue("os_version")] =
          EncodableValue(name + " " + std::to_string(vi.dwBuildNumber));
    }
  }

  // Wi-Fi SSID + signal strength, via WLAN API. Every step is independent of
  // the others above and only ever adds keys on success; any failure here
  // simply leaves wifi_ssid / wifi_signal_dbm absent.
  DWORD negotiated = 0;
  HANDLE wlan = nullptr;
  if (WlanOpenHandle(2, nullptr, &negotiated, &wlan) == ERROR_SUCCESS) {
    PWLAN_INTERFACE_INFO_LIST list = nullptr;
    if (WlanEnumInterfaces(wlan, nullptr, &list) == ERROR_SUCCESS && list &&
        list->dwNumberOfItems > 0) {
      GUID guid = list->InterfaceInfo[0].InterfaceGuid;
      PWLAN_CONNECTION_ATTRIBUTES attr = nullptr;
      DWORD size = 0;
      WLAN_OPCODE_VALUE_TYPE opcode;
      if (WlanQueryInterface(wlan, &guid, wlan_intf_opcode_current_connection,
                              nullptr, &size, reinterpret_cast<PVOID*>(&attr),
                              &opcode) == ERROR_SUCCESS &&
          attr && attr->isState == wlan_interface_state_connected) {
        const auto& ssid = attr->wlanAssociationAttributes.dot11Ssid;
        m[EncodableValue("wifi_ssid")] = EncodableValue(std::string(
            reinterpret_cast<const char*>(ssid.ucSSID), ssid.uSSIDLength));
        int quality =
            static_cast<int>(attr->wlanAssociationAttributes.wlanSignalQuality);
        m[EncodableValue("wifi_signal_dbm")] =
            EncodableValue(static_cast<int64_t>(quality / 2 - 100));
      }
      if (attr) WlanFreeMemory(attr);
    }
    if (list) WlanFreeMemory(list);
    WlanCloseHandle(wlan, nullptr);
  }

  return m;
}

}  // namespace

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringSpecsChannel(flutter::FlutterEngine* engine) {
  auto channel = std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
      engine->messenger(), "com.ezbizi.app/monitoring_specs",
      &flutter::StandardMethodCodec::GetInstance());
  channel->SetMethodCallHandler(
      [](const flutter::MethodCall<flutter::EncodableValue>& call,
         std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>> result) {
        if (call.method_name() == "readSpecs") {
          result->Success(EncodableValue(ReadSpecs()));
        } else {
          result->NotImplemented();
        }
      });
  return channel;
}
