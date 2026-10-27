// winsock2.h MUST precede windows.h: windows.h pulls in the ancient
// winsock.h otherwise and the two redefine each other. iphlpapi.h then needs
// winsock2's types, so the order here is load-bearing rather than tidy.
#include <winsock2.h>
#include <ws2tcpip.h>
// clang-format off
#include <windows.h>
#include <iphlpapi.h>
// clang-format on
#include <dwmapi.h>
#include <pdh.h>
// PDH_MORE_DATA and the PDH_CSTATUS_* values live here, not in pdh.h.
#include <pdhmsg.h>
#include <psapi.h>
#include <uiautomation.h>
#include <wrl/client.h>
#include <winternl.h>
#include <wlanapi.h>
#include <wscapi.h>

#include "monitoring_specs.h"
#include "platform_task.h"

#include <algorithm>
#include <atomic>
#include <cwctype>
#include <chrono>
#include <cstdint>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

namespace {

using Microsoft::WRL::ComPtr;

using flutter::EncodableList;
using flutter::EncodableMap;
using flutter::EncodableValue;

typedef LONG(WINAPI* RtlGetVersionPtr)(PRTL_OSVERSIONINFOW);

constexpr uint64_t kGb = 1024ULL * 1024 * 1024;
constexpr uint64_t kMb = 1024ULL * 1024;

std::string Narrow(const wchar_t* wide) {
  if (wide == nullptr || *wide == L'\0') return std::string();
  int size = ::WideCharToMultiByte(CP_UTF8, 0, wide, -1, nullptr, 0, nullptr,
                                   nullptr);
  if (size <= 1) return std::string();
  std::string out(static_cast<size_t>(size - 1), '\0');
  ::WideCharToMultiByte(CP_UTF8, 0, wide, -1, out.data(), size, nullptr,
                        nullptr);
  return out;
}

// ── CPU load ──────────────────────────────────────────────────────
//
// Deliberately NOT two samples 200ms apart: this runs on the platform thread,
// and a fifth of a second of jank every refresh is a worse trade than a
// number that describes the interval instead of the instant. So it keeps the
// previous reading and reports the load BETWEEN the last refresh and this
// one, which is what a roster wants anyway — "busy since we last looked", not
// a single instant that happened to be sampled while a menu opened.
//
// The first call after launch has nothing to compare against and reports
// NOTHING rather than 0: an absent key renders as "—", a zero renders as an
// idle machine, and only one of those is true.
bool CpuPercentSinceLastCall(int64_t* out) {
  static bool have_previous = false;
  static uint64_t previous_idle = 0;
  static uint64_t previous_total = 0;

  FILETIME idle_ft{}, kernel_ft{}, user_ft{};
  if (!::GetSystemTimes(&idle_ft, &kernel_ft, &user_ft)) return false;

  auto to_u64 = [](const FILETIME& ft) {
    return (static_cast<uint64_t>(ft.dwHighDateTime) << 32) | ft.dwLowDateTime;
  };

  const uint64_t idle = to_u64(idle_ft);
  // Kernel time INCLUDES idle time on Windows, so kernel + user is the total.
  const uint64_t total = to_u64(kernel_ft) + to_u64(user_ft);

  const bool had_previous = have_previous;
  const uint64_t last_idle = previous_idle;
  const uint64_t last_total = previous_total;

  have_previous = true;
  previous_idle = idle;
  previous_total = total;

  if (!had_previous || total <= last_total) return false;

  const uint64_t total_delta = total - last_total;
  const uint64_t idle_delta = idle - last_idle;
  if (idle_delta > total_delta) return false;

  const double busy =
      100.0 * static_cast<double>(total_delta - idle_delta) /
      static_cast<double>(total_delta);
  *out = static_cast<int64_t>(busy + 0.5);
  return true;
}

void AddSystem(EncodableMap& m) {
  wchar_t name[MAX_COMPUTERNAME_LENGTH + 1] = {0};
  DWORD name_len = MAX_COMPUTERNAME_LENGTH + 1;
  if (::GetComputerNameW(name, &name_len)) {
    m[EncodableValue("computer_name")] = EncodableValue(Narrow(name));
  }

  // Processor model from the registry — there is no Win32 call for the
  // marketing name, and __cpuid's brand string is the same value the kernel
  // put here at boot.
  HKEY key = nullptr;
  if (::RegOpenKeyExW(HKEY_LOCAL_MACHINE,
                      L"HARDWARE\\DESCRIPTION\\System\\CentralProcessor\\0", 0,
                      KEY_READ, &key) == ERROR_SUCCESS) {
    wchar_t cpu[256] = {0};
    DWORD size = sizeof(cpu);
    DWORD type = 0;
    if (::RegQueryValueExW(key, L"ProcessorNameString", nullptr, &type,
                           reinterpret_cast<LPBYTE>(cpu),
                           &size) == ERROR_SUCCESS &&
        type == REG_SZ) {
      std::string model = Narrow(cpu);
      // The registry value is padded with spaces on many CPUs.
      while (!model.empty() && model.back() == ' ') model.pop_back();
      if (!model.empty()) {
        m[EncodableValue("cpu_model")] = EncodableValue(model);
      }
    }
    ::RegCloseKey(key);
  }

  SYSTEM_INFO si{};
  ::GetSystemInfo(&si);
  if (si.dwNumberOfProcessors > 0) {
    m[EncodableValue("cpu_cores")] =
        EncodableValue(static_cast<int64_t>(si.dwNumberOfProcessors));
  }

  // Primary display adapter. DISPLAY_DEVICE_PRIMARY_DEVICE picks the one the
  // desktop is actually on rather than a disabled or virtual adapter.
  DISPLAY_DEVICEW dd{};
  dd.cb = sizeof(dd);
  for (DWORD i = 0; ::EnumDisplayDevicesW(nullptr, i, &dd, 0); ++i) {
    if (dd.StateFlags & DISPLAY_DEVICE_PRIMARY_DEVICE) {
      std::string gpu = Narrow(dd.DeviceString);
      if (!gpu.empty()) m[EncodableValue("gpu_name")] = EncodableValue(gpu);
      break;
    }
    dd.cb = sizeof(dd);
  }

  m[EncodableValue("uptime_seconds")] =
      EncodableValue(static_cast<int64_t>(::GetTickCount64() / 1000));
}

void AddMemory(EncodableMap& m) {
  MEMORYSTATUSEX mem{};
  mem.dwLength = sizeof(mem);
  if (!::GlobalMemoryStatusEx(&mem)) return;

  m[EncodableValue("ram_gb")] =
      EncodableValue(static_cast<int64_t>(mem.ullTotalPhys / kGb));
  m[EncodableValue("ram_free_gb")] =
      EncodableValue(static_cast<int64_t>(mem.ullAvailPhys / kGb));
  m[EncodableValue("ram_percent")] =
      EncodableValue(static_cast<int64_t>(mem.dwMemoryLoad));
}

void AddStorage(EncodableMap& m) {
  // The system drive keeps its own top-level key: it is what the roster shows
  // at a glance, and it predates this list.
  wchar_t sysdir[MAX_PATH] = {0};
  if (::GetWindowsDirectoryW(sysdir, MAX_PATH) >= 3) {
    sysdir[3] = L'\0';  // "C:\Windows" -> "C:\"
    ULARGE_INTEGER free_bytes{};
    if (::GetDiskFreeSpaceExW(sysdir, &free_bytes, nullptr, nullptr)) {
      m[EncodableValue("disk_free_gb")] =
          EncodableValue(static_cast<int64_t>(free_bytes.QuadPart / kGb));
    }
  }

  wchar_t drives[512] = {0};
  DWORD len = ::GetLogicalDriveStringsW(
      sizeof(drives) / sizeof(drives[0]) - 1, drives);
  if (len == 0) return;

  EncodableList list;
  for (const wchar_t* p = drives; *p != L'\0'; p += wcslen(p) + 1) {
    // Fixed disks only: a mapped network share or a plugged-in phone is not
    // this machine's storage and reporting it as such would be a lie about
    // whose disk is filling up.
    if (::GetDriveTypeW(p) != DRIVE_FIXED) continue;

    ULARGE_INTEGER free_bytes{}, total_bytes{};
    if (!::GetDiskFreeSpaceExW(p, &free_bytes, &total_bytes, nullptr)) continue;
    if (total_bytes.QuadPart == 0) continue;

    EncodableMap drive;
    drive[EncodableValue("letter")] = EncodableValue(Narrow(p));
    drive[EncodableValue("total_gb")] =
        EncodableValue(static_cast<int64_t>(total_bytes.QuadPart / kGb));
    drive[EncodableValue("free_gb")] =
        EncodableValue(static_cast<int64_t>(free_bytes.QuadPart / kGb));
    list.push_back(EncodableValue(drive));
  }

  if (!list.empty()) m[EncodableValue("drives")] = EncodableValue(list);
}

void AddOsVersion(EncodableMap& m) {
  // RtlGetVersion, because GetVersionEx has lied since Windows 8.1 unless the
  // exe carries a matching compatibility manifest.
  RTL_OSVERSIONINFOW vi{};
  vi.dwOSVersionInfoSize = sizeof(vi);
  HMODULE ntdll = ::GetModuleHandleW(L"ntdll.dll");
  if (ntdll == nullptr) return;
  auto rtl = reinterpret_cast<RtlGetVersionPtr>(
      ::GetProcAddress(ntdll, "RtlGetVersion"));
  if (rtl == nullptr || rtl(&vi) != 0) return;

  std::string name = vi.dwBuildNumber >= 22000 ? "Windows 11" : "Windows 10";
  m[EncodableValue("os_version")] =
      EncodableValue(name + " " + std::to_string(vi.dwBuildNumber));
}

void AddNetwork(EncodableMap& m) {
  // Wi-Fi SSID + signal. Independent of everything else; any failure just
  // leaves the keys absent.
  DWORD negotiated = 0;
  HANDLE wlan = nullptr;
  if (::WlanOpenHandle(2, nullptr, &negotiated, &wlan) == ERROR_SUCCESS) {
    PWLAN_INTERFACE_INFO_LIST list = nullptr;
    if (::WlanEnumInterfaces(wlan, nullptr, &list) == ERROR_SUCCESS && list &&
        list->dwNumberOfItems > 0) {
      GUID guid = list->InterfaceInfo[0].InterfaceGuid;
      PWLAN_CONNECTION_ATTRIBUTES attr = nullptr;
      DWORD size = 0;
      WLAN_OPCODE_VALUE_TYPE opcode;
      if (::WlanQueryInterface(wlan, &guid,
                               wlan_intf_opcode_current_connection, nullptr,
                               &size, reinterpret_cast<PVOID*>(&attr),
                               &opcode) == ERROR_SUCCESS &&
          attr && attr->isState == wlan_interface_state_connected) {
        const auto& ssid = attr->wlanAssociationAttributes.dot11Ssid;
        size_t ssid_len = std::min(static_cast<size_t>(ssid.uSSIDLength),
                                   sizeof(ssid.ucSSID));
        m[EncodableValue("wifi_ssid")] = EncodableValue(std::string(
            reinterpret_cast<const char*>(ssid.ucSSID), ssid_len));
        int quality =
            static_cast<int>(attr->wlanAssociationAttributes.wlanSignalQuality);
        m[EncodableValue("wifi_signal_dbm")] =
            EncodableValue(static_cast<int64_t>(quality / 2 - 100));
        m[EncodableValue("wifi_signal_percent")] =
            EncodableValue(static_cast<int64_t>(quality));
      }
      if (attr) ::WlanFreeMemory(attr);
    }
    if (list) ::WlanFreeMemory(list);
    ::WlanCloseHandle(wlan, nullptr);
  }

  // Local address + link type + traffic, from the first adapter that is UP,
  // is not loopback, and has a unicast IPv4. That is the one carrying this
  // machine's traffic; enumerating every virtual adapter a VPN or hypervisor
  // installed would bury it.
  //
  // The PUBLIC address is deliberately NOT read here. It cannot be had from
  // Win32 at all — it takes a round trip to some outside service — and the
  // server already sees it on every heartbeat, so asking the agent for it
  // would add a third-party dependency to learn something the other end of
  // the connection already knows.
  ULONG size = 16 * 1024;
  std::vector<uint8_t> buffer(size);
  auto* addresses = reinterpret_cast<IP_ADAPTER_ADDRESSES*>(buffer.data());
  ULONG result = ::GetAdaptersAddresses(
      AF_INET, GAA_FLAG_SKIP_ANYCAST | GAA_FLAG_SKIP_MULTICAST |
                   GAA_FLAG_SKIP_DNS_SERVER,
      nullptr, addresses, &size);
  if (result == ERROR_BUFFER_OVERFLOW) {
    buffer.assign(size, 0);
    addresses = reinterpret_cast<IP_ADAPTER_ADDRESSES*>(buffer.data());
    result = ::GetAdaptersAddresses(
        AF_INET, GAA_FLAG_SKIP_ANYCAST | GAA_FLAG_SKIP_MULTICAST |
                     GAA_FLAG_SKIP_DNS_SERVER,
        nullptr, addresses, &size);
  }
  if (result != NO_ERROR) return;

  for (auto* a = addresses; a != nullptr; a = a->Next) {
    if (a->OperStatus != IfOperStatusUp) continue;
    if (a->IfType == IF_TYPE_SOFTWARE_LOOPBACK) continue;
    if (a->FirstUnicastAddress == nullptr) continue;

    auto* sa = reinterpret_cast<sockaddr_in*>(
        a->FirstUnicastAddress->Address.lpSockaddr);
    char ip[INET_ADDRSTRLEN] = {0};
    if (::inet_ntop(AF_INET, &sa->sin_addr, ip, sizeof(ip)) == nullptr) {
      continue;
    }

    m[EncodableValue("local_ip")] = EncodableValue(std::string(ip));
    m[EncodableValue("connection_type")] = EncodableValue(std::string(
        a->IfType == IF_TYPE_IEEE80211 ? "WiFi" : "Ethernet"));

    MIB_IF_ROW2 row{};
    row.InterfaceLuid = a->Luid;
    if (::GetIfEntry2(&row) == NO_ERROR) {
      m[EncodableValue("net_sent_mb")] =
          EncodableValue(static_cast<int64_t>(row.OutOctets / kMb));
      m[EncodableValue("net_received_mb")] =
          EncodableValue(static_cast<int64_t>(row.InOctets / kMb));
    }
    break;
  }
}

// The programs a person actually has open: visible, top-level, titled, and
// not owned by another window (which excludes tool palettes and dialogs).
// This is what `open_apps` was always meant to hold — PC Specs has read the
// key since the day it shipped and NOTHING ever wrote it, so that section of
// the UI could never appear no matter what the machine was doing.
struct WindowScan {
  std::vector<std::string> apps;
};

// The executable behind a window, without its path or extension: "chrome",
// "OUTLOOK", "ezbizi". Deliberately the PROCESS and never the window title —
// a title carries what the person is reading ("EDIT JOB - Kowalski, 14 Elm
// St"), which is screen content wearing a hardware field's clothes. The one
// title this file reports is `active_window`, which is gated and exempted as
// screen content precisely because it is one.
std::string ProcessNameOf(HWND hwnd) {
  DWORD pid = 0;
  ::GetWindowThreadProcessId(hwnd, &pid);
  if (pid == 0) return std::string();

  HANDLE process = ::OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);
  if (process == nullptr) return std::string();

