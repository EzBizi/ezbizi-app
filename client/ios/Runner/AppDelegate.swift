import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  // ── APNs, for notifications that arrive when EzBizi is CLOSED ────
  //
  // Local notifications (flutter_local_notifications) need the app running to
  // exist at all. Waking a sleeping handset is APNs and nothing else, so this
  // is the only path by which a phone in somebody's pocket ever buzzes.
  //
  // THIS IS NOT THE VoIP TOKEN. twilio_voice holds a PushKit token, which wakes
  // the app for an incoming CALL and cannot carry a notification. Both are hex
  // strings of plausible length, the server cannot tell them apart by looking,
  // and a VoIP token stored as a notification token produces a row that reads
  // as registered and never delivers. This file is the only place that
  // guarantee can live, which is why the token is taken from
  // didRegisterForRemoteNotificationsWithDeviceToken and from nowhere else.
  private static let apnsChannelName = "com.ezbizi.app/apns"

  private var apnsChannel: FlutterMethodChannel?

  // The token usually arrives BEFORE Dart is listening.
  //
  // Registration is asynchronous and the callback can land while the engine is
  // still starting, so a design that only pushes the token to Dart loses it on
  // most launches and looks like "APNs sometimes does not give us a token".
  // Caching it and answering getToken from the cache makes the race
  // uninteresting: whichever happens first, Dart gets the same string.
  private var cachedApnsToken: String?

  // Distinguishes "asked and was refused" from "never asked".
  //
  // A simulator has no APNs token at all and never will. Without this, silence
  // there is indistinguishable from a token that was requested and never
  // arrived on a real device — the same shape as an absence that cannot say
  // whether the check was broken.
  private var apnsFailure: String?

  // ── Push taps: open the exact thing, not just the app ──────────────
  //
  // The server puts the bell row's id beside `aps` as "notification_id".
  // A tap that cold-starts the app lands here before Dart is listening, so
  // the id is held until Dart asks `initialTap`, once; after that, taps go
  // straight to `tapped`. Invoking `tapped` before Dart had a handler would
  // drop the cold-start tap, which is the one that matters most.
  private static let tapChannelName = "com.ezbizi.app/notification_tap"
  private var tapChannel: FlutterMethodChannel?
  private var pendingTapId: String?
  private var dartTakesTaps = false

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
    // `as`, NOT `as?`, and the difference is the whole safety of this line.
    //
    // Nothing else sets this delegate. flutter_local_notifications implements
    // the UNUserNotificationCenterDelegate methods and registers itself with
    // `addApplicationDelegate:` — measured in its own FlutterLocalNotifications
    // Plugin.m, which never touches `UNUserNotificationCenter.current()
    // .delegate` at all. It is relying on FlutterAppDelegate being that
    // delegate and forwarding to it, which is why the plugin's own example app
    // carries this same line.
    //
    // The conformance comes from FlutterAppDelegate, a superclass we do not
    // own. `as?` on a cast that fails yields nil, and assigning nil here SETS
    // THE DELEGATE TO NOTHING — no foreground banners and no taps reaching
    // Dart, silently, with every notification still appearing to be raised
    // successfully. A Flutter upgrade that dropped the conformance would do
    // that and nothing would say so. `as` cannot fail quietly: it stops the
    // build on the Mac, which is where somebody can still act on it.
    UNUserNotificationCenter.current().delegate =
      self as UNUserNotificationCenterDelegate

    // Deliberately NOT gated on the alert permission.
    //
    // registerForRemoteNotifications yields a device token whatever the user
    // has said about banners; permission governs whether a delivered push is
    // DISPLAYED, not whether the device can be addressed. Gating registration
    // on permission would mean somebody who declines banners can never be
    // reached even silently, and would make the token depend on a prompt that
    // another part of the app owns.
    application.registerForRemoteNotifications()

    // ── The ask, here rather than in Dart, because it was never happening ──
    //
    // Measured on Jose's iPhone: EzBizi was absent from Settings ▸
    // Notifications entirely. An app appears in that list once it has REQUESTED
    // authorization, so its absence is not "permission was declined" — it is
    // proof the request never ran. Everything downstream was fine and invisible
    // behind it: a valid 64-hex device token, and APNs ACCEPTING a push for
    // topic com.ezbizi.app on the production endpoint, which only happens for
    // an installed build. Apple took the message and iOS dropped it, because an
    // app with no authorization is not allowed to be seen.
    //
    // The Dart side already asks — OsNotifications.enable() calls
    // IOSFlutterLocalNotificationsPlugin.requestPermissions from the bell, and
    // that call is reached, since PushRegistration.start() on the next line of
    // the same initState is what produced the token. So the ask is being lost
    // inside a chain that cannot report it: a plugin, a platform resolver, a
    // method channel, and two `?? true`/`?? false` fallbacks, each of which
    // turns "this did not happen" into a plausible answer.
    //
    // Remote push needs exactly ONE thing from this app, and it is this call.
    // Making it depend on any of that was the mistake. Asking twice costs
    // nothing: iOS prompts once and afterwards returns the standing decision
    // without showing anything, so the Dart path stays as it is for local
    // notifications and this one guarantees the prompt exists at all.
    UNUserNotificationCenter.current().requestAuthorization(
      options: [.alert, .badge, .sound]
    ) { granted, error in
      // Recorded, not acted on. Nothing here can undo a refusal, and the
      // token is registered either way by design (see above) — but "declined"
      // and "the call itself failed" are the two answers somebody debugging a
      // silent handset needs to tell apart, and this is the only place that
      // distinction exists.
      NSLog(
        "EzBizi notification authorization: granted=%@ error=%@",
        String(describing: granted),
        error?.localizedDescription ?? "none")
    }

    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  override func application(
    _ application: UIApplication,
    didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
  ) {
    // Hex, lowercase, no separators — what APNs expects as the device token and
    // what the /notifications/devices endpoint stores.
    let hex = deviceToken.map { String(format: "%02x", $0) }.joined()

    cachedApnsToken = hex
    apnsFailure = nil
    apnsChannel?.invokeMethod("onToken", arguments: hex)

    // super last: FlutterAppDelegate forwards this to every plugin that wants
    // it, and twilio_voice is one of them. Taking our copy first means our
    // token cannot be affected by what a plugin does with the same callback.
    super.application(
      application, didRegisterForRemoteNotificationsWithDeviceToken: deviceToken)
  }

  override func application(
    _ application: UIApplication,
    didFailToRegisterForRemoteNotificationsWithError error: Error
  ) {
    // Recorded rather than swallowed. On a simulator this fires every launch
    // with "no valid aps-environment entitlement", which is correct and
    // expected there; on a real device it is the single most useful line when
    // somebody asks why a handset never registered.
    apnsFailure = error.localizedDescription
    cachedApnsToken = nil
    apnsChannel?.invokeMethod("onTokenError", arguments: error.localizedDescription)

    super.application(
      application, didFailToRegisterForRemoteNotificationsWithError: error)
  }

  override func userNotificationCenter(
    _ center: UNUserNotificationCenter,
    didReceive response: UNNotificationResponse,
    withCompletionHandler completionHandler: @escaping () -> Void
  ) {
    // Only remote pushes carry notification_id. A local notification's tap
    // has none and is flutter_local_notifications' to handle, through super.
    if let id = response.notification.request.content.userInfo["notification_id"] as? String,
      !id.isEmpty
    {
      if dartTakesTaps, let channel = tapChannel {
        channel.invokeMethod("tapped", arguments: id)
      } else {
        pendingTapId = id
      }
    }

    // super always: FlutterAppDelegate forwards to every plugin registered
    // for it, flutter_local_notifications included, and it calls the
    // completion handler. Calling it here as well would call it twice.
    super.userNotificationCenter(
      center, didReceive: response, withCompletionHandler: completionHandler)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // applicationRegistrar.messenger(), not a property on the bridge itself.
    // FlutterImplicitEngineBridge exposes exactly two things - pluginRegistry
    // and applicationRegistrar - and the messenger hangs off the second, from
    // FlutterBaseRegistrar. Read out of FlutterEngine.h and FlutterPlugin.h on
    // the relay rather than guessed; the name I first reached for
    // (applicationBinaryMessenger) does not exist and would not have failed
    // until a Mac compiled it.
    let channel = FlutterMethodChannel(
      name: AppDelegate.apnsChannelName,
      binaryMessenger: engineBridge.applicationRegistrar.messenger())

    channel.setMethodCallHandler { [weak self] call, result in
      switch call.method {
      case "getToken":
        // Three distinguishable answers, on purpose. A bare nil would collapse
        // "not yet" and "never going to" into one value, and those want
        // different behaviour from the Dart side: retry versus give up.
        if let token = self?.cachedApnsToken {
          result(token)
        } else if let failure = self?.apnsFailure {
          result(FlutterError(
            code: "apns_unavailable", message: failure, details: nil))
        } else {
          result(nil)
        }
      default:
        result(FlutterMethodNotImplemented)
      }
    }

    apnsChannel = channel

    let taps = FlutterMethodChannel(
      name: AppDelegate.tapChannelName,
      binaryMessenger: engineBridge.applicationRegistrar.messenger())
    taps.setMethodCallHandler { [weak self] call, result in
      guard call.method == "initialTap" else {
        result(FlutterMethodNotImplemented)
        return
      }
      // Answered once: the id is handed over and forgotten, and every tap
      // from here on is pushed as `tapped`.
      result(self?.pendingTapId)
      self?.pendingTapId = nil
      self?.dartTakesTaps = true
    }
    tapChannel = taps

    // The token can arrive before this handler exists, so replay it. Without
    // this, a launch where registration wins the race leaves Dart waiting for
    // an onToken that already happened.
    if let token = cachedApnsToken {
      channel.invokeMethod("onToken", arguments: token)
    }
  }
}
