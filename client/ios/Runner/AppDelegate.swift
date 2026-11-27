import CallKit
import Flutter
import PushKit
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate,
  PKPushRegistryDelegate, CXProviderDelegate
{
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

  // ── Chat calls through CallKit (contract with the Chat session, 11-27) ──
  //
  // The server sends a VoIP push {type:"chat_call", call_id, chat_id,
  // caller_name, video} ONLY for a ringing call. iOS terminates an app that
  // takes a VoIP push without reporting a call, so every chat_call is
  // reported; anything else (twilio_voice's pushes reach this registry too)
  // is left to the plugin that owns it.
  // Answer opens "chat:{chatId}?call={callId}" through the tap path; Decline
  // goes to Dart as callDeclined, because Dart holds the bearer token.
  private var voipRegistry: PKPushRegistry?
  private var cachedVoipToken: String?
  private lazy var callProvider: CXProvider = {
    let config = CXProviderConfiguration()
    config.supportsVideo = true
    config.maximumCallsPerCallGroup = 1
    config.supportedHandleTypes = [.generic]
    config.includesCallsInRecents = false
    let provider = CXProvider(configuration: config)
    provider.setDelegate(self, queue: nil)
    return provider
  }()
  // CallKit UUID -> (call_id, chat_id)
  private var chatCalls: [UUID: (call: String, chat: String)] = [:]

  private static func str(_ v: Any?) -> String {
    if let s = v as? String { return s }
    return v.map { "\($0)" } ?? ""
  }

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

    // Reply and Mark as read on every chat message push (aps.category).
    let reply = UNTextInputNotificationAction(
      identifier: "REPLY", title: "Reply", options: [],
      textInputButtonTitle: "Send", textInputPlaceholder: "Message")
    let read = UNNotificationAction(identifier: "READ", title: "Mark as read", options: [])
    UNUserNotificationCenter.current().setNotificationCategories([
      UNNotificationCategory(
        identifier: "CHAT_MESSAGE", actions: [reply, read], intentIdentifiers: [], options: [])
    ])

    let registry = PKPushRegistry(queue: .main)
    registry.delegate = self
    registry.desiredPushTypes = [.voIP]
    voipRegistry = registry

    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  // ── PushKit ──
  func pushRegistry(
    _ registry: PKPushRegistry, didUpdate credentials: PKPushCredentials, for type: PKPushType
  ) {
    let hex = credentials.token.map { String(format: "%02x", $0) }.joined()
    cachedVoipToken = hex
    apnsChannel?.invokeMethod("onVoipToken", arguments: hex)
  }

  func pushRegistry(
    _ registry: PKPushRegistry, didReceiveIncomingPushWith payload: PKPushPayload,
    for type: PKPushType, completion: @escaping () -> Void
  ) {
    let p = payload.dictionaryPayload
    guard p["type"] as? String == "chat_call" else {
      completion()  // not ours: twilio_voice reports its own calls
      return
    }
    let uuid = UUID()
    chatCalls[uuid] = (AppDelegate.str(p["call_id"]), AppDelegate.str(p["chat_id"]))
    let name = (p["caller_name"] as? String) ?? "EzChat"
    let update = CXCallUpdate()
    update.remoteHandle = CXHandle(type: .generic, value: name)
    update.localizedCallerName = name
    update.hasVideo = (p["video"] as? Bool) ?? false
    callProvider.reportNewIncomingCall(with: uuid, update: update) { _ in completion() }
  }

  // ── CallKit ──
  func providerDidReset(_ provider: CXProvider) { chatCalls.removeAll() }

  func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
    guard let c = chatCalls.removeValue(forKey: action.callUUID) else {
      action.fail()
      return
    }
    // Dart joins the call; CallKit's screen is closed so the app's own call
    // screen is the one in charge.
    deliverTap("chat:\(c.chat)?call=\(c.call)")
    action.fulfill()
    provider.reportCall(with: action.callUUID, endedAt: nil, reason: .answeredElsewhere)
  }

  func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
    if let c = chatCalls.removeValue(forKey: action.callUUID) {
      tapChannel?.invokeMethod("callDeclined", arguments: ["call_id": c.call, "chat_id": c.chat])
    }
    action.fulfill()
  }

  // ── Background pushes: chat_call_end, chat_read ──
  override func application(
    _ application: UIApplication, didReceiveRemoteNotification userInfo: [AnyHashable: Any],
    fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
  ) {
    let chatId = AppDelegate.str(userInfo["chat_id"])
    switch userInfo["type"] as? String {
    case "chat_call_end":
      let callId = AppDelegate.str(userInfo["call_id"])
      for (uuid, c) in chatCalls where c.call == callId {
        chatCalls[uuid] = nil
        callProvider.reportCall(with: uuid, endedAt: nil, reason: .remoteEnded)
      }
      completionHandler(.newData)
    case "chat_read":
      let center = UNUserNotificationCenter.current()
      center.getDeliveredNotifications { list in
        center.removeDeliveredNotifications(
          withIdentifiers: list.filter { $0.request.content.threadIdentifier == "chat-\(chatId)" }
            .map { $0.request.identifier })
        completionHandler(.newData)
      }
    default:
      super.application(
        application, didReceiveRemoteNotification: userInfo,
        fetchCompletionHandler: completionHandler)
    }
  }

  private func deliverTap(_ id: String) {
    if dartTakesTaps, let channel = tapChannel {
      channel.invokeMethod("tapped", arguments: id)
    } else {
      pendingTapId = id
    }
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
    // Reply / Mark as read: Dart holds the bearer token, so it makes the
    // call, and iOS is told we are done only once Dart answers.
    let info = response.notification.request.content.userInfo
    if response.actionIdentifier == "REPLY" || response.actionIdentifier == "READ",
      let nid = info["notification_id"] as? String, nid.hasPrefix("chat:")
    {
      var args: [String: Any] = ["chat_id": String(nid.dropFirst(5).prefix { $0 != "?" })]
      if let text = (response as? UNTextInputNotificationResponse)?.userText { args["text"] = text }
      if let channel = tapChannel {
        channel.invokeMethod(
          response.actionIdentifier == "REPLY" ? "reply" : "markRead", arguments: args
        ) { _ in completionHandler() }
      } else {
        completionHandler()
      }
      return
    }

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
      case "getVoipToken":
        result(self?.cachedVoipToken)
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
    if let voip = cachedVoipToken {
      channel.invokeMethod("onVoipToken", arguments: voip)
    }
  }
}