  wchar_t path[MAX_PATH] = {0};
  DWORD size = MAX_PATH;
  const BOOL ok = ::QueryFullProcessImageNameW(process, 0, path, &size);
  ::CloseHandle(process);
  if (!ok || size == 0) return std::string();

  std::wstring full(path, size);
  const size_t slash = full.find_last_of(L'\\');
  std::wstring leaf = slash == std::wstring::npos ? full : full.substr(slash + 1);
  const size_t dot = leaf.find_last_of(L'.');
  if (dot != std::wstring::npos) leaf = leaf.substr(0, dot);

  return Narrow(leaf.c_str());
}

BOOL CALLBACK CollectWindow(HWND hwnd, LPARAM param) {
  auto* scan = reinterpret_cast<WindowScan*>(param);

  if (!::IsWindowVisible(hwnd)) return TRUE;
  if (::GetWindow(hwnd, GW_OWNER) != nullptr) return TRUE;

  // Cloaked windows are the UWP ghosts that stay "visible" forever.
  BOOL cloaked = FALSE;
  if (SUCCEEDED(::DwmGetWindowAttribute(hwnd, DWMWA_CLOAKED, &cloaked,
                                        sizeof(cloaked))) &&
      cloaked) {
    return TRUE;
  }

  // A titleless top-level window is a helper, not something a person opened.
  if (::GetWindowTextLengthW(hwnd) <= 0) return TRUE;

  const std::string name = ProcessNameOf(hwnd);
  if (name.empty()) return TRUE;

  // One entry per program, however many windows it has open.
  if (std::find(scan->apps.begin(), scan->apps.end(), name) ==
      scan->apps.end()) {
    scan->apps.push_back(name);
  }
  return TRUE;
}

