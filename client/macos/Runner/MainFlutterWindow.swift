import Cocoa
import FlutterMacOS

class MainFlutterWindow: NSWindow {
  override func awakeFromNib() {
    let flutterViewController = FlutterViewController()
    let windowFrame = self.frame
    self.contentViewController = flutterViewController
    self.setFrame(windowFrame, display: true)
    // Reopen at the size and position the window was last left, maximized
    // state included, the way every macOS document window does; the name is
    // the key AppKit stores the frame under in the app's preferences.
    self.setFrameAutosaveName("EzBiziMainWindow")

    // EZBIZI_WINDOW=1440x900 pins the content area to that size. It exists for
    // the App Store screenshot run on the relay Mac, where nothing else can
    // size a window: AppleScript is refused (no assistive access) and the nib
    // opens at 800x600, which is no size Apple accepts. A person launching the
    // app never has the variable set and sees no change.
    if let spec = ProcessInfo.processInfo.environment["EZBIZI_WINDOW"] {
      let parts = spec.split(separator: "x").compactMap { Double($0) }
      if parts.count == 2 {
        self.setContentSize(NSSize(width: parts[0], height: parts[1]))
        self.center()
      }
    }

    RegisterGeneratedPlugins(registry: flutterViewController)

    // Dock badge = unread chat total; Dart calls set(int), 0 clears it.
    FlutterMethodChannel(
      name: "ezbizi/badge", binaryMessenger: flutterViewController.engine.binaryMessenger
    ).setMethodCallHandler { call, result in
      guard call.method == "set" else { return result(FlutterMethodNotImplemented) }
      let n = call.arguments as? Int ?? 0
      NSApp.dockTile.badgeLabel = n > 0 ? String(n) : nil
      result(nil)
    }

    super.awakeFromNib()
  }
}
