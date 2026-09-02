#include "win32_window.h"

#include <dwmapi.h>
#include <flutter_windows.h>
#include <shellapi.h>

#include "resource.h"

namespace {

// Our own message for notification-area callbacks. WM_APP and above are
// reserved for the application, so this cannot collide with anything Windows
// or Flutter sends.
constexpr UINT kTrayCallbackMessage = WM_APP + 1;
constexpr UINT kTrayIconId = 1;

// Menu command ids for the right-click menu.
constexpr UINT kTrayCommandOpen = 1001;
constexpr UINT kTrayCommandQuit = 1002;

/// Sent to every top-level window when Explorer restarts.
///
/// Not a nicety. Explorer crashes and restarts on its own, and when it does it
/// throws away the notification area — every icon in it disappears, including
/// ours. Without re-adding on this message the app becomes a running process
/// with no window and no icon, which is precisely the trap that made a tray
/// icon mandatory in the first place. Registered lazily on first use because
/// the string is only resolvable once at runtime.
UINT TaskbarCreatedMessage() {
  static const UINT id = RegisterWindowMessage(L"TaskbarCreated");

  return id;
}

/// Window attribute that enables dark mode window decorations.
///
/// Redefined in case the developer's machine has a Windows SDK older than
/// version 10.0.22000.0.
/// See: https://docs.microsoft.com/windows/win32/api/dwmapi/ne-dwmapi-dwmwindowattribute
#ifndef DWMWA_USE_IMMERSIVE_DARK_MODE
#define DWMWA_USE_IMMERSIVE_DARK_MODE 20
#endif

constexpr const wchar_t kWindowClassName[] = L"FLUTTER_RUNNER_WIN32_WINDOW";

/// Registry key for app theme preference.
///
/// A value of 0 indicates apps should use dark mode. A non-zero or missing
/// value indicates apps should use light mode.
constexpr const wchar_t kGetPreferredBrightnessRegKey[] =
  L"Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize";
constexpr const wchar_t kGetPreferredBrightnessRegValue[] = L"AppsUseLightTheme";

// The number of Win32Window objects that currently exist.
static int g_active_window_count = 0;

using EnableNonClientDpiScaling = BOOL __stdcall(HWND hwnd);

// Scale helper to convert logical scaler values to physical using passed in
// scale factor
int Scale(int source, double scale_factor) {
  return static_cast<int>(source * scale_factor);
}

// Dynamically loads the |EnableNonClientDpiScaling| from the User32 module.
// This API is only needed for PerMonitor V1 awareness mode.
void EnableFullDpiSupportIfAvailable(HWND hwnd) {
  HMODULE user32_module = LoadLibraryA("User32.dll");
  if (!user32_module) {
    return;
  }
  auto enable_non_client_dpi_scaling =
      reinterpret_cast<EnableNonClientDpiScaling*>(
          GetProcAddress(user32_module, "EnableNonClientDpiScaling"));
  if (enable_non_client_dpi_scaling != nullptr) {
    enable_non_client_dpi_scaling(hwnd);
  }
  FreeLibrary(user32_module);
}

}  // namespace

// Manages the Win32Window's window class registration.
class WindowClassRegistrar {
 public:
  ~WindowClassRegistrar() = default;

  // Returns the singleton registrar instance.
  static WindowClassRegistrar* GetInstance() {
    if (!instance_) {
      instance_ = new WindowClassRegistrar();
    }
    return instance_;
  }

  // Returns the name of the window class, registering the class if it hasn't
  // previously been registered.
  const wchar_t* GetWindowClass();

  // Unregisters the window class. Should only be called if there are no
  // instances of the window.
  void UnregisterWindowClass();

 private:
  WindowClassRegistrar() = default;

  static WindowClassRegistrar* instance_;

  bool class_registered_ = false;
};

WindowClassRegistrar* WindowClassRegistrar::instance_ = nullptr;

const wchar_t* WindowClassRegistrar::GetWindowClass() {
  if (!class_registered_) {
    WNDCLASS window_class{};
    window_class.hCursor = LoadCursor(nullptr, IDC_ARROW);
    window_class.lpszClassName = kWindowClassName;
    window_class.style = CS_HREDRAW | CS_VREDRAW;
    window_class.cbClsExtra = 0;
    window_class.cbWndExtra = 0;
    window_class.hInstance = GetModuleHandle(nullptr);
    window_class.hIcon =
        LoadIcon(window_class.hInstance, MAKEINTRESOURCE(IDI_APP_ICON));
    window_class.hbrBackground = 0;
    window_class.lpszMenuName = nullptr;
    window_class.lpfnWndProc = Win32Window::WndProc;
    RegisterClass(&window_class);
    class_registered_ = true;
  }
  return kWindowClassName;
}