// ── Installed programs ────────────────────────────────────────────
//
// The three Uninstall keys Windows actually uses: 64-bit machine-wide,
// 32-bit machine-wide under WOW6432Node, and this user's own. A program can
// appear in more than one, so names are de-duplicated.
//
// Read ONCE per app launch and cached. The list changes when somebody
// installs something, which is not a thing that happens between two
// heartbeats, and walking several hundred registry keys every minute on the
// platform thread to re-learn the same answer would be the expensive kind of
// thorough.
// ── GPU load ──────────────────────────────────────────────────────
//
// There is no Win32 call for "how busy is the graphics card". Task Manager
// reads the same performance counters this does: `\GPU Engine(*)\Utilization
// Percentage`, one instance per process per engine, with names shaped like
//
//   pid_9312_luid_0x00000000_0x0000C7C1_phys_0_eng_0_engtype_3D
//
// Only the 3D engines are summed. A machine decoding video or running a
// display-compositing engine is not "using the GPU" in the sense somebody
// scanning a roster means, and summing every engine type double-counts work
// that overlaps — which is how a idle machine ends up reading 140%.
//
// Same two-sample shape as CpuPercentSinceLastCall and for the same reason:
// these are rate counters, so the FIRST call after launch establishes a
// baseline and reports nothing. An absent key renders as "—"; a zero would
// claim the card is idle when the truth is that nobody has asked it yet.
bool GpuPercentSinceLastCall(int64_t* out) {
  static bool tried = false;
  static PDH_HQUERY query = nullptr;
  static PDH_HCOUNTER counter = nullptr;
  static bool have_baseline = false;

  if (!tried) {
    tried = true;
    if (::PdhOpenQueryW(nullptr, 0, &query) != ERROR_SUCCESS) {
      query = nullptr;
    } else if (::PdhAddEnglishCounterW(
                   query, L"\\GPU Engine(*)\\Utilization Percentage", 0,
                   &counter) != ERROR_SUCCESS) {
      ::PdhCloseQuery(query);
      query = nullptr;
    }
  }

  if (query == nullptr) return false;
  if (::PdhCollectQueryData(query) != ERROR_SUCCESS) return false;

  if (!have_baseline) {
    have_baseline = true;
    return false;
  }

  DWORD size = 0;
  DWORD count = 0;
  if (::PdhGetFormattedCounterArrayW(counter, PDH_FMT_DOUBLE, &size, &count,
                                     nullptr) != PDH_MORE_DATA) {
    return false;
  }

  std::vector<uint8_t> buffer(size);
  auto* items =
      reinterpret_cast<PDH_FMT_COUNTERVALUE_ITEM_W*>(buffer.data());
  if (::PdhGetFormattedCounterArrayW(counter, PDH_FMT_DOUBLE, &size, &count,
                                     items) != ERROR_SUCCESS) {
    return false;
  }

  double total = 0;
  int matched = 0;
  for (DWORD i = 0; i < count; ++i) {
    if (items[i].szName == nullptr) continue;

    // Case-folded, because the counter says "engtype_3d" in lower case on a
    // real machine and a case-sensitive match on "3D" finds NOTHING — which
    // reads exactly like a machine with no 3D engine, so the meter would have
    // shown "—" for ever with nothing to debug. Windows does not promise the
    // casing of an instance name either way; measured on this box, not
    // assumed from the documentation, which writes it "engtype_3D".
    std::wstring instance(items[i].szName);
    std::transform(instance.begin(), instance.end(), instance.begin(),
                   ::towlower);
    if (instance.find(L"engtype_3d") == std::wstring::npos) continue;

    if (items[i].FmtValue.CStatus != PDH_CSTATUS_VALID_DATA &&
        items[i].FmtValue.CStatus != PDH_CSTATUS_NEW_DATA) {
      continue;
    }
    total += items[i].FmtValue.doubleValue;
    ++matched;
  }

  // NOT the same as a reading of zero: a machine whose counters name no 3D
  // engine has told us nothing, and reporting 0% would say its card is idle.
  if (matched == 0) return false;

  if (total < 0) total = 0;
  if (total > 100) total = 100;
  *out = static_cast<int64_t>(total + 0.5);
  return true;
}

