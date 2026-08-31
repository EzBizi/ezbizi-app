import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // Without this line iOS shows NOTHING while EzBizi is the app on screen.
    //
    // That is iOS's default and it is reasonable on its own terms — the app is
    // already in front of you, so the system assumes it will say its own piece.
    // It is wrong for this app specifically: EzBizi's notifications are raised
    // by a poll that runs on whatever screen somebody happens to be on, and
    // "you were assigned a job" arriving while they are reading the fleet list
    // is precisely the case the banner exists for.
    //
    // It matters more here than the equivalent line would anywhere else,
    // because these are LOCAL notifications: the app must be running for one to
    // exist at all. On a phone, "running" very often means foreground — so
    // suppressing the foreground case suppresses most of what iOS would ever
    // have shown, and the platform would look broken while being configured
    // exactly as documented.
    UNUserNotificationCenter.current().delegate =
      self as? UNUserNotificationCenterDelegate

    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }
}
