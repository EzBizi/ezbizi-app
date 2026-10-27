#ifndef RUNNER_PLATFORM_TASK_H_
#define RUNNER_PLATFORM_TASK_H_

#include <windows.h>

#include <atomic>
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
// SHUTDOWN (2026-10-27). A completion carries the channel's MethodResult, and
// destroying an unanswered result SENDS an empty reply through the engine's
// messenger. Once the engine is gone that is a call into freed engine state —
// an access violation inside flutter_windows.dll. So:
//  * FlutterWindow::OnDestroy sets [PlatformShuttingDown] first, waits
//    (bounded) for [InFlightWorkers] to reach zero while the engine is still
//    alive to take their replies, and only then destroys the engine;
//  * a handler asked to start work after that answers an error at once;
//  * a completion that can no longer be posted (the window is gone) is
//    deliberately LEAKED, never deleted: its destructor would be that reply.
//    The process is exiting; the bytes go with it.
inline constexpr UINT kWmRunOnPlatform = WM_APP + 0x11;

inline std::atomic<bool>& PlatformShuttingDown() {
  static std::atomic<bool> flag{false};
  return flag;
}

inline std::atomic<int>& InFlightWorkers() {
  static std::atomic<int> count{0};
  return count;
}

// Held by a worker thread for its whole life, so OnDestroy can wait for it.
struct InFlightWorker {
  InFlightWorker() { ++InFlightWorkers(); }
  ~InFlightWorker() { --InFlightWorkers(); }
  InFlightWorker(const InFlightWorker&) = delete;
  InFlightWorker& operator=(const InFlightWorker&) = delete;
};

inline void PostTaskToPlatform(HWND hwnd, std::function<void()> task) {
  if (hwnd == nullptr) {
    return;
  }

  auto* heap = new std::function<void()>(std::move(task));
  if (!PostMessage(hwnd, kWmRunOnPlatform, 0,
                   reinterpret_cast<LPARAM>(heap))) {
    // The window is gone: leak the completion on purpose (see above) —
    // deleting it would reply through an engine that no longer exists.
    (void)heap;
  }
}

#endif  // RUNNER_PLATFORM_TASK_H_