void AddGpuLoad(EncodableMap& m) {
  int64_t percent = 0;
  if (GpuPercentSinceLastCall(&percent)) {
    m[EncodableValue("gpu_percent")] = EncodableValue(percent);
  }
}

// ── Security posture ──────────────────────────────────────────────
//
// What Windows' own Security Center says about this machine: antivirus,
// firewall, and whether updates are being installed. Read through WSC rather
// than by poking at Defender's registry keys or services, because WSC is the
// thing that knows about OTHER antivirus products too — a machine running
// Bitdefender with Defender correctly stood down is HEALTHY, and a check
// written against Defender's own state would report it as unprotected.
//
// Returns nullptr for a health value we do not recognise rather than guessing
// a word for it: an unknown state reported as "good" is the one failure here
// that actually matters.
const char* SecurityHealthWord(WSC_SECURITY_PROVIDER provider) {
  WSC_SECURITY_PROVIDER_HEALTH health =
      WSC_SECURITY_PROVIDER_HEALTH_NOTMONITORED;

  if (FAILED(::WscGetSecurityProviderHealth(provider, &health))) {
    return nullptr;
  }

  switch (health) {
    case WSC_SECURITY_PROVIDER_HEALTH_GOOD:
      return "good";
    case WSC_SECURITY_PROVIDER_HEALTH_NOTMONITORED:
      return "not_monitored";
    case WSC_SECURITY_PROVIDER_HEALTH_POOR:
      return "poor";
    case WSC_SECURITY_PROVIDER_HEALTH_SNOOZE:
      return "snoozed";
  }

  return nullptr;
}