void WindowClassRegistrar::UnregisterWindowClass() {
  UnregisterClass(kWindowClassName, nullptr);
  class_registered_ = false;
}

Win32Window::Win32Window() {
  ++g_active_window_count;
}

Win32Window::~Win32Window() {
  --g_active_window_count;
  Destroy();
}

bool Win32Window::Create(const std::wstring& title,
                         const Point& origin,
                         const Size& size) {
  Destroy();

  const wchar_t* window_class =
      WindowClassRegistrar::GetInstance()->GetWindowClass();

  const POINT target_point = {static_cast<LONG>(origin.x),
                              static_cast<LONG>(origin.y)};
  HMONITOR monitor = MonitorFromPoint(target_point, MONITOR_DEFAULTTONEAREST);
  UINT dpi = FlutterDesktopGetDpiForMonitor(monitor);
  double scale_factor = dpi / 96.0;

  HWND window = CreateWindow(
      window_class, title.c_str(), WS_OVERLAPPEDWINDOW,
      Scale(origin.x, scale_factor), Scale(origin.y, scale_factor),
      Scale(size.width, scale_factor), Scale(size.height, scale_factor),
      nullptr, nullptr, GetModuleHandle(nullptr), this);

  if (!window) {
    return false;
  }

  UpdateTheme(window);

  return OnCreate();
}

bool Win32Window::Show() {
  return ShowWindow(window_handle_, SW_SHOWNORMAL);
}

// static
LRESULT CALLBACK Win32Window::WndProc(HWND const window,
                                      UINT const message,
                                      WPARAM const wparam,
                                      LPARAM const lparam) noexcept {
  if (message == WM_NCCREATE) {
    auto window_struct = reinterpret_cast<CREATESTRUCT*>(lparam);
    SetWindowLongPtr(window, GWLP_USERDATA,
                     reinterpret_cast<LONG_PTR>(window_struct->lpCreateParams));

    auto that = static_cast<Win32Window*>(window_struct->lpCreateParams);
    EnableFullDpiSupportIfAvailable(window);
    that->window_handle_ = window;
  } else if (Win32Window* that = GetThisFromHandle(window)) {
    return that->MessageHandler(window, message, wparam, lparam);
  }

  return DefWindowProc(window, message, wparam, lparam);
}

