import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import 'client_platform.dart';
import 'clock_window.dart';

/// A notification the operating system shows, rather than the bell (plan §12).
///
/// ── Why there is no conditional import here ──────────────────────
///
/// Every other cross-platform seam in this folder is a `foo.dart` /
/// `foo_io.dart` / `foo_web.dart` trio, because `dart:io` does not exist in a
/// browser and one import of it takes the web build down. This one is a single
/// file because the plugin is already federated the same way underneath:
/// `flutter_local_notifications` 22 ships a web implementation that registers
/// its own service worker out of its own package assets, so there is nothing
/// for `client/web/index.html` to declare and nothing for this file to branch
/// on. Writing the trio anyway would be a second dispatch layer sitting on top
/// of one that already works.
///
/// ── What "works" means on each of the four, honestly ─────────────
///
/// These are LOCAL notifications: the app must be running for one to appear.
/// That is the whole of it on web and desktop, where a running app is the
/// normal state. On a phone it is the real limit — close EzBizi and the handset
/// stays quiet, because waking a sleeping phone is remote push (FCM/APNs), a
/// different mechanism with its own server, its own credentials and its own
/// device registry. None of that is built, and this class does not pretend to
/// be a substitute for it: it makes the app that is open tell you something,
/// which is exactly what it says on the tin and no more.
///
/// ── Why permission is asked from a tap and never at startup ──────
///
/// The browsers punish the alternative. Requesting notification permission on
/// page load can have the request auto-denied AND the origin marked abusive, at
/// which point no prompt is ever shown again — a permanent loss, taken in
/// exchange for asking a second earlier. So [enable] is called from the bell
/// when somebody opens it, which is a deliberate act by a person who has just
/// demonstrated they care about notifications.
class OsNotifications {
  OsNotifications._();

  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();

  /// The Windows toast identity, which is not free-form.
  ///
  /// `guid` is the same one Inno Setup already registers as the installer's
  /// `AppId` (scripts/windows-installer.iss). Reused rather than generated so
  /// the app has ONE identity on a Windows machine: a second GUID here would
  /// mean the thing that installs EzBizi and the thing that raises its toasts
  /// disagree about what EzBizi is, and Windows resolves that disagreement by
  /// showing nothing.
  static const String _windowsGuid = '8F3A6C21-4D7E-4B90-9E52-1C6A0B7D4E38';

  /// The icon Windows draws on the toast, declared in pubspec.yaml.
  ///
  /// ── Why a path and not the .ico the app already has ──────────────
  ///
  /// The plugin writes this into
  /// `HKCU\Software\Classes\AppUserModelId\EzBizi.DesktopClient\IconUri` —
  /// measured in its own source, not inferred — and WINDOWS opens that file,
  /// later, when it draws the toast. So it has to be a real file that outlives
  /// the call. `windows/runner/resources/app_icon.ico` is compiled into
  /// ezbizi.exe as a resource and is not a file on disk at all, which is why
  /// the obvious candidate is the wrong one.
  ///
  /// Null when it cannot be found, and that is deliberately not an error: the
  /// registry value is simply not written, Windows falls back to the generic
  /// icon, and the toast still appears. An icon is worth exactly nothing next
  /// to the notification itself.
  ///
  /// Public only so a test can load this exact key through `rootBundle`. The
  /// failure it guards is a silent one: drop the line from pubspec.yaml, or
  /// rename the file, and nothing throws — `installedAssetPath` finds no file,
  /// returns null, and the toast goes back to the generic icon it had before
  /// anybody noticed. Naming the same string from both sides is what makes
  /// that visible.
  @visibleForTesting
  static const String windowsIconAsset = 'assets/logo/app-icon-120.png';

  /// The same icon, carried ON the toast instead of looked up from the registry.
  ///
  /// ── Why both, when one of them ought to be enough ────────────────
  ///
  /// `iconPath` is the registry route: the plugin writes it to
  /// `...\AppUserModelId\EzBizi.DesktopClient\IconUri` and Windows is supposed
  /// to find it from there. That route was set up correctly and measured
  /// correct — the value present, the file at the end of it on disk, the name
  /// beside it in the same key rendering fine on the toast — and Windows drew
  /// no icon anyway. A registration that is right and does not take is not
  /// something this code can argue with.
  ///
  /// `appLogoOverride` does not ask Windows to resolve anything: the image is
  /// named in the toast's own XML, per notification, and appears in the banner
  /// and in the notification centre entry behind it. So the two are not
  /// belt-and-braces for the same mechanism — one is an app-identity lookup and
  /// the other is a picture in this specific toast, and only the second is
  /// under our control at the moment we show it.
  ///
  /// Resolved once: it is the same file every time, and `existsSync` inside
  /// [ClientPlatform.installedAssetPath] is a disk hit that has no business
  /// running on every notification.
  static final WindowsImage? _windowsLogo = () {
    final path = ClientPlatform.installedAssetPath(windowsIconAsset);

    return path == null
        ? null
        : WindowsImage(
            Uri.file(path, windows: true),
            altText: 'EzBizi',
            placement: WindowsImagePlacement.appLogoOverride,
          );
  }();