void AddSecurity(EncodableMap& m) {
  EncodableMap security;

  auto put = [&security](const char* key, WSC_SECURITY_PROVIDER provider) {
    const char* word = SecurityHealthWord(provider);
    if (word != nullptr) {
      security[EncodableValue(key)] = EncodableValue(std::string(word));
    }
  };

  put("antivirus", WSC_SECURITY_PROVIDER_ANTIVIRUS);
  put("firewall", WSC_SECURITY_PROVIDER_FIREWALL);
  put("updates", WSC_SECURITY_PROVIDER_AUTOUPDATE_SETTINGS);

  // Absent rather than empty: WSC is a client-SKU API and is not there at all
  // on Windows Server, where reporting an empty security block would read as
  // "we looked and found nothing configured".
  if (!security.empty()) {
    m[EncodableValue("security")] = EncodableValue(security);
  }
}

void AddInstalledPrograms(EncodableMap& m) {
  static bool read = false;
  static std::vector<std::string> cached;

  if (!read) {
    read = true;

    struct Root {
      HKEY hive;
      const wchar_t* path;
      DWORD flag;
    };
    const Root roots[] = {
        {HKEY_LOCAL_MACHINE,
         L"SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall",
         KEY_WOW64_64KEY},
        {HKEY_LOCAL_MACHINE,
         L"SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall",
         KEY_WOW64_32KEY},
        {HKEY_CURRENT_USER,
         L"SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall", 0},
    };

    for (const Root& root : roots) {
      HKEY key = nullptr;
      if (::RegOpenKeyExW(root.hive, root.path, 0, KEY_READ | root.flag,
                          &key) != ERROR_SUCCESS) {
        continue;
      }

      for (DWORD i = 0;; ++i) {
        wchar_t name[256] = {0};
        DWORD name_len = 256;
        if (::RegEnumKeyExW(key, i, name, &name_len, nullptr, nullptr, nullptr,
                            nullptr) != ERROR_SUCCESS) {
          break;
        }

        HKEY entry = nullptr;
        if (::RegOpenKeyExW(key, name, 0, KEY_READ | root.flag, &entry) !=
            ERROR_SUCCESS) {
          continue;
        }

        // SystemComponent=1 is how Windows marks the runtimes and hotfixes it
        // does not show in Apps & Features either; matching that keeps the
        // list to things a person would recognise as installed software.
        DWORD system_component = 0;
        DWORD size = sizeof(system_component);
        DWORD type = 0;
        if (::RegQueryValueExW(entry, L"SystemComponent", nullptr, &type,
                               reinterpret_cast<LPBYTE>(&system_component),
                               &size) == ERROR_SUCCESS &&
            type == REG_DWORD && system_component == 1) {
          ::RegCloseKey(entry);
          continue;
        }

        wchar_t display[512] = {0};
        size = sizeof(display);
        type = 0;
        if (::RegQueryValueExW(entry, L"DisplayName", nullptr, &type,
                               reinterpret_cast<LPBYTE>(display),
                               &size) == ERROR_SUCCESS &&
            type == REG_SZ) {
          std::string program = Narrow(display);
          if (!program.empty() &&
              std::find(cached.begin(), cached.end(), program) ==
                  cached.end()) {
            cached.push_back(program);
          }
        }
        ::RegCloseKey(entry);
      }
      ::RegCloseKey(key);
    }

    std::sort(cached.begin(), cached.end());
  }

  if (cached.empty()) return;

  EncodableList list;
  for (const std::string& program : cached) {
    list.push_back(EncodableValue(program));
  }
  m[EncodableValue("installed_programs")] = EncodableValue(list);
}

