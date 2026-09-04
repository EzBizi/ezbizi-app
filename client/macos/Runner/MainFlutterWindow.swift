import Cocoa
import FlutterMacOS

class MainFlutterWindow: NSWindow {
  override func awakeFromNib() {
    let flutterViewController = FlutterViewController()
    let windowFrame = self.frame
    self.contentViewController = flutterViewController
    self.setFrame(windowFrame, display: true)

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

    super.awakeFromNib()
  }
}
