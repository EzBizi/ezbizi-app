#ifndef RUNNER_PLATFORM_TASK_H_
#define RUNNER_PLATFORM_TASK_H_

#include <windows.h>

#include <functional>
#include <utility>

// Marshal a completion back onto the PLATFORM THREAD.
//
// A Flutter Windows method-channel result (`result->Success` / `Error`) must be
// invoked on the platform thread — the one that pumps the window's Win32
// messages. The monitoring handlers used to do their slow Win32 work (ReadSpecs,
// the screenshot capture) inline on that thread, so a stalled WLAN, GPU-counter
// or Security-Center call froze the window: unclickable, then white once Windows
// ghosted it. Users reported it and so did Jose (2026-10-06).
//
// The fix runs that work on a detached worker thread and posts the completion
// back here: [PostTaskToPlatform] queues a `std::function<void()>` as a window
// message, and [FlutterWindow::MessageHandler] runs it when the platform thread
// next pumps — which is exactly where `result->Success` is allowed to be called.
//
// On shutdown the window is gone, so PostMessage fails and the task is dropped
// rather than leaked (the result is abandoned, which is correct when the app is
// closing). The small race where a task is queued just as the engine tears down
// is accepted: it needs a read in flight at the instant of close, and the worst
// case is a dropped reply, not a frozen UI.
inline constexpr UINT kWmRunOnPlatform = WM_APP + 0x11;

inline void PostTaskToPlatform(HWND hwnd, std::function<void()> task) {
  if (hwnd == nullptr) {
    return;
  }

  auto* heap = new std::function<void()>(std::move(task));
  if (!PostMessage(hwnd, kWmRunOnPlatform, 0,
                   reinterpret_cast<LPARAM>(heap))) {
    // The window is going away; don't leak the completion.
    delete heap;
  }
}

#endif  // RUNNER_PLATFORM_TASK_H_