// The executable behind a pid, for the connection list. Separate from
// ProcessNameOf(HWND) because a connection's owner usually has no window at
// all — a background updater is exactly the kind of thing worth seeing here.
std::string ProcessNameOfPid(DWORD pid) {
  if (pid == 0) return std::string();

  HANDLE process = ::OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);
  if (process == nullptr) return std::string();

  wchar_t path[MAX_PATH] = {0};
  DWORD size = MAX_PATH;
  const BOOL ok = ::QueryFullProcessImageNameW(process, 0, path, &size);
  ::CloseHandle(process);
  if (!ok || size == 0) return std::string();

  std::wstring full(path, size);
  const size_t slash = full.find_last_of(L'\\');
  std::wstring leaf = slash == std::wstring::npos ? full : full.substr(slash + 1);
  const size_t dot = leaf.find_last_of(L'.');
  if (dot != std::wstring::npos) leaf = leaf.substr(0, dot);

  return Narrow(leaf.c_str());
}

// ── Established connections ───────────────────────────────────────
//
// Which program is talking to which address, IPv4 and IPv6. ESTABLISHED only:
// a listening socket says what a machine is willing to accept, which is a
// different question and a much longer list.
void AddConnections(EncodableMap& m) {
  EncodableList list;
  // A machine with a browser open holds a lot of sockets; past a point this
  // is weight on every heartbeat rather than information.
  constexpr size_t kMax = 60;

  auto add = [&list](const std::string& process, const char* address,
                     uint16_t port) {
    if (list.size() >= kMax) return;
    EncodableMap row;
    row[EncodableValue("process")] = EncodableValue(process);
    row[EncodableValue("remote_address")] = EncodableValue(std::string(address));
    row[EncodableValue("remote_port")] = EncodableValue(static_cast<int64_t>(port));
    list.push_back(EncodableValue(row));
  };

  // IPv4.
  {
    ULONG size = 0;
    if (::GetExtendedTcpTable(nullptr, &size, FALSE, AF_INET,
                              TCP_TABLE_OWNER_PID_ALL, 0) ==
        ERROR_INSUFFICIENT_BUFFER) {
      std::vector<uint8_t> buffer(size);
      auto* table = reinterpret_cast<MIB_TCPTABLE_OWNER_PID*>(buffer.data());
      if (::GetExtendedTcpTable(table, &size, FALSE, AF_INET,
                                TCP_TABLE_OWNER_PID_ALL, 0) == NO_ERROR) {
        for (DWORD i = 0; i < table->dwNumEntries && list.size() < kMax; ++i) {
          const auto& row = table->table[i];
          if (row.dwState != MIB_TCP_STATE_ESTAB) continue;

          in_addr addr{};
          addr.S_un.S_addr = row.dwRemoteAddr;
          char text[INET_ADDRSTRLEN] = {0};
          if (::inet_ntop(AF_INET, &addr, text, sizeof(text)) == nullptr) {
            continue;
          }
          const std::string process = ProcessNameOfPid(row.dwOwningPid);
          if (process.empty()) continue;

          add(process, text, ::ntohs(static_cast<u_short>(row.dwRemotePort)));
        }
      }
    }
  }

  // IPv6. Same call, different family and a different row type — hence the
  // duplication rather than a template over two unrelated structs.
  {
    ULONG size = 0;
    if (::GetExtendedTcpTable(nullptr, &size, FALSE, AF_INET6,
                              TCP_TABLE_OWNER_PID_ALL, 0) ==
        ERROR_INSUFFICIENT_BUFFER) {
      std::vector<uint8_t> buffer(size);
      auto* table = reinterpret_cast<MIB_TCP6TABLE_OWNER_PID*>(buffer.data());
      if (::GetExtendedTcpTable(table, &size, FALSE, AF_INET6,
                                TCP_TABLE_OWNER_PID_ALL, 0) == NO_ERROR) {
        for (DWORD i = 0; i < table->dwNumEntries && list.size() < kMax; ++i) {
          const auto& row = table->table[i];
          if (row.dwState != MIB_TCP_STATE_ESTAB) continue;

          in6_addr addr{};
          memcpy(&addr, row.ucRemoteAddr, sizeof(addr));
          char text[INET6_ADDRSTRLEN] = {0};
          if (::inet_ntop(AF_INET6, &addr, text, sizeof(text)) == nullptr) {
            continue;
          }
          const std::string process = ProcessNameOfPid(row.dwOwningPid);
          if (process.empty()) continue;

          add(process, text, ::ntohs(static_cast<u_short>(row.dwRemotePort)));
        }
      }
    }
  }

  if (!list.empty()) m[EncodableValue("connections")] = EncodableValue(list);
}

// ── Browser tabs ──────────────────────────────────────────────────
//
// The one thing here that cannot be had cheaply. A tab is not a window, so
// there is no Win32 call for it: the titles come from UI Automation walking
// the browser's accessibility tree, which costs hundreds of milliseconds on a
// machine with a lot of tabs open.
//
// That cost is why this does NOT run inline. ReadSpecs is served on the
// platform thread, and half a second of frozen UI every minute would be a
// worse bug than the missing feature — so the scan runs on its own thread and
// leaves its answer in a cache that ReadSpecs copies. The first read after
// launch therefore has no tabs and reports the key as ABSENT rather than as
// an empty list: "nobody has any tabs open" and "we have not looked yet" are
// different facts and the roster is entitled to tell them apart.
std::mutex g_tabs_mutex;
std::vector<std::string> g_tabs;
bool g_tabs_known = false;
std::atomic<bool> g_tabs_scanning{false};

