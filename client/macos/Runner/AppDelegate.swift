import Cocoa
import FlutterMacOS
import UserNotifications

@main
class AppDelegate: FlutterAppDelegate {
  // The macOS half of the same problem the iOS AppDelegate documents: without a
  // delegate, a notification raised while EzBizi is the frontmost app is
  // delivered to Notification Centre and never shown as a banner. On a desktop
  // the app is frontmost most of the time somebody is using it.
  override func applicationDidFinishLaunching(_ notification: Notification) {
    UNUserNotificationCenter.current().delegate =
      self as? UNUserNotificationCenterDelegate

    super.applicationDidFinishLaunching(notification)
  }

  override func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
    return true
  }

  override func applicationSupportsSecureRestorableState(_ app: NSApplication) -> Bool {
    return true
  }
}