  static bool _initialised = false;
  static bool _permitted = false;

  /// What a click on one of these does, given the `payload` it was shown with.
  ///
  /// Set by NotificationBell, which is the only thing that knows how to open
  /// what a notification is about. Until 2026-09-29 nothing was set and
  /// [initialize] was given no response callback at all, so a click on a
  /// Windows toast was delivered to the running app and dropped on the floor —
  /// Jose: "clicking on notification on desktop does nothing".
  ///
  /// A static hook rather than a stream: there is one bell, it is the one
  /// consumer, and it clears this when it is disposed so a click after
  /// sign-out cannot reach a widget that no longer exists.
  static void Function(String? payload)? onClick;

  /// Every title [show] was ASKED to raise, while a test is listening.
  ///
  /// Recorded before the permission check on purpose: a test host never grants
  /// permission, so the only observable difference between "the bell decided
  /// to alert" and "the bell decided not to" is whether it asked. Null outside
  /// tests, where it costs one null check.
  @visibleForTesting
  static List<String>? shownForTest;

  /// Ask for permission, initialising the plugin the first time.
  ///
  /// Returns whether an OS notification can actually be shown. False is a
  /// completely normal answer — permission refused, a browser without service
  /// workers, a Linux desktop with no notification daemon — and callers are
  /// expected to carry on without it rather than surface an error. The bell is
  /// the notification; this is a courtesy on top of it.
  static Future<bool> enable() async {
    if (_permitted) return true;

    try {
      if (!_initialised) {
        _initialised =
            await _plugin.initialize(
              settings: InitializationSettings(
                // The launcher icon, which every Flutter Android project has
                // already. A dedicated white-on-transparent status bar icon
                // would look better on Android and is the kind of asset that
                // needs a designer, not a developer inventing one.
                android: const AndroidInitializationSettings(
                  '@mipmap/ic_launcher',
                ),
                // Permission flags are all false: this class asks below, from
                // a tap, and asking twice would show the person two prompts.
                iOS: const DarwinInitializationSettings(
                  requestAlertPermission: false,
                  requestBadgePermission: false,
                  requestSoundPermission: false,
                ),
                macOS: const DarwinInitializationSettings(
                  requestAlertPermission: false,
                  requestBadgePermission: false,
                  requestSoundPermission: false,
                ),
                windows: WindowsInitializationSettings(
                  appName: 'EzBizi',
                  appUserModelId: 'EzBizi.DesktopClient',
                  guid: _windowsGuid,
                  iconPath: ClientPlatform.installedAssetPath(
                    windowsIconAsset,
                  ),
                ),
                linux: const LinuxInitializationSettings(
                  defaultActionName: 'Open EzBizi',
                ),
                web: const WebInitializationSettings(),
              ),
              onDidReceiveNotificationResponse: _clicked,
            ) ??
            false;
      }

      _permitted = _initialised && await _requestPermission();

      return _permitted;
    } catch (_) {
      // A platform that cannot do this at all throws rather than returning
      // false — an unsupported browser, a desktop with no notification service.
      // Swallowed on purpose: the bell has already shown the person everything
      // this would have told them, so there is nothing here worth interrupting
      // them about.
      return false;
    }
  }