bool IsBrowserProcess(const std::string& name) {
  static const char* kBrowsers[] = {"chrome", "msedge", "firefox",
                                    "brave",  "opera",  "vivaldi"};
  for (const char* browser : kBrowsers) {
    if (_stricmp(name.c_str(), browser) == 0) return true;
  }
  return false;
}

BOOL CALLBACK CollectBrowserWindow(HWND hwnd, LPARAM param) {
  auto* windows = reinterpret_cast<std::vector<HWND>*>(param);
  if (windows->size() >= 6) return FALSE;

  if (!::IsWindowVisible(hwnd)) return TRUE;
  if (::GetWindow(hwnd, GW_OWNER) != nullptr) return TRUE;
  if (::GetWindowTextLengthW(hwnd) <= 0) return TRUE;
  if (!IsBrowserProcess(ProcessNameOf(hwnd))) return TRUE;

  windows->push_back(hwnd);
  return TRUE;
}

void ScanBrowserTabs() {
  std::vector<std::string> found;

  // MTA: this is a worker thread of our own, so it owns its apartment and
  // nothing else on it cares which model we pick.
  const HRESULT com = ::CoInitializeEx(nullptr, COINIT_MULTITHREADED);
  const bool owns_com = SUCCEEDED(com);

  if (owns_com || com == RPC_E_CHANGED_MODE) {
    ComPtr<IUIAutomation> automation;
    if (SUCCEEDED(::CoCreateInstance(CLSID_CUIAutomation, nullptr,
                                     CLSCTX_INPROC_SERVER,
                                     IID_PPV_ARGS(&automation))) &&
        automation) {
      std::vector<HWND> browsers;
      ::EnumWindows(CollectBrowserWindow,
                    reinterpret_cast<LPARAM>(&browsers));

      ComPtr<IUIAutomationCondition> is_tab;
      VARIANT tab_type;
      ::VariantInit(&tab_type);
      tab_type.vt = VT_I4;
      tab_type.lVal = UIA_TabItemControlTypeId;

      if (SUCCEEDED(automation->CreatePropertyCondition(
              UIA_ControlTypePropertyId, tab_type, &is_tab)) &&
          is_tab) {
        for (HWND browser : browsers) {
          ComPtr<IUIAutomationElement> root;
          if (FAILED(automation->ElementFromHandle(browser, &root)) || !root) {
            continue;
          }

          ComPtr<IUIAutomationElementArray> tabs;
          if (FAILED(root->FindAll(TreeScope_Descendants, is_tab.Get(),
                                   &tabs)) ||
              !tabs) {
            continue;
          }

          int count = 0;
          tabs->get_Length(&count);
          for (int i = 0; i < count && found.size() < 40; ++i) {
            ComPtr<IUIAutomationElement> tab;
            if (FAILED(tabs->GetElement(i, &tab)) || !tab) continue;

            BSTR name = nullptr;
            if (FAILED(tab->get_CurrentName(&name)) || name == nullptr) {
              continue;
            }
            std::string title = Narrow(name);
            ::SysFreeString(name);

            if (!title.empty() &&
                std::find(found.begin(), found.end(), title) == found.end()) {
              found.push_back(title);
            }
          }
        }
      }
      ::VariantClear(&tab_type);
    }
  }

  if (owns_com) ::CoUninitialize();

  {
    std::lock_guard<std::mutex> lock(g_tabs_mutex);
    g_tabs = std::move(found);
    g_tabs_known = true;
  }
  g_tabs_scanning = false;
}

void AddBrowserTabs(EncodableMap& m) {
  {
    std::lock_guard<std::mutex> lock(g_tabs_mutex);
    if (g_tabs_known) {
      EncodableList list;
      for (const std::string& title : g_tabs) {
        list.push_back(EncodableValue(title));
      }
      m[EncodableValue("browser_tabs")] = EncodableValue(list);
    }
  }

  // Kick the next scan, unless one is already running. `exchange` rather than
  // a read-then-write: two refreshes can overlap if one scan runs long, and
  // starting a second walk of the same trees would cost twice for one answer.
  if (!g_tabs_scanning.exchange(true)) {
    std::thread(ScanBrowserTabs).detach();
  }
}

