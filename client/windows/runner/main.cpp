#include <flutter/dart_project.h>
#include <flutter/flutter_view_controller.h>
#include <windows.h>

#include "flutter_window.h"
#include "win32_window.h"
#include "utils.h"

int APIENTRY wWinMain(_In_ HINSTANCE instance, _In_opt_ HINSTANCE prev,
                      _In_ wchar_t *command_line, _In_ int show_command) {
  // Attach to console when present (e.g., 'flutter run') or create a
  // new console when running with a debugger.
  if (!::AttachConsole(ATTACH_PARENT_PROCESS) && ::IsDebuggerPresent()) {
    CreateAndAttachConsole();
  }

  // Initialize COM, so that it is available for use in the library and/or
  // plugins.
  ::CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);

  flutter::DartProject project(L"data");

  std::vector<std::string> command_line_arguments =
      GetCommandLineArguments();

  project.set_dart_entrypoint_arguments(std::move(command_line_arguments));

  // One EzBizi at a time. A second launch - the taskbar icon, the Start menu,
  // a second click on the shortcut - used to open a second full copy with its
  // own softphone registration, so calls rang in two places and the updater
  // could run twice. The mutex is held for the life of the process and the OS
  // releases it on exit, so a crash cannot leave it behind. An unprefixed name
  // lives in this Windows session's namespace; another user gets their own.
  HANDLE instance_mutex =
      ::CreateMutexW(nullptr, TRUE, L"EzBizi.Desktop.SingleInstance");
  if (instance_mutex != nullptr && ::GetLastError() == ERROR_ALREADY_EXISTS) {
    // Bring the running one forward instead of making another. Class AND
    // title, because every Flutter desktop app on the machine shares the class.
    HWND existing = ::FindWindowW(L"FLUTTER_RUNNER_WIN32_WINDOW", L"EzBizi");
    if (existing != nullptr) {
      /*
       * Ask the running copy to show itself, rather than showing it from here.
       *
       * Jose, 2026-09-17: with the window CLOSED to the tray, clicking the
       * taskbar button did nothing and only the notification-area icon would
       * bring the app back. This is why. Close-to-tray hides the window
       * (WM_CLOSE -> hide, win32_window.cpp), so it is NOT VISIBLE and NOT
       * ICONIC -- and what stood here restored an iconic window and nothing
       * else, then called SetForegroundWindow on a window that cannot be
       * foregrounded because it is invisible. Both calls succeeded and the
       * app stayed hidden.
       *
       * The fix is not the missing SW_SHOW. ShowFromTray() already does all
       * three steps in the right order, and the tray menu has been using it
       * the whole time; this was a SECOND copy of that sequence, in another
       * process, with one line missing. So post the command instead and let
       * the owning process run the one path, which cannot drift from itself.
       */
      ::PostMessageW(existing, WM_COMMAND, Win32Window::kCommandShowFromTray, 0);
    }
    ::CloseHandle(instance_mutex);
    ::CoUninitialize();
    return EXIT_SUCCESS;
  }

  FlutterWindow window(project);
  Win32Window::Point origin(10, 10);
  Win32Window::Size size(1280, 720);
  if (!window.Create(L"EzBizi", origin, size)) {
    return EXIT_FAILURE;
  }
  window.SetQuitOnClose(true);

  ::MSG msg;
  while (::GetMessage(&msg, nullptr, 0, 0)) {
    ::TranslateMessage(&msg);
    ::DispatchMessage(&msg);
  }

  ::CoUninitialize();
  return EXIT_SUCCESS;
}