LRESULT
Win32Window::MessageHandler(HWND hwnd,
                            UINT const message,
                            WPARAM const wparam,
                            LPARAM const lparam) noexcept {
  // Explorer restarted and threw the notification area away with it. Put the
  // icon back, or the app is running with no way to reach it.
  if (message == TaskbarCreatedMessage()) {
    tray_added_ = false;
    AddTrayIcon();

    return 0;
  }

  switch (message) {
    /*
     * The X hides; it does not quit. See win32_window.h for why.
     *
     * `really_quitting_` is the whole safety of this. Windows sends WM_CLOSE
     * for more than somebody clicking X: a logoff, a shutdown, Task Manager's
     * "End task", and — the one that matters most here — the Restart Manager
     * that our own installer drives with /CLOSEAPPLICATIONS when the in-app
     * updater runs it. If WM_CLOSE always meant hide, the updater would ask the
     * app to close, the app would hide instead, and the update would fail on
     * files it could not replace. So the shutdown messages below set the flag
     * first, and then this case lets the close through.
     */
    case WM_CLOSE:
      /*
       * Hide only when there is a visible window to hide.
       *
       * Already hidden and asked to close again means it was not a person
       * clicking X — nobody can click the X of a window they cannot see. It is
       * something else asking us to go away, and the case that matters is the
       * Restart Manager: it is documented to send the session messages below to
       * anything with a message queue, but if it ever reaches only for WM_CLOSE
       * then swallowing it would leave our own updater unable to replace files
       * it has the app sitting on. Letting a close through to an already
       * invisible window costs nothing and closes that hole.
       */
      if (!really_quitting_ && IsWindowVisible(window_handle_)) {
        AddTrayIcon();
        ShowWindow(window_handle_, SW_HIDE);

        return 0;
      }

      really_quitting_ = true;
      RemoveTrayIcon();
      break;

    // Windows asking whether it may end the session, and then telling us it
    // is. Both mean this is not a click on X, so stop intercepting the close.
    case WM_QUERYENDSESSION:
      really_quitting_ = true;
      return TRUE;

    case WM_ENDSESSION:
      really_quitting_ = true;
      break;

    case kTrayCallbackMessage:
      switch (LOWORD(lparam)) {
        case WM_LBUTTONDBLCLK:
        case WM_LBUTTONUP:
          ShowFromTray();
          return 0;

        case WM_RBUTTONUP:
        case WM_CONTEXTMENU:
          ShowTrayMenu();
          return 0;
      }
      return 0;

    case WM_COMMAND:
      switch (LOWORD(wparam)) {
        case kTrayCommandOpen:
          ShowFromTray();
          return 0;

        case kTrayCommandQuit:
          RequestQuit();
          return 0;
      }
      break;

    case WM_DESTROY:
      window_handle_ = nullptr;
      Destroy();
      if (quit_on_close_) {
        PostQuitMessage(0);
      }
      return 0;

    case WM_DPICHANGED: {
      auto newRectSize = reinterpret_cast<RECT*>(lparam);
      LONG newWidth = newRectSize->right - newRectSize->left;
      LONG newHeight = newRectSize->bottom - newRectSize->top;

      SetWindowPos(hwnd, nullptr, newRectSize->left, newRectSize->top, newWidth,
                   newHeight, SWP_NOZORDER | SWP_NOACTIVATE);

      return 0;
    }
    case WM_SIZE: {
      RECT rect = GetClientArea();
      if (child_content_ != nullptr) {
        // Size and position the child window.
        MoveWindow(child_content_, rect.left, rect.top, rect.right - rect.left,
                   rect.bottom - rect.top, TRUE);
      }
      return 0;
    }

    case WM_ACTIVATE:
      if (child_content_ != nullptr) {
        SetFocus(child_content_);
      }
      return 0;

    case WM_DWMCOLORIZATIONCOLORCHANGED:
      UpdateTheme(hwnd);
      return 0;
  }

  return DefWindowProc(window_handle_, message, wparam, lparam);
}

void Win32Window::AddTrayIcon() {
  if (tray_added_ || !window_handle_) {
    return;
  }

  NOTIFYICONDATA data = {};
  data.cbSize = sizeof(data);
  data.hWnd = window_handle_;
  data.uID = kTrayIconId;
  data.uFlags = NIF_ICON | NIF_MESSAGE | NIF_TIP;
  data.uCallbackMessage = kTrayCallbackMessage;
  // The same icon the taskbar and Alt-Tab use, from Runner.rc, so there is one
  // place to change it and no second asset to forget.
  data.hIcon = LoadIcon(GetModuleHandle(nullptr), MAKEINTRESOURCE(IDI_APP_ICON));
  wcscpy_s(data.szTip, L"EzBizi");

  tray_added_ = Shell_NotifyIcon(NIM_ADD, &data) == TRUE;
}

void Win32Window::RemoveTrayIcon() {
  if (!tray_added_) {
    return;
  }

  NOTIFYICONDATA data = {};
  data.cbSize = sizeof(data);
  data.hWnd = window_handle_;
  data.uID = kTrayIconId;
  Shell_NotifyIcon(NIM_DELETE, &data);
  tray_added_ = false;
}

void Win32Window::ShowFromTray() {
  if (!window_handle_) {
    return;
  }

  ShowWindow(window_handle_, SW_SHOW);

  // Restore first if it was minimised, or showing leaves it on the taskbar
  // still minimised and the click appears to have done nothing.
  if (IsIconic(window_handle_)) {
    ShowWindow(window_handle_, SW_RESTORE);
  }

  SetForegroundWindow(window_handle_);
}

void Win32Window::RequestQuit() {
  really_quitting_ = true;
  RemoveTrayIcon();

  if (window_handle_) {
    // Through the normal path, so Flutter's engine shuts down the way it
    // expects rather than having the process pulled out from under it.
    PostMessage(window_handle_, WM_CLOSE, 0, 0);
  }
}