void AddActivity(EncodableMap& m) {
  // How long since the last keystroke or mouse move, anywhere on the machine.
  LASTINPUTINFO input{};
  input.cbSize = sizeof(input);
  if (::GetLastInputInfo(&input)) {
    const ULONGLONG now = ::GetTickCount64();
    // GetTickCount64 and dwTime share an origin but not a width; the subtract
    // is done in 32 bits to survive the wrap the same way Windows does.
    const DWORD idle_ms = static_cast<DWORD>(now) - input.dwTime;
    m[EncodableValue("idle_seconds")] =
        EncodableValue(static_cast<int64_t>(idle_ms / 1000));
  }

  // Locked, screensaver-secured, or a UAC prompt owning the secure desktop —
  // the same probe the screen-capture path uses to refuse rather than hand
  // back a black frame. See monitoring_screenshot.cpp.
  HDESK desk = ::OpenInputDesktop(0, FALSE, DESKTOP_READOBJECTS);
  m[EncodableValue("screen_locked")] = EncodableValue(desk == nullptr);
  if (desk != nullptr) ::CloseDesktop(desk);

  WindowScan scan;
  ::EnumWindows(CollectWindow, reinterpret_cast<LPARAM>(&scan));

  if (!scan.apps.empty()) {
    EncodableList apps;
    // A busy machine can carry dozens; the roster shows a handful and the
    // rest is noise on the wire and in the row.
    const size_t limit = std::min<size_t>(scan.apps.size(), 25);
    for (size_t i = 0; i < limit; ++i) {
      apps.push_back(EncodableValue(scan.apps[i]));
    }
    m[EncodableValue("open_apps")] = EncodableValue(apps);
  }

  // The FOREGROUND window's title, which is the one thing here that can carry
  // the content of what somebody is reading — a document name, a customer, a
  // subject line. It is reported under its own key so the server and the UI
  // can gate it as screen content rather than as hardware, and so the Lead
  // Developer exemption can strip it without touching the rest of the specs.
  HWND foreground = ::GetForegroundWindow();
  if (foreground != nullptr) {
    wchar_t title[512] = {0};
    if (::GetWindowTextW(foreground, title, 512) > 0) {
      std::string narrowed = Narrow(title);
      if (!narrowed.empty()) {
        m[EncodableValue("active_window")] = EncodableValue(narrowed);
      }
    }
  }
}

EncodableMap ReadSpecs() {
  EncodableMap m;

  // Per-section timing rides along in `specs_section_ms`. A freeze is
  // environmental — a WLAN scan, a busy Security Center, a slow GPU-counter
  // enumeration — so it shows up on the machine that stalls, not on a dev box
  // where every section is a few ms. This is where the slow section is found:
  // in production, per machine, beside the total `specs_ms`.
  EncodableMap sections;
  auto timed = [&sections](const char* name, void (*fn)(EncodableMap&),
                           EncodableMap& out) {
    const auto start = std::chrono::steady_clock::now();
    fn(out);
    const auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(
                        std::chrono::steady_clock::now() - start)
                        .count();
    sections[EncodableValue(name)] = EncodableValue(static_cast<int64_t>(ms));
  };

  // Every block only ever ADDS keys, and only on success. A machine where one
  // probe fails reports everything else rather than nothing — which is why
  // none of these return a status and none of them can fail the read.
  timed("system", AddSystem, m);
  timed("memory", AddMemory, m);
  timed("storage", AddStorage, m);
  timed("os", AddOsVersion, m);
  timed("network", AddNetwork, m);
  timed("activity", AddActivity, m);
  timed("gpu", AddGpuLoad, m);
  timed("security", AddSecurity, m);
  timed("programs", AddInstalledPrograms, m);
  timed("connections", AddConnections, m);
  timed("browser_tabs", AddBrowserTabs, m);

  int64_t cpu = 0;
  if (CpuPercentSinceLastCall(&cpu)) {
    m[EncodableValue("cpu_percent")] = EncodableValue(cpu);
  }

  m[EncodableValue("specs_section_ms")] = EncodableValue(std::move(sections));

  return m;
}

}  // namespace

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringSpecsChannel(flutter::FlutterEngine* engine, HWND window) {
  auto channel = std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
      engine->messenger(), "com.ezbizi.app/monitoring_specs",
      &flutter::StandardMethodCodec::GetInstance());
  channel->SetMethodCallHandler(
      [window](const flutter::MethodCall<flutter::EncodableValue>& call,
               std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>>
                   result) {
        if (call.method_name() != "readSpecs") {
          result->NotImplemented();
          return;
        }
        // No new worker once the window has begun to close: its reply could
        // outlive the engine (platform_task.h).
        if (PlatformShuttingDown()) {
          result->Error("shutting_down", "The app is closing.");
          return;
        }

        // OFF THE PLATFORM THREAD. ReadSpecs makes a dozen blocking Win32 calls
        // (WLAN, PDH GPU counters, Security Center, the registry sweep, the TCP
        // table) and any one can stall; running it here would freeze the window.
        // A shared_ptr, not the unique_ptr the handler is given, because the
        // completion is carried in a std::function, which must be copyable.
        std::shared_ptr<flutter::MethodResult<flutter::EncodableValue>> shared =
            std::move(result);

        std::thread([shared, window, worker = std::make_shared<InFlightWorker>()]() {
          // Serialised: a stalled ReadSpecs must not overlap the next poll and
          // corrupt the per-call CPU/GPU deltas it keeps in statics. Waiting
          // here costs a worker thread, never the UI.
          static std::mutex read_mutex;
          std::lock_guard<std::mutex> lock(read_mutex);

          const auto start = std::chrono::steady_clock::now();
          EncodableMap specs = ReadSpecs();
          const auto elapsed =
              std::chrono::duration_cast<std::chrono::milliseconds>(
                  std::chrono::steady_clock::now() - start)
                  .count();

          // Rides the specs the heartbeat already sends, so production shows
          // how long the read took and on which machine.
          specs[EncodableValue("specs_ms")] =
              EncodableValue(static_cast<int64_t>(elapsed));

          PostTaskToPlatform(window, [shared, specs = std::move(specs)]() mutable {
            shared->Success(EncodableValue(std::move(specs)));
          });
        }).detach();
      });
  return channel;
}