  /// Show one, if [enable] has succeeded. A no-op otherwise.
  ///
  /// [id] replaces rather than stacks when reused, on every platform including
  /// the web (where the plugin maps it onto the notification `tag`). Callers
  /// pass a fresh id per distinct thing to say, and reuse one deliberately to
  /// overwrite — see the summary in NotificationBell.
  ///
  /// [payload] comes back to [onClick] when the notification is clicked. On
  /// Windows it rides the toast's `launch` attribute, so it survives the app
  /// being closed in between.
  static Future<void> show({
    required int id,
    required String title,
    String? body,
    String? payload,
  }) async {
    shownForTest?.add(title);
    if (!_permitted) return;

    try {
      await _plugin.show(
        payload: payload,
        id: id,
        title: title,
        body: body,
        // No `windows:` details, and the sound is the reason that is a
        // decision rather than an omission. The plugin serialises audio as
        // `audio?.buildXml(builder)` — a null one writes no `<audio>` element
        // at all, and a toast without that element plays the Windows default
        // notification sound. Passing WindowsNotificationAudio.preset(...) to
        // say so out loud would emit `<audio src="ms-winsoundevent:
        // Notification.Default">`, which is the same sound named twice and one
        // more thing to keep in step with whatever the person chose in
        // Settings. Silence would need an explicit `silent="true"`, so there is
        // no way to end up quiet by accident here.
        notificationDetails: NotificationDetails(
          windows: WindowsNotificationDetails(
            images: <WindowsImage>[?_windowsLogo],
          ),
          android: const AndroidNotificationDetails(
            'ezbizi_notifications',
            'EzBizi notifications',
            channelDescription:
                'Assignments, mentions, reminders and alerts from EzBizi.',
            importance: Importance.defaultImportance,
            priority: Priority.defaultPriority,
          ),
        ),
      );
    } catch (_) {
      // Same reasoning as above. A failed toast must never be able to take a
      // poll cycle down with it.
    }
  }

  /// A notification was clicked: bring EzBizi forward, then go where it says.
  ///
  /// The window first, because a click that navigates a hidden window has
  /// done nothing the person can see. [ClockWindow.raise] is show, restore if
  /// minimized, focus — the same three steps as the runner's ShowFromTray, so
  /// it covers a window closed to the tray (hidden, not minimized) as well as
  /// one minimized to the taskbar. A no-op off the desktop, where the OS
  /// brings the app forward itself.
  static Future<void> _clicked(NotificationResponse response) async {
    await ClockWindow.raise();
    onClick?.call(response.payload);
  }

  /// A phone PUSH (FCM / APNs) was tapped — the native side's half of [onClick].
  ///
  /// The server puts the bell row's id in the push as `notification_id`; the
  /// Android and iOS runners read it from the tap and forward it here:
  /// `tapped(id)` while running, and `initialTap()` answers the id that
  /// cold-started the app (null when nothing did). Called once the bell has set
  /// [onClick], so a cold-start tap is not delivered to nobody.
  static const _pushTaps = MethodChannel('com.ezbizi.app/notification_tap');

  static Future<void> listenForPushTaps() async {
    if (ClientPlatform.isWeb) return;
    _pushTaps.setMethodCallHandler((call) async {
      if (call.method == 'tapped' && call.arguments is String) {
        onClick?.call(call.arguments as String);
      }
    });
    try {
      final initial = await _pushTaps.invokeMethod<String>('initialTap');
      if (initial != null && initial.isNotEmpty) onClick?.call(initial);
    } on MissingPluginException {
      // ponytail: desktop, and a runner that has not learned the channel yet.
    } on PlatformException {
      // Same: no native tap handling means no push tap to deliver.
    }
  }

  /// Per-platform, because the four ask in four different ways.
  ///
  /// Android has had a runtime notification permission since 13 and grants it
  /// silently below that; Darwin asks for alert/badge/sound together; Windows
  /// has no prompt at all — the toast is allowed unless the person has turned
  /// EzBizi off in Windows Settings, which is not something the app can see or
  /// should try to. A null answer from any resolver means "this platform does
  /// not gate it", so it reads as permitted.
  static Future<bool> _requestPermission() async {
    if (defaultTargetPlatform == TargetPlatform.android && !kIsWeb) {
      return await _plugin
              .resolvePlatformSpecificImplementation<
                AndroidFlutterLocalNotificationsPlugin
              >()
              ?.requestNotificationsPermission() ??
          true;
    }

    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.iOS) {
      return await _plugin
              .resolvePlatformSpecificImplementation<
                IOSFlutterLocalNotificationsPlugin
              >()
              ?.requestPermissions(alert: true, badge: true, sound: true) ??
          true;
    }

    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.macOS) {
      return await _plugin
              .resolvePlatformSpecificImplementation<
                MacOSFlutterLocalNotificationsPlugin
              >()
              ?.requestPermissions(alert: true, badge: true, sound: true) ??
          true;
    }

    if (kIsWeb) {
      // False rather than true when the resolver gives nothing back: a browser
      // is the one platform here that will not show a notification without an
      // explicit grant, so "we could not ask" has to mean "we may not show".
      return await _plugin
              .resolvePlatformSpecificImplementation<
                WebFlutterLocalNotificationsPlugin
              >()
              ?.requestNotificationsPermission() ??
          false;
    }

    return true;
  }
}