void Win32Window::SetControlledNotice(bool active) {
  // Reuse the ONE icon the close-to-tray behaviour already owns — never a
  // second one. There is nothing to modify when no icon is present (a
  // headless/service run never added one, and Explorer may not have created
  // the notification area yet): guard and no-op so this can never crash there.
  if (!tray_added_ || !window_handle_) {
    controlled_notice_shown_ = active;
    return;
  }

  NOTIFYICONDATA data = {};
  data.cbSize = sizeof(data);
  data.hWnd = window_handle_;
  data.uID = kTrayIconId;

  if (active) {
    data.uFlags = NIF_TIP;
    wcscpy_s(data.szTip, L"Your screen is being controlled by your company");

    // Balloon only on the way IN — a repeated setControlled(true) from a poll
    // that still sees the same state must not pop a fresh balloon each time.
    if (!controlled_notice_shown_) {
      data.uFlags |= NIF_INFO;
      data.dwInfoFlags = NIIF_INFO;
      wcscpy_s(data.szInfoTitle, L"EzBizi");
      wcscpy_s(data.szInfo, L"Your screen is being controlled by your company.");
    }

    controlled_notice_shown_ = true;
  } else {
    // Back to the ordinary tooltip; no balloon on the way out.
    data.uFlags = NIF_TIP;
    wcscpy_s(data.szTip, L"EzBizi");
    controlled_notice_shown_ = false;
  }

  Shell_NotifyIcon(NIM_MODIFY, &data);
}

void Win32Window::ShowTrayMenu() {
  HMENU menu = CreatePopupMenu();

  if (!menu) {
    return;
  }

  AppendMenu(menu, MF_STRING, kTrayCommandOpen, L"Open EzBizi");
  AppendMenu(menu, MF_SEPARATOR, 0, nullptr);
  AppendMenu(menu, MF_STRING, kTrayCommandQuit, L"Quit EzBizi");

  POINT cursor;
  GetCursorPos(&cursor);

  // Required, and easy to miss: without foregrounding first, the menu does not
  // dismiss when you click elsewhere and sits on top of everything.
  SetForegroundWindow(window_handle_);
  TrackPopupMenu(menu, TPM_RIGHTBUTTON, cursor.x, cursor.y, 0, window_handle_,
                 nullptr);
  DestroyMenu(menu);
}

void Win32Window::Destroy() {
  RemoveTrayIcon();
  OnDestroy();

  if (window_handle_) {
    DestroyWindow(window_handle_);
    window_handle_ = nullptr;
  }
  if (g_active_window_count == 0) {
    WindowClassRegistrar::GetInstance()->UnregisterWindowClass();
  }
}

Win32Window* Win32Window::GetThisFromHandle(HWND const window) noexcept {
  return reinterpret_cast<Win32Window*>(
      GetWindowLongPtr(window, GWLP_USERDATA));
}

void Win32Window::SetChildContent(HWND content) {
  child_content_ = content;
  SetParent(content, window_handle_);
  RECT frame = GetClientArea();

  MoveWindow(content, frame.left, frame.top, frame.right - frame.left,
             frame.bottom - frame.top, true);

  SetFocus(child_content_);
}

RECT Win32Window::GetClientArea() {
  RECT frame;
  GetClientRect(window_handle_, &frame);
  return frame;
}

HWND Win32Window::GetHandle() {
  return window_handle_;
}

void Win32Window::SetQuitOnClose(bool quit_on_close) {
  quit_on_close_ = quit_on_close;
}

bool Win32Window::OnCreate() {
  // No-op; provided for subclasses.
  return true;
}

void Win32Window::OnDestroy() {
  // No-op; provided for subclasses.
}

void Win32Window::UpdateTheme(HWND const window) {
  DWORD light_mode;
  DWORD light_mode_size = sizeof(light_mode);
  LSTATUS result = RegGetValue(HKEY_CURRENT_USER, kGetPreferredBrightnessRegKey,
                               kGetPreferredBrightnessRegValue,
                               RRF_RT_REG_DWORD, nullptr, &light_mode,
                               &light_mode_size);

  if (result == ERROR_SUCCESS) {
    BOOL enable_dark_mode = light_mode == 0;
    DwmSetWindowAttribute(window, DWMWA_USE_IMMERSIVE_DARK_MODE,
                          &enable_dark_mode, sizeof(enable_dark_mode));
  }
}
