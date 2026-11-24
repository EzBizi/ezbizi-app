#ifndef RUNNER_WIN32_WINDOW_H_
#define RUNNER_WIN32_WINDOW_H_

#include <windows.h>

#include <functional>
#include <memory>
#include <string>

// A class abstraction for a high DPI-aware Win32 Window. Intended to be
// inherited from by classes that wish to specialize with custom
// rendering and input handling
class Win32Window {
 public:
  struct Point {
    unsigned int x;
    unsigned int y;
    Point(unsigned int x, unsigned int y) : x(x), y(y) {}
  };

  struct Size {
    unsigned int width;
    unsigned int height;
    Size(unsigned int width, unsigned int height)
        : width(width), height(height) {}
  };

  Win32Window();
  virtual ~Win32Window();

  // Creates a win32 window with |title| that is positioned and sized using
  // |origin| and |size|. New windows are created on the default monitor. Window
  // sizes are specified to the OS in physical pixels, hence to ensure a
  // consistent size this function will scale the inputted width and height as
  // as appropriate for the default monitor. The window is invisible until
  // |Show| is called. Returns true if the window was created successfully.
  bool Create(const std::wstring& title, const Point& origin, const Size& size);

  // Show the current window. Returns true if the window was successfully shown.
  bool Show();

  // Release OS resources associated with window.
  void Destroy();

  // Inserts |content| into the window tree.
  void SetChildContent(HWND content);

  // Returns the backing Window handle to enable clients to set icon and other
  // window properties. Returns nullptr if the window has been destroyed.
  HWND GetHandle();

  // If true, closing this window will quit the application.
  void SetQuitOnClose(bool quit_on_close);

  /*
   * Closing hides to the notification area instead of quitting.
   *
   * EzBizi is open all day and gets closed by reflex, and quitting on that
   * loses the session, the sidebar state and anything half-typed. So the X
   * hides the window and the app keeps running, which is what Teams, Slack and
   * every other tool of this shape do.
   *
   * A tray icon is not decoration here, it is the requirement. Hiding a window
   * with no way to bring it back leaves an app that is running, invisible, and
   * only killable through Task Manager — strictly worse than quitting. So the
   * icon is added as soon as the window exists - so it is up for the whole
   * run, not just from the first hide - and it carries the only two commands
   * that matter: open it, or actually quit.
   */
  void ShowFromTray();

  /* The tray menu's "Open EzBizi" command, as a WM_COMMAND id.
   *
   * Public because a SECOND process needs it. The single-instance handler
   * in main.cpp cannot call ShowFromTray() -- the window belongs to the
   * running copy -- so it posts this and the owning process runs the one
   * show path. It used to reimplement the show itself and drifted: it
   * restored a MINIMISED window and did nothing for a HIDDEN one, which is
   * the state close-to-tray leaves behind. So clicking the taskbar button
   * did nothing while the tray menu worked. Two copies of a three-line
   * sequence is all it took.
   */
  static constexpr UINT kCommandShowFromTray = 1001;

  // Really quit; posted by the tray's Quit and, in a second window, when the
  // first one has gone (main.cpp).
  static constexpr UINT kCommandQuit = 1002;

  // A second EzBizi window (Jose, 2026-11-24; lib/core/app_window.dart): the
  // app started it with EZBIZI_WINDOW set. It has no tray icon, never saves
  // or restores the placement, and its X closes it instead of hiding.
  static bool IsSecondWindow();

  // Really exit, as opposed to hiding. Used by the tray menu and by Windows
  // when it is shutting down or an installer has asked us to close.
  void RequestQuit();

  /*
   * Raise or clear the "your screen is being controlled" notice on the ONE
   * tray icon this window already owns — the monitoring agent calls it via the
   * com.ezbizi.app/monitoring_indicator channel while a manager is remotely
   * controlling this machine.
   *
   * active=true swaps the tray tooltip to the controlled message and, on the
   * transition into controlled, shows a balloon saying the same; active=false
   * restores the normal "EzBizi" tooltip and shows nothing. It reuses the
   * existing NOTIFYICONDATA (NIM_MODIFY) rather than adding a second icon, and
   * is a safe no-op when no icon is present — a headless/service run that never
   * added one — so it can never crash there.
   */
  void SetControlledNotice(bool active);

  // Return a RECT representing the bounds of the current client area.
  RECT GetClientArea();

 protected:
  // Processes and route salient window messages for mouse handling,
  // size change and DPI. Delegates handling of these to member overloads that
  // inheriting classes can handle.
  virtual LRESULT MessageHandler(HWND window,
                                 UINT const message,
                                 WPARAM const wparam,
                                 LPARAM const lparam) noexcept;

  // Called when CreateAndShow is called, allowing subclass window-related
  // setup. Subclasses should return false if setup fails.
  virtual bool OnCreate();

  // The tray's "New window". Dart opens it (lib/core/app_window.dart), since
  // only Dart holds a session that was not kept on disk to hand it.
  virtual void OnNewWindowRequested() {}

  // Called when Destroy is called.
  virtual void OnDestroy();

 private:
  friend class WindowClassRegistrar;

  // OS callback called by message pump. Handles the WM_NCCREATE message which
  // is passed when the non-client area is being created and enables automatic
  // non-client DPI scaling so that the non-client area automatically
  // responds to changes in DPI. All other messages are handled by
  // MessageHandler.
  static LRESULT CALLBACK WndProc(HWND const window,
                                  UINT const message,
                                  WPARAM const wparam,
                                  LPARAM const lparam) noexcept;

  // Retrieves a class instance pointer for |window|
  static Win32Window* GetThisFromHandle(HWND const window) noexcept;

  // Update the window frame's theme to match the system theme.
  static void UpdateTheme(HWND const window);

  // Put the icon in the notification area, or take it out again.
  void AddTrayIcon();
  void RemoveTrayIcon();

  // The right-click menu: open, and quit.
  void ShowTrayMenu();

  bool quit_on_close_ = false;

  // True once something has asked to genuinely exit — the tray's Quit, or
  // Windows shutting down. Without it, WM_CLOSE can never mean close and the
  // process would survive a logoff and an installer alike.
  bool really_quitting_ = false;

  // Whether the notification-area icon is currently registered.
  bool tray_added_ = false;

  // Whether the "being controlled" notice is currently up. Guards the balloon
  // so it fires once on the transition into controlled, not on every poll that
  // re-asserts the same state.
  bool controlled_notice_shown_ = false;

  // window handle for top level window.
  HWND window_handle_ = nullptr;

  // window handle for hosted content.
  HWND child_content_ = nullptr;
};

#endif  // RUNNER_WIN32_WINDOW_H_
