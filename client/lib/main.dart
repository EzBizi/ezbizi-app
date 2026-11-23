import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'core/address.dart';
import 'core/api_client.dart';
import 'core/contracts.dart';
import 'core/deep_links.dart';
import 'core/deployment.dart';
import 'core/desktop_updater.dart';
import 'core/files.dart';
import 'core/live_view_service.dart';
import 'core/mobile_view_window.dart';
import 'core/module_screens.dart';
import 'screens/leads_screen.dart';
import 'core/field_tracker.dart';
import 'core/issue_reporter.dart';
import 'core/monitoring_heartbeat.dart';
import 'core/permissions.dart';
import 'core/file_access_service.dart';
import 'core/remote_control_service.dart';
import 'core/screenshot_service.dart';
import 'core/punch_position.dart';
import 'core/session.dart';
import 'core/sidebar_order.dart';
import 'core/staff_session.dart';
import 'core/theme.dart';
import 'core/ui_strings.dart';
import 'core/clock_window.dart';
import 'core/version_gate.dart';
import 'screens/lock_screen.dart';
import 'widgets/in_page_back.dart';
import 'widgets/controlled_banner.dart';
import 'widgets/get_the_apps.dart';
import 'widgets/open_destination.dart';
import 'widgets/side_panel.dart';
import 'widgets/shift_reminder.dart';
import 'screens/development_screen.dart';
import 'screens/hr/onboarding_form.dart';
import 'screens/invitation_screen.dart';
import 'screens/login_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/reset_password_screen.dart';
import 'screens/signup_screen.dart';
import 'screens/settings_shell.dart';
import 'screens/staff/staff_login_screen.dart';
import 'screens/staff/staff_shell.dart';
import 'screens/suggestions_screen.dart';
import 'screens/update_required_screen.dart';
import 'core/camera_bridge/bridge_boot.dart';
import 'core/lighting/lighting_boot.dart';
import 'core/token_store.dart';
import 'widgets/build_stamp.dart';
import 'widgets/module_page_header.dart';
import 'widgets/tab_help.dart';
import 'widgets/notification_bell.dart';
import 'widgets/profile_aura.dart';
import 'widgets/scope_switcher.dart';
import 'widgets/user_avatar.dart';
import 'widgets/availability_button.dart';
import 'widgets/broken_widget_card.dart';
import 'widgets/callout.dart';
import 'widgets/quick_search.dart';
import 'widgets/incoming_call_bar.dart';
import 'widgets/incoming_call_popup.dart';
import 'core/phone_call_service.dart';
import 'screens/phone/voice_view.dart';

Future<void> main() async {
  IssueReporter.markLaunch();
  // Bindings first: TokenStore.init reaches a platform channel (the keychain),
  // and a channel used before this call throws.
  WidgetsFlutterBinding.ensureInitialized();

  // The desktop window facade, before any screen can ask it for the window.
  // A no-op on web and phones (core/clock_window.dart).
  await ClockWindow.init();

  // Awaited, deliberately, and it is the only thing between launch and the
  // first frame. Session.restore reads TokenStore synchronously; if that read
  // happens before the keychain has been loaded it sees nothing and signs the
  // person out. That is the whole "an update logged me out" bug.
  await TokenStore.init();

  // Crashes, freezes and failed calls reach the staff console (2026-10-15).
  IssueReporter.install();

  // One widget that throws draws a card, not a grey or black app.
  BrokenWidgetCard.install();

  // A paired camera-bridge PC streams on its site key, signed in or not.
  unawaited(startCameraBridge());

  runApp(const EzBiziApp());
}

class EzBiziApp extends StatefulWidget {
  const EzBiziApp({super.key});

  @override
  State<EzBiziApp> createState() => _EzBiziAppState();
}

class _EzBiziAppState extends State<EzBiziApp> {
  // Two clients, deliberately. A tenant token and a staff token must never sit
  // in the same place — the server refuses each on the other's endpoints, and
  // the client should not be trying it.
  late final ApiClient _api = ApiClient();
  late final Session _session = Session(_api);

  late final ApiClient _staffApi = ApiClient();
  late final StaffSession _staffSession = StaffSession(_staffApi);
  final Address _address = Address.platform();

  /// Decided by the host the browser asked for, once, at launch. There is no
  /// switching between the two faces at runtime — a customer host has no staff
  /// form to reach and the control-plane host has no customer app behind it.
  final AppFace _face = Deployment.face();

  /// Whether the server has refused this build for being too old.
  ///
  /// One gate across BOTH clients. Being out of date is a fact about the binary,
  /// not about a session, so a customer and a staff member running the same
  /// stale build must both be told — see core/version_gate.dart.
  final _versionGate = ValueNotifier<UpgradeRequired?>(null);

  /// An update that closes the app has something to interrupt only once
  /// somebody is signed in. On the sign-in screen it goes straight ahead.
  void _tellUpdaterWhoIsHere() {
    DesktopUpdater.instance.signedIn =
        _session.me != null || _staffSession.me != null;
  }

  @override
  void dispose() {
    // The window is closing, so stop looking for updates. Without this the
    // hourly timer outlives the widget tree, which in a running app is a leak
    // and in a widget test is a hard failure on every test in the file.
    DesktopUpdater.instance.stop();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    if (IssueReporter.installed) IssueReporter.instance.attach(_api);
    _session.addListener(() => setState(() {}));
    _staffSession.addListener(() => setState(() {}));

    /*
     * Kept current from here, not from the shell inside it.
     *
     * The check has to run whether anybody is signed in or not — a desktop left
     * sitting on the sign-in screen is exactly the one that goes a month behind
     * — so it starts at the root and keeps going for as long as the window is
     * open. See core/desktop_updater.dart for the hourly sweep and for why a
     * found update waits a minute before it closes the app.
     */
    // Never closes the app under a live call (Jose, 2026-10-09).
    // ...and only installs on its own once a person who takes calls here has
    // set Do Not Disturb (Jose, 2026-10-09).
    DesktopUpdater.instance.guardCalls(
      PhoneCallService.current,
      () => PhoneCallService.current.busy,
      availableForCalls: () =>
          PhoneCallService.current.presenceHasLine &&
          PhoneCallService.current.presence != 'dnd',
    );
    DesktopUpdater.instance.start();
    _session.addListener(_tellUpdaterWhoIsHere);
    _staffSession.addListener(_tellUpdaterWhoIsHere);

    _versionGate.addListener(() => setState(() {}));
    // Once, for every ApiClient in the process — including the ones the
    // Development section and staff enrolment build for themselves, which a
    // per-client registration here silently missed.
    installVersionGate(_versionGate);

    // A refresh used to sign people out, because the token lived only in
    // memory. It is picked back up here and verified before it is trusted.
    if (_face == AppFace.customer) {
      _session.restore();
    } else {
      _staffSession.restore();
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'EzBizi',
      debugShowCheckedModeBanner: false,
      theme: EzBiziTheme.light(),
      darkTheme: EzBiziTheme.dark(),
      /*
       * The signed-in person's choice (§2.1 category 6), falling back to the
       * device before anyone has signed in — there is nobody to have a
       * preference yet at that point.
       *
       * Distinct from §12's theming decision, which is about an ORGANIZATION's
       * brand: its colours and logo. Somebody preferring dark mode at night is
       * not a branding decision and does not belong to their employer.
       */
      themeMode: EzBiziTheme.modeFor(_session.me?.theme ?? 'system'),

      /*
       * The incoming-call window, above every screen in the app.
       *
       * `builder` rather than a Stack inside the shell's Scaffold, because a
       * ringing phone is not a property of one screen: somebody on the sign-in
       * screen has nobody to ring, but somebody reading a report, an
       * inspection or a staff page does, and each of those is a different
       * Scaffold. This is the one place above all of them.
       *
       * It draws nothing unless a call is actually ringing AND the person has
       * not switched it off — `phone.incoming_popup`, on by default. The strip in
       * the shell is unaffected and appears either way; see IncomingCallPopup
       * for why both exist.
       */
      builder: (context, child) => PageProblemBanner(
        child: Stack(
          children: [
            ?child,
            IncomingCallPopup(api: _api, session: _session),
          ],
        ),
      ),
      home: _home(),
    );
  }

  /// Set once a reset link has been dealt with, so "Back to sign in" does not
  /// bounce straight back into the reset screen off the same URL.
  bool _resetHandled = false;

  /// The same latch for an invitation. Without it, accepting would sign the
  /// person in and then be handed straight back the token still sitting in the
  /// address bar — a spent one, so they would land on "this link has already
  /// been used" seconds after it worked.
  bool _inviteHandled = false;

  /// Whether the signup form is being shown instead of the sign-in one.
  ///
  /// The public screens are swapped here rather than pushed, because there is no
  /// router — the same way the reset screen is reached. Kept below the staff
  /// return in _home() so the control plane cannot show it whatever this holds:
  /// §4.2 gives platform staff their own accounts, created by staff, and nobody
  /// self-registers there.
  bool _signingUp = false;

  Widget _home() {
    /*
     * FIRST, above even the reset link, and it is the only thing that belongs
     * above it.
     *
     * Everything below this line assumes the app can talk to the server. Once
     * the server has said this build is too old, none of it can: the reset form
     * would submit into a 426, the invitation screen could not fetch what the
     * invitation is for, and the sign-in form would refuse a correct password
     * with no visible reason. Each of those is a worse version of the same
     * message, delivered later and less clearly.
     *
     * Note that this cannot delay anybody who is fine. Nothing is checked in
     * advance and no request is made to find out — the gate only ever holds a
     * refusal the server has already sent, so on the overwhelmingly common path
     * this is one null check.
     */
    final blocked = _versionGate.value;

    if (blocked != null) {
      return UpdateRequiredScreen(
        notice: blocked,
        // A way out, because the 426 can stop being true without this app
        // hearing of it: a newer build installed over the top, a minimum
        // lowered again, a proxy mid-deploy. If it is still true, the next
        // request raises the gate again.
        onTryAgain: () => _versionGate.value = null,
        // Offered only when there is a session to end. A sign-out button on a
        // sign-in screen is a control that does nothing to somebody who is
        // already out, which reads as the app being confused about who they are.
        //
        // Local, and it must be: signing out asks the server to revoke a token,
        // and that request would come straight back 426 like every other. So the
        // token is dropped on this side regardless. A stale token on a server
        // that will not accept this client anyway is not a risk worth trapping
        // somebody on this screen over.
        onSignOut: _signedInSomewhere ? _signOutEverywhere : null,
      );
    }

    // Before anything else, including the restore gate: somebody following a
    // reset link is by definition not signed in, and making them wait on a
    // session check to see a form that does not need one is the kind of delay
    // that gets read as a broken link.
    final token = _resetHandled ? null : Deployment.resetToken();

    if (token != null) {
      return ResetPasswordScreen(
        api: _api,
        token: token,
        onDone: () {
          Files.clearQuery();
          setState(() => _resetHandled = true);
        },
      );
    }

    // Held until a stored token has been checked. Without this the sign-in
    // screen flashes for a moment on every refresh, which reads as being
    // logged out and then not.
    final restoring = _face == AppFace.staff
        ? !_staffSession.restored
        : !_session.restored;

    if (restoring) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (_face == AppFace.staff) {
      return _staffSession.signedIn
          ? StaffShell(staff: _staffSession, address: _address)
          : StaffLoginScreen(staff: _staffSession);
    }

    // An invitation link, swapped in the way the reset screen is — there is no
    // router, so the public screens are chosen here.
    //
    // Two things put it BELOW the staff branch and the restore gate, where the
    // reset screen sits above both:
    //
    //   - §4.2. Platform staff have their own accounts in their own control
    //     plane, created by staff. Nobody is invited into it, so the customer
    //     face is the only face that offers this;
    //   - accepting SIGNS THEM IN. A restore still in flight would be racing to
    //     put a stored token back over the one acceptance just adopted. The
    //     screen fetches what the invitation is for before it can draw anything
    //     anyway, so waiting for the restore costs nothing anybody can see.
    //
    // Above the signed-in branch, though: somebody who follows an invitation
    // while already signed in elsewhere means to join the place that invited
    // them, and one account belongs to one organization (§4).
    final invite = _inviteHandled ? null : Deployment.inviteToken();

    if (invite != null) {
      return InvitationScreen(
        api: _api,
        session: _session,
        token: invite,
        onDone: () {
          Files.clearQuery();
          setState(() => _inviteHandled = true);
        },
      );
    }

    // Before the signup branch, so a successful signup lands in the app rather
    // than back on the form it just submitted — the session adopted there
    // notifies this widget, and _signingUp is still set when it rebuilds.
    if (_session.signedIn) {
      // And cleared here, because ordering alone only MASKS it. The flag was
      // set on the way in and never unset on success, so it stayed true for the
      // life of the app: the moment the session ended — signing out, or a token
      // that stopped being accepted — the branch below took over and the person
      // who had just created a business was shown "Create your account" with no
      // sign-in field anywhere. Filling it in built a SECOND organization with
      // the same name and the same administrator, which nothing refuses (an
      // address may belong to several organizations by design, and the slug
      // quietly becomes `-2`).
      //
      // A plain assignment rather than setState: this build is already showing
      // the shell, so nothing needs to rebuild. The flag only decides what a
      // LATER build does, and by then it is right.
      _signingUp = false;

      // The lock is the server's answer, never a permission read here
      // (design 2026-09-08 §3.9). Phones never lock, whatever it says.
      if (_session.me!.timeClock.lock && ClockWindow.canLock) {
        return LockScreen(api: _api, session: _session);
      }

      return HomeShell(api: _api, session: _session, address: _address);
    }

    if (_signingUp) {
      return SignupScreen(
        api: _api,
        session: _session,
        onSignIn: () => setState(() => _signingUp = false),
      );
    }

    return LoginScreen(
      api: _api,
      session: _session,
      onCreateAccount: () => setState(() => _signingUp = true),
    );
  }

  /// Either face has somebody signed in.
  ///
  /// Asked rather than assumed from [_face], because the update screen is shown
  /// above the branch that picks a face and therefore has no business knowing
  /// which one it is standing in front of.
  bool get _signedInSomewhere => _session.signedIn || _staffSession.signedIn;

  /// End whichever session exists. Both, without checking, because signing out
  /// of one while the other lingers is how somebody ends up believing they have
  /// left — the same reasoning as TokenStore.clear, which both of these call.
  void _signOutEverywhere() {
    _session.signOut();
    _staffSession.signOut();
  }
}

/// The signed-in shell.
///
/// Its business destinations are not written down here. They are built from the
/// `modules` block on `/me`, which answers both gates in one list: whether the
/// PLATFORM permits a module for this organization (§13) and whether this
/// person's role holds its view permission (§4). The sidebar renders exactly
/// those marked reachable, in the order the server sent them.
///
/// Three things that were wrong while the list was compiled in:
///
///   - a technician with `customers.view` and nothing else saw five tabs, four
///     of which answered 403 the moment they were tapped;
///   - the platform kill switch was built to be used mid-incident, and left
///     every tab drawn with every action inside it failing;
///   - "which tabs do I see" had two answers, the server's and Dart's, and only
///     one of them was enforced.
///
/// See core/module_screens.dart for the only part that stays in code — which
/// widget draws which module — and why that is not the same list.
class HomeShell extends StatefulWidget {
  const HomeShell({
    super.key,
    required this.api,
    required this.session,
    this.address,
  });

  final ApiClient api;
  final Session session;

  /// The address bar, so a reload opens the same place. Null in tests that
  /// are only asking which destinations exist.
  final Address? address;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

/// Which group holds the current selection. The profile is one of them: it is
/// not a rail destination, but it is somewhere the shell can be pointed.
enum _Rail { modules, pinned, staff, profile }

/// The two traversal regions of the shell, named so a test can ask which one
/// focus is in. See the note beside [shellSidebarGroup]'s use.
const Key shellSidebarGroup = Key('shell-traversal-sidebar');
const Key shellPageGroup = Key('shell-traversal-page');

class _HomeShellState extends State<HomeShell> {
  /// For Android's Back, which closes the drawer before anything else.
  final _scaffoldKey = GlobalKey<ScaffoldState>();

  /// The countdown before a scheduled shift ends (design 2026-09-08 §10.1).
  late final ShiftReminderController _reminder;
  Timer? _clockPoll;

  /// Access changed elsewhere (an inbox granted, a role edited) reaches an
  /// open app without a reload: /me is asked again every minute and when the
  /// app comes back, and the page is keyed on its access_version (Jose,
  /// 2026-11-10).
  Timer? _accessPoll;
  AppLifecycleListener? _accessOnResume;
  VoidCallback? _stopClockListening;

  _Rail _rail = _Rail.modules;

  /// Developer only: render the app at phone width without resizing the window
  /// (Jose, 2026-09-14 — desktop layout 1, mobile layout 4).
  ///
  /// Session state rather than a preference. It is a mode you are in for a
  /// minute; saving it would mean signing in on a desktop to a mobile layout
  /// with no memory of having asked for one.
  bool _mobileView = false;

  /// A phone the app has to look right on. Narrow enough to be the real test —
  /// under the 480 breakpoint `auth_shell` uses, and well under the 600
  /// `lead_thread` uses and the 900 this shell uses.
  static const double _phoneWidth = 390;

  /// Whether the drawer is showing its rows with drag handles on.
  ///
  /// Held here rather than in the drawer because a Drawer is rebuilt from
  /// scratch every time it opens; state inside it would be lost on the first
  /// scroll that closed and reopened it.
  bool _arrangingDrawer = false;
  void Function()? _stopListening;

  /// Reports this device's presence and which module is on screen
  /// (2026-08-28-monitoring-pc-specs). Lives here rather than on [EzBiziApp]
  /// so it only starts beating once somebody is actually signed in and inside
  /// the shell — a beat posted before that would just be an authenticated
  /// endpoint refusing a request nobody sent on purpose.
  ///
  /// Constructed explicitly in [initState] rather than as an inline `late
  /// final` field initializer — a `late final` FIELD initializer runs on
  /// first READ, not at construction. The only reads were in `_show()` and
  /// `dispose()`, so a session that lands on a deep link and never taps the
  /// nav rail never read it, never constructed it, and its `Timer.periodic`
  /// never started: zero beats, not even the keep-alive, contradicting the
  /// class's own doc comment that the keep-alive fires unconditionally once
  /// this exists. Assigning it here forces that construction to happen
  /// exactly once, unconditionally, the moment the shell mounts.
  late final MonitoringHeartbeat _heartbeat;

  /// This device's own periodic screen captures
  /// (2026-08-31-monitoring-screenshots). Same lifecycle as [_heartbeat] and
  /// for the same reason — see its doc comment above for why this has to be
  /// an explicit `initState` assignment rather than a `late final` field
  /// initializer: the identical lazy-init bug (never constructed, never
  /// started) would otherwise apply here too.
  ///
  /// Null when this person is screen-monitoring exempt (the Lead Developer):
  /// the capture service is then never constructed, so it never captures.
  ScreenshotService? _screenshots;

  /// The agent half of Live View (2026-09-02-monitoring-live-view, Part A) —
  /// polls whether a manager is currently watching this device and, only
  /// then, captures and uploads. Same lifecycle as [_heartbeat] and
  /// [_screenshots] and for the same reason: see [_heartbeat]'s doc comment
  /// above for why this has to be an explicit `initState` assignment.
  ///
  /// Null when exempt — it then never polls live-wanted and never uploads.
  LiveViewService? _liveView;

  /// The agent half of Remote Control (2026-09-02-monitoring-remote-control,
  /// Part A) — polls this device's input queue and applies a manager's
  /// mouse/keyboard via the native channel. Same lifecycle as [_liveView] and
  /// for the same reason: see [_heartbeat]'s doc comment above for why this
  /// has to be an explicit `initState` assignment.
  ///
  /// Null when exempt — it then never polls input-pending and applies nothing.
  RemoteControlService? _remoteControl;
  FileAccessService? _fileAccess;

  /// Field GPS sharing, on a phone whose role is tracked. It asks the server
  /// whether to share and starts or stops itself; null off a phone.
  FieldTracker? _fieldTracker;

  /// Ctrl+K / ⌘K from anywhere in the shell; set by _shell, which knows the
  /// destinations (monday port, desk 2026-11-09).
  VoidCallback? _quickSearch;

  bool _quickSearchKey(KeyEvent event) {
    final k = HardwareKeyboard.instance;
    if (event is KeyDownEvent &&
        event.logicalKey == LogicalKeyboardKey.keyK &&
        (k.isControlPressed || k.isMetaPressed) &&
        _quickSearch != null) {
      _quickSearch!();
      return true;
    }
    return false;
  }

  @override
  void initState() {
    super.initState();
    HardwareKeyboard.instance.addHandler(_quickSearchKey);

    // One-time tips: what this person already dismissed, and where a new
    // dismissal is written.
    Callouts.seen.value = {
      for (final id
          in widget.session.me?.preferences['callouts.seen'] as List? ??
              const [])
        '$id',
    };
    Callouts.ready = widget.session.me != null;
    Callouts.onDismiss = (_) => widget.session.setPreference(
      'callouts.seen',
      Callouts.seen.value.toList(),
    );

    // SECURITY exemption: the Lead Developer's own device still beats (online)
    // and reports specs, but never captures, live-views or is remote-driven —
    // so the heartbeat starts unconditionally while the three capture services
    // are simply never constructed for an exempt session. The heartbeat is also
    // told, so it omits the `screenshot` capability from its beat.
    final exempt = widget.session.me?.screenMonitoringExempt ?? false;

    _heartbeat = MonitoringHeartbeat(
      widget.api,
      screenMonitoringExempt: exempt,
    );

    // RGB lighting a manager sets in Monitoring, and the hardware inventory
    // (motherboard, RAM, monitors…) that rides in specs. Windows only.
    startLighting(widget.api);

    // The availability icon, given a minute hand.
    //
    // Do Not Disturb set on the web now reaches the desktop's SERVER state
    // immediately, and the desktop's ICON found out only when something else
    // made it look — which in practice meant changing tabs. Jose: "the status
    // now syncs across devices, which is good, but only displays the change
    // once you change tabs."
    //
    // I had claimed the app coming forward would cover it. It does not, and
    // his report is what showed that: two windows side by side never leave
    // the screen, so `AppLifecycleState.resumed` never fires and the hook
    // watching for it can only wait. That claim is corrected where I made it.
    //
    // A minute is the wrong answer for a phone ringing and the right one for
    // an icon: what it prevents is somebody reading a stale dot for the rest
    // of the afternoon. Anything that must be immediate — a call starting or
    // ending — already notifies in the same frame.
    _heartbeat.onBeat = () {
      PhoneCallService.current.presenceMayHaveChanged();
      // A phone account that just got its first line is picked up within a
      // minute, without a reload (Jose, 2026-11-12).
      unawaited(PhoneCallService.current.syncAccounts());
    };
    _fieldTracker = FieldTracker.startIfPhone(widget.api, widget.session);
    // So no computer asks somebody who has the phone app to install it.
    markPhoneAppUsed(widget.session);
    if (!exempt) {
      // The capture service tells the heartbeat how each attempt went, and
      // the beat carries it to the server — otherwise a device that cannot
      // capture at all is indistinguishable from one that has not tried yet.
      _screenshots = ScreenshotService(
        widget.api,
        onOutcome: _heartbeat.reportCapture,
      );
      _liveView = LiveViewService(widget.api);
      _remoteControl = RemoteControlService(widget.api);
      _fileAccess = FileAccessService(widget.api);
    }

    _reminder = ShiftReminderController(
      api: widget.api,
      session: widget.session,
      context: () => context,
    );
    _reminder.sync();
    widget.session.addListener(_reminder.sync);
    _stopClockListening = () => widget.session.removeListener(_reminder.sync);

    // One small block every minute while the organization has the clock on,
    // so an exemption, a schedule change or a server-side close reaches an
    // open app (design 2026-09-08 §3.12). Same cadence as the bell.
    _accessPoll = Timer.periodic(
      const Duration(seconds: 60),
      (_) => widget.session.refresh(),
    );
    _accessOnResume = AppLifecycleListener(
      onResume: widget.session.refresh,
      onShow: widget.session.refresh,
    );

    _clockPoll = Timer.periodic(const Duration(seconds: 60), (_) async {
      final status = widget.session.me?.timeClock;
      if (status == null ||
          (!status.organizationEnabled && status.open == null)) {
        return;
      }
      final response = await widget.api.get('/time-clock/me');
      if (!mounted || !response.ok || response.data is! Map) return;
      final block = (response.data as Map)['time_clock'];
      if (block is Map) {
        widget.session.applyTimeClock(Map<String, dynamic>.from(block));
      }
    });

    // The softphone comes up with the SHELL, not with the Dial tab.
    //
    // It used to be created and disposed by CallTab, and this shell renders
    // only the selected module — so a person who had not opened Communication,
    // or who had walked away from it, was unreachable and nothing said so.
    // Starting it here is what makes the bar below able to exist at all.
    //
    // Safe for somebody with no phone line: start() looks, finds nothing, and
    // leaves everything false.
    _followScope();
    widget.session.addListener(_followScope);

    _apply(widget.address?.path);
    // The day-before email's "Open Booked vs available" link carries its day
    // in the query, which the address bar's path drops (UI queue 147).
    // Leads opens one lead through its constructor (see [_screenFor]); every
    // other module takes the link from [DeepLinks].
    if (DeepLinks.fromUri(Uri.base) case final link?) {
      if (link.module == 'leads') {
        _leadsOpen = link.id;
      } else {
        DeepLinks.open(link);
      }
    }
    DeepLinks.module.addListener(_openRequestedModule);
    _stopListening = widget.address?.listen((path) {
      if (!mounted) return;
      setState(() => _apply(path));
    });
  }

  /// Which view the desk was brought up for, so a change can be noticed.
  String? _deskScope;
  String? _deskAccess;

  /// Try again for a desk that never came up, now that the view has moved.
  ///
  /// `start()` reads `/phone/numbers`, which answers for the scope in view,
  /// and it ran ONCE — at sign-in. Somebody whose view had no line at that
  /// moment kept a dead desk for the rest of the session however they
  /// navigated afterwards, and the screen explained it by saying the phone
  /// needed a web browser. Jose read that in a web browser. Android had hit
  /// the same thing from the other side: "I changed scope by accident, which
  /// silently stopped setup() running at all."
  ///
  /// ── Only when there is no desk to lose ───────────────────────
  ///
  /// The tempting version re-registers on every scope change, so the desk
  /// always matches the company on screen. That also makes an org admin
  /// unreachable for as long as they browse a sub company with no line of its
  /// own — an ordinary thing to do, and a worse failure than the one being
  /// fixed. So this only reaches for a line when there is none.
  ///
  /// ponytail: the desk can still be registered on a company the reader has
  /// since navigated away from. Give it the scope's own line when somebody
  /// asks for that, and decide then what a lineless scope should do.
  void _followScope() {
    // Access changed (a line or account granted): register on what is new.
    final access = widget.session.me?.accessVersion;
    if (access != _deskAccess) {
      _deskAccess = access;
      unawaited(PhoneCallService.current.syncAccounts());
    }

    final scope = widget.session.me?.scope;
    final key = scope == null ? null : '${scope.type}:${scope.id}';
    if (key == _deskScope) return;
    _deskScope = key;

    final phone = PhoneCallService.current;
    if (phone.hasLine) return;

    // NOT WHILE A CALL IS IN FLIGHT. This guard was here, I took it out, and
    // taking it out cost Jose a call.
    //
    // The first draft of this method refused while `onCall`. Narrowing the
    // method to "only when there is no line" I decided `hasLine` subsumed it —
    // if there is no line there is no call. That is false twice over, and the
    // second way is the one that bit: `stop()` clears `_connectionId`, so
    // `hasLine` is false on every shell remount, and a call can be RINGING
    // rather than connected, which `onCall` would not have caught either.
    //
    // What it costs to be wrong: restart() disposes the device, twilio-voice's
    // `Device.destroy()` rejects a pending incoming call, and Twilio reports
    // the leg BUSY. Jose pressed Answer on the desktop and his own phone said
    // "please leave your message after the tone" — the caller was refused by
    // the desk that was ringing.
    //
    // So the question is about the DEVICE, not about the line: is there a call
    // on it, of any kind. A desk with somebody on it is never worth restarting
    // — the reason this method exists is a banner being wrong, and no banner
    // is worth a dropped call.
    if (phone.busy) return;

    unawaited(phone.restart(widget.api));
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_quickSearchKey);
    Callouts.ready = false;
    _stopListening?.call();
    DeepLinks.module.removeListener(_openRequestedModule);
    _heartbeat.dispose();
    stopLighting();
    _fieldTracker?.dispose();
    // Null for an exempt session, where they were never constructed.
    _screenshots?.dispose();
    _liveView?.dispose();
    _remoteControl?.dispose();
    _fileAccess?.dispose();

    widget.session.removeListener(_followScope);

    // stop(), not dispose(): the service is a singleton that outlives this
    // widget, and the next person to sign in is not this person.
    PhoneCallService.current.stop();

    _clockPoll?.cancel();
    _accessPoll?.cancel();
    _accessOnResume?.dispose();
    _stopClockListening?.call();
    _reminder.dispose();

    super.dispose();
  }

  /// Read the path into [_rail] / [_selected]. Unknown or empty paths leave
  /// the selection as it is; [build] falls back to the first reachable
  /// destination and rewrites the URL to match.
  void _apply(String? path) {
    final id = Address.idOf(path ?? '');
    if (id == null) return;

    if (id == 'account') {
      _rail = _Rail.profile;
      _selected = null;
      return;
    }

    if (id == 'organization' || id == 'settings') {
      _rail = _Rail.pinned;
      // Organization used to be its own pinned row. It now lives inside
      // Settings, so an old /organization URL still opens this destination
      // rather than falling through to Dashboard.
      _selected = 'settings';
      return;
    }

    if (id == 'suggestions') {
      _rail = _Rail.pinned;
      _selected = id;
      return;
    }

    if (id == 'development') {
      _rail = _Rail.staff;
      _selected = id;
      return;
    }

    // A module drawn inside another one has no destination of its own, so its
    // old URL names nothing: /leads, /reviews and /business_profile were three
    // sidebar rows until they became tabs inside Marketing. Opening one selects
    // the tab it is drawn in rather than falling through to the first module.
    //
    // Which CHIP of Marketing it lands on is not restored, exactly as
    // /organization above does not restore a chip inside Settings. The address
    // holds a destination; the strip inside one is not one.
    _rail = _Rail.modules;
    _selected = widget.session.me?.module(id)?.parent ?? id;
  }

  String _pathFor(_Rail rail, String? id) {
    if (rail == _Rail.profile || id == null || id.isEmpty) return '/account';
    return Address.pathOf(id);
  }

  void _syncShown(String path) {
    final address = widget.address;
    if (address == null || address.path == path) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      widget.address?.replace(path);
    });
  }

  /// WHICH destination within that group, by id — a module key, or a pinned
  /// destination's own name. Null means "whatever comes first".
  ///
  /// This used to be one `int` across all three groups, with a comment warning
  /// that the arithmetic was delicate. That warning was written about lists
  /// whose lengths were fixed when the app was compiled. The business
  /// destinations are now assembled per person, and the list can change length
  /// WHILE somebody is looking at it: EzBizi switches a module off mid-incident
  /// (§13), or an administrator edits the role they hold.
  ///
  /// Under an index, that is silent and wrong. Every destination after the one
  /// that vanished shifts up a slot, so the person is quietly moved to a
  /// different screen; if it was the last one, the index points past the end
  /// and the next frame throws — a blank page with no way back.
  ///
  /// A name cannot shift, and "the thing you were looking at is gone" is a
  /// question with an obvious answer rather than an off-by-one. See `_resolve`
  /// in build().
  String? _selected;

  /// In-flight punch. The button is disabled so a second tap cannot fire
  /// another POST while the first is still unanswered.
  bool _punching = false;

  /// Server sentence from a refused punch. A SnackBar is a popup; this sits
  /// as a one-line strip under the AppBar, same family as the two-factor chip.
  String? _timeClockNotice;

  /// In-flight resend of the confirmation link, so a second tap cannot fire
  /// another POST while the first is unanswered. The endpoint is throttled
  /// server-side per address AND per IP; this stops a person burning that
  /// allowance on their own double-click.
  bool _resendingConfirmation = false;

  /// Outcome of that resend. Its own strip rather than [_timeClockNotice]:
  /// that one is named for the time clock and tinted as an error, and the
  /// usual outcome here is success.
  String? _confirmationNotice;

  /// The lead a notification was about, opened rather than the newest one.
  ///
  /// A notification no longer narrows Leads to its source: a Google one used
  /// to leave the Google chip selected for the rest of the session, so Leads
  /// opened on Google instead of All (Jose, 2026-09-28). The lead is opened
  /// and pinned whatever filter the list shows. Cleared when Leads is opened
  /// any other way.
  String? _leadsOpen;

  /// One module screen, with the one destination that accepts a filter.
  ///
  /// A special case at a single site rather than a third argument on every
  /// builder in `moduleScreens`: the typedef is `(api, session)` across some
  /// forty screens, and Dart will not accept the existing lambdas against a
  /// signature with an extra optional parameter — so generalising costs forty
  /// edits to serve one consumer. When a SECOND module wants to be opened
  /// narrowed, that is the moment to change the typedef rather than add a
  /// second branch here.
  ///
  /// The ValueKey is what makes it work twice. Every destination is built up
  /// front and kept, so LeadsScreen applies `initialSource` in initState and
  /// would never see a second, different filter — arriving from one chat
  /// notification and then from a form one would leave the first filter in
  /// place. Changing the key rebuilds the screen, which re-runs initState.
  Widget _screenFor(String key, ModuleScreen screen) {
    if (key == 'leads' && _leadsOpen != null) {
      return LeadsScreen(
        key: ValueKey('open|$_leadsOpen'),
        api: widget.api,
        session: widget.session,
        initialLeadId: _leadsOpen,
      );
    }

    return screen.build(widget.api, widget.session);
  }

  void _show(_Rail rail, String id, {String? openLead}) => setState(() {
    _leadsOpen = openLead;
    _rail = rail;
    _selected = id;
    widget.address?.push(_pathFor(rail, id));
    unawaited(_heartbeat.enter(id));
  });

  /// Another screen sending someone here ([OpenDestination]): read the id the
  /// way a typed URL is read, so a child module opens inside its parent.
  void _openRequestedModule() {
    final key = DeepLinks.module.value;
    if (key == null || !mounted) return;
    DeepLinks.module.value = null;
    _open(key);
  }

  void _open(String id) {
    setState(() {
      _leadsOpen = null;
      _apply(Address.pathOf(id));
    });
    widget.address?.push(_pathFor(_rail, _selected));
    if (_selected case final shown?) unawaited(_heartbeat.enter(shown));
  }

  /// "Your account" is reached from the avatar, not from a rail, so it has no
  /// id of its own.
  void _showProfile() => setState(() {
    _rail = _Rail.profile;
    _selected = null;
    widget.address?.push('/account');
  });

  Future<void> _signOut() async {
    await widget.api.post('/auth/logout', {});
    widget.session.signOut();
  }

  /// Ask for another confirmation link.
  ///
  /// This exists because the server's refusal used to say "ask for another
  /// link if it did not arrive" while nothing in this client called the
  /// endpoint -- a sentence naming a control that was on no screen, which is
  /// worse than a shorter one because the reader believes there is a way out
  /// and goes looking for it.
  ///
  /// POST /auth/verify/resend answers identically whether or not the address
  /// matched, so there is no success to read out of it -- only that it was
  /// accepted. The sentence below says what we DID, never that it arrived.
  Future<void> _resendConfirmation() async {
    final me = widget.session.me;
    if (me == null || _resendingConfirmation) return;

    setState(() {
      _resendingConfirmation = true;
      _confirmationNotice = null;
    });

    final response = await widget.api.post('/auth/verify/resend', {
      'email': me.email,
    });

    if (!mounted) return;

    setState(() {
      _resendingConfirmation = false;
      _confirmationNotice = response.ok
          ? 'We have sent another confirmation link to ${me.email}.'
          : response.reason;
    });
  }

  Future<void> _punch() async {
    final me = widget.session.me;
    if (me == null || _punching || !me.timeClock.showButton) return;

    final clockedIn = me.timeClock.open != null;

    setState(() {
      _punching = true;
      _timeClockNotice = null;
    });

    final response = await widget.api.post(
      clockedIn ? '/time-clock/clock-out' : '/time-clock/clock-in',
      await punchPosition(me.timeClock.needsLocation),
    );

    if (!mounted) return;

    if (response.ok) {
      final data = response.data;
      if (data is Map) {
        final block = data['time_clock'];
        if (block is Map<String, dynamic>) {
          widget.session.applyTimeClock(block);
        } else if (block is Map) {
          widget.session.applyTimeClock(Map<String, dynamic>.from(block));
        }
      }
      setState(() => _punching = false);
      return;
    }

    setState(() {
      _punching = false;
      _timeClockNotice = response.reason;
    });
  }

  /// Phone width, or the app as it is.
  ///
  /// ── Why this overrides the VIEWPORT and not a flag ───────────────
  ///
  /// This shell decides its shape from one line — `width >= 900`, below — but
  /// it is not the only thing that asks. Nine reads of the viewport width live
  /// across five files at THREE breakpoints: 900 here, in `staff_shell` and in
  /// Web widgets; 600 in `lead_thread`; 480 in `auth_shell`; and `side_panel`
  /// clamping its own width to the window. Flipping this shell's boolean would
  /// give a narrow shell with DESKTOP screens inside it — a mobile view that is
  /// wrong in exactly the places somebody would be using it to check.
  ///
  /// Overriding `MediaQuery` makes all nine agree, and no screen has to know
  /// this control exists.
  ///
  /// The SizedBox is the other half: the override changes what the app is TOLD,
  /// and this changes what it is given. Without it the app would report 390 and
  /// still be laid out across the whole window — a drawer instead of a rail,
  /// stretched over 1600px, which is not what anything looks like.
  @override
  Widget build(BuildContext context) {
    if (!_mobileView) return _shell(context);

    return ColoredBox(
      color: Theme.of(context).scaffoldBackgroundColor,
      child: Align(
        alignment: Alignment.topLeft,
        child: SizedBox(
          width: _phoneWidth,
          child: LayoutBuilder(
            builder: (context, constraints) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(size: Size(_phoneWidth, constraints.maxHeight)),
              child: Builder(builder: _shell),
            ),
          ),
        ),
      ),
    );
  }

  Widget _shell(BuildContext context) {
    final me = widget.session.me!;
    final wide = MediaQuery.sizeOf(context).width >= 900;

    // Labels beside icons, unless this person has collapsed it or prefers the
    // stacked "blocks" shape. Stored, so it survives a reload — a sidebar that
    // springs back open every time is a toggle nobody uses twice.
    final expanded = me.navigationStyle == 'list' && !me.navigationCollapsed;

    // The business destinations, as the server describes them.
    //
    // Order is the server's, and so is every label — the module registry that
    // decides what a module IS decides where it sits and what it is called,
    // rather than a second opinion in Dart drifting away from it. Dashboard
    // still leads the list, but because the server puts it there rather than
    // because this file does.
    //
    // Two consequences, both deliberate:
    //
    //   - a module the server does not declare gets no destination, however
    //     well this build knows how to draw it. Silence is not permission, and
    //     there is no label to print for something we were never told about;
    //   - a module the server DOES declare that this build has no screen for is
    //     skipped rather than drawn as a tab that opens nothing.
    //
    // See core/module_screens.dart.
    var destinations = <Destination>[
      // Their own paperwork, first, while it is unfinished.
      //
      // The one destination here that is not a module, and it earns the
      // exception. A new starter accepts an invitation, which signs them in,
      // and the form their employer began for them is the only thing they are
      // in this app to do — but Human Resources is the EMPLOYER's module and
      // their role does not reach it, so without this they land in a shell with
      // nothing in it and no way to find their own contract.
      //
      // It is not compiled in: it exists exactly when `/me` says there is an
      // onboarding about this person, and it disappears when the server stops
      // saying so. Being first is what makes it the landing page — `railIndex`
      // falls back to the first destination — so accepting an invitation puts
      // the form on screen without a link to keep or a step to remember, and
      // everything else is still one click away.
      if (me.onboardingId case final mine?)
        Destination(
          'Your paperwork',
          Icons.assignment_outlined,
          OnboardingForm(
            api: widget.api,
            onboardingId: mine,
            // The employee cannot read the Contracts catalogue — it belongs to
            // the module they have no permission for — so the words for a kind
            // of question travel with the onboarding itself. See
            // OnboardingForm._vocabulary.
            vocabulary: ContractVocabulary.none,
            formats: me.display,
            // Signing it finishes it, and the destination should go when it
            // does. /me is where its existence is declared, so /me is what has
            // to be asked again.
            onChanged: widget.session.refresh,
          ),
          // Not a module key, and spelled so it cannot collide with one: module
          // keys are bare words (`hr`, `jobs`) and this is the shell's own name
          // for a destination the module registry knows nothing about.
          id: 'my-onboarding',
        ),
      for (final module in me.modules ?? const <ModuleAccess>[])
        if (_hasDestination(me, module))
          if (moduleScreens[module.key] case final screen?)
            Destination(
              module.label,
              screen.icon,
              _screenFor(module.key, screen),
              id: module.key,
            ),
    ];

    // Their own order, applied ONCE and here.
    //
    // Everything downstream — the fallback selection, the rail's index
    // translation, the drawer — indexes this list, so rearranging it at the
    // source means none of them has to know the order exists. `divideAfter`
    // holds positions in it, and is empty for everybody who has never dragged
    // anything.
    final arranged = arrangeSidebar(
      destinations,
      me.navigationOrder,
      (d) => d.id,
    );
    // Under fixed group headings (Muse audit 1A); their order still
    // decides the order inside each group.
    final grouped = groupSidebar(
      arranged.rows,
      arranged.divideAfter,
      (d) => d.id,
    );
    destinations = grouped.rows;
    final divideAfter = grouped.divideAfter;
    final headingAt = grouped.headingAt;

    // Pinned to the bottom, under a rule and the empty space.
    //
    // Neither is a place you go to do the work. Settings is where you go to
    // change how the work behaves — the shape of the business, the cascade,
    // and its roles — and Development is the platform underneath the product
    // rather than part of it (§4.2). Both belong away from the list of things
    // you actually open all day.
    final pinned = <Destination>[
      Destination(
        UiStrings.t('shell.settings'),
        Icons.settings_outlined,
        SettingsShell(api: widget.api, session: widget.session),
        id: 'settings',
      ),
      // Suggestions is pinned rather than a rail destination because it is not
      // in `me.modules` and never varies. The business rail is assembled per
      // person and can change length while somebody is looking at it; this
      // cannot. It is deliberately not gated on anything — there is no module
      // key, no permission and no availability flag behind it, which is what
      // makes it unremovable. See App\Models\Suggestion.
      Destination(
        'Suggestions',
        Icons.lightbulb_outline,
        SuggestionsScreen(
          api: widget.api,
          formats: me.display,
          // "Which tab is it about?" offers exactly the sidebar they see.
          tabs: [
            for (final d in destinations)
              if (d.id != 'my-onboarding') (key: d.id, label: d.label),
            (key: 'settings', label: UiStrings.t('shell.settings')),
          ],
        ),
        id: 'suggestions',
      ),
    ];

    // Development gets its own group, below its own rule.
    //
    // It was a row of `pinned`, which said it was the same KIND of thing as
    // Settings. It is not: Settings configures the business and every
    // administrator has it. This is the platform underneath the product
    // (§4.2), it exists only when the server says this person is also EzBizi
    // staff, and most people signed into this app will never see it at all.
    //
    // A third rail rather than a divider between destinations, for the reason
    // the second one exists: NavigationRail cannot put a rule between its own
    // items, and the alternative is a hand-drawn row that does not match the
    // real ones. That was tried and produced four separate defects.
    final staffOnly = <Destination>[
      if (me.development != null)
        Destination(
          'Development',
          Icons.terminal_outlined,
          DevelopmentScreen(session: widget.session),
          id: 'development',
        ),
    ];

    // "Your account" is deliberately NOT a rail destination. It is reached from
    // the avatar in the top bar, where every other app puts it — it is about the
    // person, not about the business, and a tab beside Customers and Jobs
    // implies it is one more thing to work on.
    //
    // Resolved from the NAME held in `_selected`, every build. Each rail is
    // asked only when it is the one holding the selection, so exactly one of
    // these can be non-null — which is what NavigationRail's own assert wants.
    int? resolve(List<Destination> group, _Rail rail) {
      if (_rail != rail) return null;

      final at = group.indexWhere((d) => d.id == _selected);

      return at < 0 ? null : at;
    }

    final showingProfile = _rail == _Rail.profile;
    final pinnedIndex = resolve(pinned, _Rail.pinned);
    final staffIndex = resolve(staffOnly, _Rail.staff);

    // Falls back to the first business destination whenever the selection no
    // longer names anything: the module was switched off under them, their role
    // stopped reaching it, or a staff link was removed and the group it was in
    // has gone. Null when there is nothing to fall back to, which is a real
    // state — somebody whose role reaches no module at all.
    final railIndex =
        (showingProfile || pinnedIndex != null || staffIndex != null)
        ? null
        : resolve(destinations, _Rail.modules) ??
              (destinations.isEmpty ? null : 0);

    final shownId = showingProfile
        ? 'account'
        : pinnedIndex != null
        ? pinned[pinnedIndex].id
        : staffIndex != null
        ? staffOnly[staffIndex].id
        : railIndex != null
        ? destinations[railIndex].id
        : 'account';
    _syncShown(Address.pathOf(shownId));
    // The first screen after launch is never `enter`ed, so reports from it
    // said `module: unknown` (2026-10-27). Name whatever is actually shown.
    IssueReporter.module = shownId;
    IssueReporter.route = Address.pathOf(shownId);

    final barStates = _BarStates(
      // A state is not a control, and states already have a home here:
      // `_timeClockNotice` is described in this file as sitting "as a
      // one-line strip under the AppBar, same family as the two-factor
      // chip". The chip was simply never put in it.
      //
      // On a phone that is not tidiness. Measured at 375 with both
      // showing, the actions row runs off the right edge -- these two
      // are conditional, so the worst case is worse than any screenshot
      // of a healthy account.
      twoFactor: !wide && me.twoFactorEnrolmentRequired,
      readOnly: !wide && me.organization.isReadOnly,
      // The third state this strip's own comment anticipated. It
      // scrolls rather than wraps, so it does not push every screen
      // down a row on a phone.
      emailConfirmation: !wide && me.emailConfirmationRequired,
      onTwoFactor: _showProfile,
      onEmailConfirmation: _resendConfirmation,
      // The company picker on its own row on a phone (live 0.0.0597 at 375:
      // between the hamburger and the icons it got ~20 px and drew an
      // empty box). Only when there is somewhere else to stand.
      scope: !wide && ScopeSwitcher.hasChoices(me)
          ? ScopeSwitcher(api: widget.api, session: widget.session, block: true)
          : null,
    );

    final shell = Scaffold(
      key: _scaffoldKey,
      // The top bar belongs to the CHROME, not to the page.
      //
      // In a mixed mode it takes the sidebar's theme, so the bar and the
      // sidebar read as one continuous frame around a page in the other theme.
      // Siding it with the page instead put a seam across the top of the
      // sidebar, which made the two halves look misaligned rather than
      // deliberately different.
      //
      // PreferredSize because Scaffold.appBar demands one and a Theme is not:
      // the height is the toolbar plus what hangs below it (the rule, and on
      // a phone the state strip and the company picker's row).
      appBar: PreferredSize(
        preferredSize: Size.fromHeight(
          kToolbarHeight + barStates.preferredSize.height,
        ),
        child: _maybeThemed(
          EzBiziTheme.navigationOverride(me.theme),
          builder: (context) => AppBar(
            // A rule under the top bar.
            //
            // The bar and the rail were both plain surface, so on a light theme
            // they met as one continuous white field and the sidebar read as part
            // of the header. One pixel fixes it; nothing else would.
            bottom: barStates,
            leading: wide
                ? IconButton(
                    tooltip: expanded
                        ? 'Collapse the sidebar'
                        : 'Expand the sidebar',
                    icon: Icon(expanded ? Icons.menu_open : Icons.menu),
                    // Written through the same path as every other preference, so
                    // it is the server's answer that wins and the value is there on
                    // the next device somebody signs in from.
                    onPressed: () => widget.session.setPreference(
                      'navigation.collapsed',
                      !me.navigationCollapsed,
                    ),
                  )
                : null,
            titleSpacing: 0,
            /*
         * The wordmark sits beside the collapse button, in the header band above
         * the sidebar — the product's identity belongs to the application
         * chrome, not to one column of it.
         *
         * The ORGANIZATION does not live here. It is the sidebar's subject:
         * every destination below it is scoped to that organization, so it is
         * stated at the top of the thing it scopes.
         *
         * Except when the sidebar is collapsed, when there is no room for it
         * there — then it appears here instead. Which one is showing is decided
         * by where there is space to say it, and it is never said twice.
         */
            // The organization's NAME is gone from here, replaced by the switcher
            // that shows where you are standing. Two controls saying almost the
            // same thing — a fixed name and a changeable view — is how somebody
            // ends up sure they are in one place while the lists show another.
            title: LayoutBuilder(
              builder: (context, constraints) {
                final brand = <Widget>[
                  EzBiziTheme.wordmark(context, size: 20),
                  const SizedBox(width: 14),
                  Container(
                    width: 1,
                    height: 18,
                    color: Theme.of(context).dividerColor,
                  ),
                  const SizedBox(width: 14),
                  // The brand first, then the control. Reading order matches
                  // what the eye needs: whose business am I in, then where in it.
                  ScopeSwitcher.logo(widget.api, widget.session),
                  const SizedBox(width: 12),
                ];
                final switcher = ScopeSwitcher(
                  api: widget.api,
                  session: widget.session,
                );

                // On a phone the brand row does not shrink -- it goes.
                //
                // This used to be `FittedBox(scaleDown)` below 240px, and Jose
                // reported the result as "the ezbizi logo itself is impossible
                // to see" (2026-09-10). It was not truncated, it was SCALED
                // past reading: a mark nobody can read still takes its width
                // and says nothing, which is worse than absence.
                //
                // So the wordmark, the rule and the company logo are dropped
                // together below 900, and the switcher gets a row of its own. It
                // still NAMES the company in words, which is what the logo was
                // saying in pixels -- nothing that identifies the business is
                // lost, only a second copy of it.
                if (!wide) {
                  // The switcher draws NOTHING for a single-company business
                  // (`ScopeSwitcher.hasChoices`) — there is nowhere else to
                  // stand. Dropping the brand and leaning on it to name the
                  // company would have left those organizations with a bar that
                  // identifies nothing: a hamburger and some icons.
                  //
                  // So when it has nothing to offer, the name is said plainly.
                  // Asking the switcher rather than re-deriving the condition
                  // here keeps one definition of "is there a choice".
                  // With a choice, the switcher has its own row below
                  // (`_BarStates.scope`).
                  return ScopeSwitcher.hasChoices(me)
                      ? const SizedBox.shrink()
                      : Text(
                          me.scope.label,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleSmall,
                        );
                }

                return Row(
                  children: [
                    ...brand,
                    Flexible(child: switcher),
                  ],
                );
              },
            ),
            actions: [
              if (me.timeClock.showButton)
                _ClockButton(
                  open: me.timeClock.open != null,
                  busy: _punching,
                  onPressed: _punch,
                  // Icon only on a phone, which is what buys the room for
                  // availability to stay in the bar beside it (Jose, layout 6
                  // plus his amendment, 2026-09-10).
                  compact: !wide,
                ),

              // Beside Clock In, because they are the same kind of fact: am I
              // here, and am I taking calls. It used to live on the Dial pad,
              // so the only way to stop your phone ringing was to navigate to
              // the phone — two taps away at exactly the moment you want it.
              // Draws nothing for somebody who is not on a line.
              AvailabilityButton(api: widget.api),

              // Desktop and phone shapes, one press apart. Staff only: the
              // same gate the Development rail uses (§4.2), because it is a
              // tool for building the product rather than part of it.
              //
              // It stays in the bar in mobile view rather than moving
              // somewhere roomier, by Jose's pick (layout 4) — which means it
              // is the way back as well as the way in, and the way back must
              // not be somewhere a phone does not draw.
              if (me.development != null)
                IconButton(
                  icon: Icon(
                    _mobileView
                        ? Icons.phone_iphone
                        : Icons.phone_iphone_outlined,
                  ),
                  isSelected: _mobileView,
                  tooltip: _mobileView
                      ? UiStrings.t('shell.exit_mobile_view')
                      : UiStrings.t('shell.mobile_view'),
                  onPressed: () {
                    final on = !_mobileView;
                    setState(() => _mobileView = on);
                    // Not awaited, and that is the point rather than
                    // laziness: the layout has already changed by the line
                    // above, and the window catching up is a separate,
                    // fallible thing. Awaiting it would also hang a widget
                    // test, where a plugin call never completes.
                    unawaited(
                      on
                          ? MobileViewWindow.shrink(_phoneWidth)
                          : MobileViewWindow.restore(),
                    );
                  },
                ),
              // Light / dark, one tap, from anywhere.
              //
              // It is the same preference the account screen holds (§2.1 category
              // 6), written through the same path — this is a shortcut to it, not a
              // second setting. Two places that both claim to control the theme and
              // disagree is worse than one place that is hard to find.
              //
              // Cycles system -> light -> dark rather than toggling two states: the
              // default is "match my device", and a two-way switch would silently
              // throw that away the first time anybody touched it.
              // Desktop only, by Jose's word (2026-09-10): "we can get rid of
              // the light-dark mode switcher and have it only managed via your
              // account on mobile". It was always a shortcut to the account
              // setting rather than a second one, so removing it here removes a
              // path and never a capability -- which is the only reason it is
              // safe to drop rather than relocate.
              if (wide)
                IconButton(
                  tooltip: switch (me.theme) {
                    'light' => 'Light — switch to dark',
                    'dark' => 'Dark — match my device',
                    _ => 'Matching your device — switch to light',
                  },
                  icon: Icon(switch (me.theme) {
                    'light' => Icons.wb_sunny_outlined,
                    'dark' => Icons.dark_mode_outlined,
                    // Not brightness_auto: that glyph is a sun with an A, and
                    // it sits next to the two-factor shield that also carries
                    // an A. Contrast is the half-and-half circle — light and
                    // dark in one mark, no letter.
                    _ => Icons.contrast,
                  }),
                  onPressed: () => widget.session.setPreference(
                    'appearance.theme',
                    switch (me.theme) {
                      'system' => 'light',
                      'light' => 'dark',
                      _ => 'system',
                    },
                  ),
                ),
              IconButton(
                key: const Key('shell-quick-search'),
                tooltip: 'Search (Ctrl+K)',
                icon: const Icon(Icons.search),
                onPressed: _quickSearch,
              ),
              NotificationBell(
                api: widget.api,
                // The shell already knows how to select a module; the bell
                // only needs to name one.
                // Suggestions is the one pinned destination a notification
                // points at; everything else is a module.
                onOpenModule: (key, filter, record) => _show(
                  key == 'suggestions' ? _Rail.pinned : _Rail.modules,
                  key,
                  openLead: record,
                ),
                // Each notification wears its module's sidebar glyph.
                iconOf: (key) => moduleScreens[key]?.icon,
                formats: widget.session.me?.display,
                // Each person's own "Alert me" switch, in the panel.
                session: widget.session,
              ),
              // The organization requires a second factor and this person has
              // not set one up (§6). Every screen except their own account is
              // answering 403 until they do, so the chrome has to say why and
              // point at the one place that fixes it — otherwise the app simply
              // looks broken.
              //
              // A chip beside the read-only one rather than a modal: same kind
              // of statement about the state of the session, and it stays
              // visible while they act on it.
              if (wide && me.twoFactorEnrolmentRequired)
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: ActionChip(
                    avatar: const Icon(Icons.shield_outlined, size: 16),
                    label: const Text('Set up two-factor'),
                    tooltip:
                        'Your organization requires two-factor '
                        'authentication. The rest of the app is on hold until '
                        'your authenticator is set up.',
                    backgroundColor: Theme.of(
                      context,
                    ).colorScheme.errorContainer.withValues(alpha: 0.4),
                    onPressed: _showProfile,
                  ),
                ),
              // Same family as the two-factor chip above and for the same
              // reason: every SAVE is answering 403 until this is done, so
              // the chrome has to say why and offer the one action that
              // helps. Reads still work, which is exactly why the app looks
              // healthy until somebody tries to keep something.
              if (wide && me.emailConfirmationRequired)
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: ActionChip(
                    avatar: const Icon(
                      Icons.mark_email_unread_outlined,
                      size: 16,
                    ),
                    label: Text(
                      _resendingConfirmation
                          ? 'Sending...'
                          : 'Confirm your email',
                    ),
                    tooltip:
                        'We sent a confirmation link when you signed up. '
                        'Saving anything is on hold until you open it. '
                        'Press to send another.',
                    backgroundColor: Theme.of(
                      context,
                    ).colorScheme.errorContainer.withValues(alpha: 0.4),
                    onPressed: _resendingConfirmation
                        ? null
                        : _resendConfirmation,
                  ),
                ),
              if (wide && me.organization.isReadOnly)
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: Chip(
                    avatar: const Icon(Icons.lock_outline, size: 16),
                    label: const Text('Read only'),
                    // §13 — stated up front, so a failed save is never a surprise.
                    backgroundColor: Theme.of(
                      context,
                    ).colorScheme.errorContainer.withValues(alpha: 0.4),
                  ),
                ),
              PopupMenuButton<String>(
                tooltip:
                    '${UiStrings.t('shell.your_account')} — ${me.name} (${me.email})'
                    '${me.profileMissing.isEmpty ? '' : '. Missing from your profile: ${profileMissingWords(me.profileMissing)}'}',
                // The photo, not just the initial. It was an initial always: the
                // profile screen was the only place a picture appeared, and even
                // there it did not, because it was fetched without the token the
                // endpoint requires. This is the same widget that card uses.
                // Pulsing amber while the profile lacks something (Jose,
                // 2026-09-30, from A1 Tools); still once it is complete.
                icon: ProfileAura(
                  active: me.profileMissing.isNotEmpty,
                  child: UserAvatar(
                    api: widget.api,
                    userId: me.id,
                    name: me.name,
                    radius: 15,
                    hasAvatar: me.hasAvatar,
                  ),
                ),
                itemBuilder: (_) => [
                  PopupMenuItem(
                    enabled: false,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          me.name,
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        Text(me.email, style: const TextStyle(fontSize: 12)),
                        if (me.roleName != null)
                          Text(
                            me.roleName!,
                            style: const TextStyle(fontSize: 12),
                          ),
                        if (me.profileMissing.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 6),
                            child: Text(
                              'Missing from your profile: '
                              '${profileMissingWords(me.profileMissing)}.',
                              style: TextStyle(
                                fontSize: 12,
                                color: StateColour.of(context).caution,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                  const PopupMenuDivider(),
                  PopupMenuItem(
                    value: 'me',
                    child: Text(UiStrings.t('shell.your_account')),
                  ),
                  const PopupMenuItem(
                    key: Key('menu-get-the-apps'),
                    value: 'apps',
                    child: Text('Get the apps'),
                  ),
                  PopupMenuItem(
                    value: 'out',
                    child: Text(UiStrings.t('shell.sign_out')),
                  ),
                ],
                onSelected: (v) {
                  if (v == 'out') {
                    _signOut();
                    return;
                  }
                  if (v == 'apps') {
                    showSidePanel<void>(
                      context,
                      builder: (context) => SidePanel(
                        title: 'Get the apps',
                        onClose: () => Navigator.of(context).pop(),
                        child: const GetTheAppsPanel(),
                      ),
                    );
                    return;
                  }

                  _showProfile();
                },
              ),
              const SizedBox(width: 8),
            ],
          ),
        ),
      ),
      /*
       * Edge to edge, handled rather than hoped for.
       *
       * The app targets SDK 36, and from Android 15 that means the system draws
       * the app under the status bar and under the gesture bar unless the app
       * says where its content may go. Play flagged this on release 97, and the
       * whole client had exactly one SafeArea in it — on the screen that tells
       * you to update, of all places.
       *
       * `top: false` because the AppBar above already consumes the status bar
       * inset; taking it twice would leave a band of empty chrome. The sides
       * matter in landscape, where a display cutout eats into the row.
       */
      body: SafeArea(
        top: false,
        child: Column(
          children: [
            if (!wide) _phoneBack(),
            /*
             * The call, above whatever you were doing.
             *
             * A strip rather than a modal, in the same Column as the update
             * notice: a ringing phone must not take the app away from somebody
             * halfway through writing up a job. Answer, keep typing.
             *
             * It draws nothing when nobody is calling and no call is up, so
             * this costs an empty box the rest of the time.
             */
            IncomingCallBar(onOpenDial: () => _show(_Rail.modules, 'phone')),

            /*
             * "Somebody is driving this machine", said by the app itself.
             *
             * The native tray notice is a tooltip plus a one-off balloon, and
             * a balloon is a courtesy the OS may decline: Focus Assist, a
             * full-screen window, or a notification policy set years ago all
             * swallow it, and Shell_NotifyIcon reports success regardless. A
             * consent notice whose only surface can be silently switched off
             * is a notice you cannot rely on having given.
             *
             * So this says it in the one place the person is certainly
             * looking. Null for a screen-monitoring-exempt session, which is
             * never remote-driven in the first place; and the flag it watches
             * is already the organization's opt-in ANDed with "is actually
             * being controlled", so an organization that wants a silent agent
             * still has one.
             */
            if (_remoteControl != null)
              ValueListenableBuilder<bool>(
                valueListenable: _remoteControl!.controlled,
                builder: (context, controlled, _) => controlled
                    ? const ControlledBanner()
                    : const SizedBox.shrink(),
              ),

            /*
             * Zero-sized, and load-bearing — the same widget CallTab used to
             * mount, moved up with the device that needs it.
             *
             * WebView2 delivers the microphone prompt to THIS widget's own
             * delegate. A softphone whose view is not in the tree registers and
             * then cannot hear anybody: a phone that looks perfect and is deaf.
             * Empty on every platform but Windows.
             */
            AnimatedBuilder(
              animation: PhoneCallService.current,
              builder: (context, _) => Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  voiceDeviceView(PhoneCallService.current.platformView),
                  // One per other phone account (EverDoors, 2026-11-11).
                  for (final view in PhoneCallService.current.extraViews)
                    voiceDeviceView(view),
                ],
              ),
            ),

            /*
             * A newer build, offered rather than imposed.
             *
             * A strip above the work, dismissed by acting on it, and never a
             * modal: somebody halfway through writing up a job should not have
             * the app taken away because a release happened. The wall is the
             * version gate's job, and only once the server actually refuses
             * this build — see core/desktop_update.dart for why both exist.
             */
            /*
             * A newer build, announced rather than sprung.
             *
             * The updater installs this by itself after a minute — see
             * core/desktop_updater.dart — so this strip is the warning, not the
             * offer. It says how long is left and gives a way out, because the
             * install closes the app and somebody halfway through writing up a
             * job should be able to say "not now". Postponing does not cancel
             * anything permanently: the next hourly sweep finds it again.
             *
             * A strip above the work, never a modal. The wall is the version
             * gate's job, and only once the server actually refuses this
             * build — see core/desktop_update.dart for why both exist.
             */
            AnimatedBuilder(
              animation: DesktopUpdater.instance,
              builder: (context, _) {
                final u = DesktopUpdater.instance;
                final left = u.secondsLeft;

                if (u.available == null && u.error == null) {
                  return const SizedBox.shrink();
                }

                return Material(
                  color: u.error != null
                      ? Theme.of(context).colorScheme.errorContainer
                      : Theme.of(context).colorScheme.primaryContainer,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
                    child: Row(
                      children: [
                        Icon(
                          u.error != null
                              ? Icons.error_outline
                              : Icons.system_update_alt,
                          size: 18,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            u.error ??
                                (u.waitingForCall
                                    ? 'EzBizi ${u.available!.version} will install '
                                          'when your call ends.'
                                    : u.waitingForDnd
                                    ? 'EzBizi ${u.available!.version} is ready. It '
                                          'installs when you set Do Not Disturb, '
                                          'or install it now.'
                                    : left != null
                                    ? 'EzBizi ${u.available!.version} installs in '
                                          '$left seconds. The app closes and reopens.'
                                    : 'EzBizi ${u.available!.version} is ready to '
                                          'install. The app closes and reopens.'),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        const SizedBox(width: 8),
                        if (u.busy)
                          const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        else ...[
                          if (left != null && !u.waitingForCall)
                            TextButton(
                              onPressed: u.postpone,
                              child: const Text('Not now'),
                            ),
                          FilledButton(
                            onPressed: () => u.install(),
                            child: Text(
                              u.error == null ? 'Update now' : 'Try again',
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
            if (_confirmationNotice != null)
              Material(
                color: Theme.of(
                  context,
                ).colorScheme.secondaryContainer.withValues(alpha: 0.5),
                child: SizedBox(
                  width: double.infinity,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    child: Text(
                      _confirmationNotice!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
              ),
            if (_timeClockNotice != null)
              Material(
                color: Theme.of(
                  context,
                ).colorScheme.errorContainer.withValues(alpha: 0.4),
                child: SizedBox(
                  width: double.infinity,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    child: Text(
                      _timeClockNotice!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
              ),
            Expanded(
              child: Row(
                children: [
                  if (wide)
                    // Two shapes, and the default is the list.
                    //
                    // A list reads faster: the eye scans labels down a column, and the
                    // icons are a secondary cue rather than the thing being read.
                    // Blocks fit more in less width and some people prefer them, so
                    // they stay available — as the user's own preference, not an
                    // opinion imposed by whoever wrote this.
                    // TAB STAYS IN THE SECTION IT STARTED IN.
                    //
                    // Jose, 2026-09-17, typing in a form: *"i press tab it
                    // should go to the field below, but instead it's going to
                    // the sidebar, then the item below on the sidebar, then
                    // the left bar where the websites are, then back to the
                    // fields"*.
                    //
                    // Nothing broke and nothing changed. With no groups,
                    // Flutter's reading-order policy sorts every focusable in
                    // the tree by GEOMETRY, and a sidebar row sits BESIDE a
                    // form field rather than before or after it -- so the two
                    // columns interleave, exactly as he describes. Grouping
                    // the regions is what makes "the fields, then the
                    // sidebar" something the policy can express at all.
                    //
                    // Here rather than in the two panes he happened to be in:
                    // there was exactly ONE FocusTraversalGroup in the whole
                    // client before this (structure_canvas.dart), so every
                    // screen has the same gap and a per-pane fix mends the two
                    // he named while leaving the next one he opens broken.
                    //
                    // It orders, it does not trap: Tab still reaches the
                    // sidebar, after the page rather than through it.
                    FocusTraversalGroup(
                      // Keyed so a test can say which region a stop is IN,
                      // rather than which one it is not. A type finder does
                      // not fail when its subject is replaced -- it silently
                      // re-aims at whatever now answers to that type -- and
                      // "not the sidebar" quietly counts a top-bar stop as a
                      // page stop, which is the measurement changing shape
                      // under an assertion that still reads as true.
                      key: shellSidebarGroup,
                      child: _maybeThemed(
                        // In a mixed mode the sidebar runs on the opposite theme to the
                        // page. Wrapping only the sidebar — rather than building two
                        // MaterialApps or threading colours by hand — means every widget
                        // inside it, including NavigationRail's own indicator and hover
                        // colours, resolves against the right scheme without knowing that
                        // anything unusual is happening.
                        //
                        // The top bar takes this same override, so the chrome is one
                        // theme and the page is the other.
                        EzBiziTheme.navigationOverride(me.theme),
                        builder: (context) => NavigationSidebar(
                          api: widget.api,
                          expanded: expanded,
                          width: me.navigationWidth,
                          destinations: destinations,
                          divideAfter: divideAfter,
                          headingAt: headingAt,
                          pinned: pinned,
                          staffOnly: staffOnly,
                          selectedIndex: railIndex,
                          pinnedIndex: pinnedIndex,
                          staffIndex: staffIndex,
                          onSelected: (i) =>
                              _show(_Rail.modules, destinations[i].id),
                          onPinnedSelected: (i) =>
                              _show(_Rail.pinned, pinned[i].id),
                          onStaffSelected: (i) =>
                              _show(_Rail.staff, staffOnly[i].id),
                          // Written through the same path as every other preference, once
                          // the drag settles.
                          onResized: (w) => widget.session.setPreference(
                            'navigation.width',
                            w.round(),
                          ),
                          onArranged: (order) => widget.session.setPreference(
                            'navigation.order',
                            order,
                          ),
                        ),
                      ),
                    ),
                  Expanded(
                    // Keyed by the current view, so changing it REMOUNTS the screen and
                    // its initState fetches again.
                    //
                    // Every screen loads once in initState — the ordinary Flutter
                    // shape — so without this the switcher changed the label and left
                    // Connecticut's jobs on the page while claiming to be in Texas.
                    // Making the scope part of the screen's identity is the fix that
                    // needs no screen to know the switcher exists.
                    // The page is the other group. Everything a screen
                    // puts up -- its fields, its list, its side panel --
                    // is traversed together before Tab leaves for the
                    // chrome around it.
                    child: FocusTraversalGroup(
                      key: shellPageGroup,
                      child: KeyedSubtree(
                        // And by what they may reach, so a grant made
                        // elsewhere remounts the page and its lists reload.
                        key: ValueKey(
                          '${me.scope.type}:${me.scope.id}:${me.accessVersion}',
                        ),
                        // Their own account is the last fallback, and the only honest
                        // one: it is the single screen that is theirs by definition and
                        // cannot answer 403. It is what somebody whose role reaches no
                        // module at all lands on, rather than an empty frame.
                        // UI queue 99: Settings, Suggestions, Development and
                        // Your account get the header every module has, with
                        // its walkthrough and how-to video.
                        child: pinnedIndex != null
                            ? _titled(pinned[pinnedIndex])
                            : staffIndex != null
                            ? _titled(staffOnly[staffIndex])
                            : (showingProfile || railIndex == null)
                            ? _titled(
                                Destination(
                                  'Your account',
                                  Icons.person_outline,
                                  ProfileScreen(
                                    api: widget.api,
                                    session: widget.session,
                                  ),
                                  id: 'account',
                                ),
                              )
                            : _titled(destinations[railIndex]),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      /*
       * Narrow layout: a drawer, not a bar along the bottom.
       *
       * It was a NavigationBar holding every destination flat, and that could
       * not work. NavigationBar divides its width evenly between destinations,
       * and this app has fifteen — on a 360px phone that is twenty-four pixels
       * each, which is the icon with nothing left over. Every label broke
       * mid-word: "Huma n Res ources", "Fleet Mana geme nt", "Auto matio ns".
       * It was visible in all six Play store screenshots.
       *
       * The old comment reasoned that hiding the tail would make Settings, and
       * Development for staff, unreachable on a phone — which is true, and is
       * the argument against a bar, not against showing everything. A drawer
       * shows everything, at full width, and scrolls when there is more.
       *
       * It also restores the grouping the bar had to throw away: the sidebar
       * separates modules from pinned from staff with rules, and a drawer can
       * draw exactly the same rules. Order stops having to carry meaning on its
       * own.
       *
       * No `leading` is set on this layout, so Scaffold supplies the button
       * that opens it.
       */
      // Mobile review 2026-11-13, P7 (preview §3 A): on a phone, four
      // modules one tap away at the bottom, and More opens the drawer.
      bottomNavigationBar: MediaQuery.sizeOf(context).width < 600
          ? _bottomBar(destinations)
          : null,
      drawer: wide
          ? null
          : _navigationDrawer(
              destinations,
              divideAfter,
              headingAt,
              pinned,
              staffOnly,
              (order) =>
                  widget.session.setPreference('navigation.order', order),
            ),
    );
    _quickSearch = () => showQuickSearch(
      context,
      api: widget.api,
      screens: [
        for (final d in destinations)
          (
            module: d.id,
            record: null,
            label: d.label,
            detail: 'Screen',
            icon: d.icon,
          ),
      ],
      onOpen: (module, record) {
        if (record != null && module == 'leads') {
          return _show(_Rail.modules, 'leads', openLead: record);
        }
        if (record != null) {
          DeepLinks.open(
            DeepLink(
              module: module,
              type: module.substring(0, module.length - 1),
              id: record,
            ),
          );
        }
        _open(module);
      },
    );
    return OpenDestination(
      open: _open,
      openLead: (lead) => _show(_Rail.modules, 'leads', openLead: lead),
      child: shell,
    );
  }

  /// Android's Back on a phone: the drawer if it is open, else the ← of the
  /// page drawn inside the screen (see pressInPageBack), and out of the app
  /// only when there is neither. It used to leave the app from inside any
  /// record, form or settings section. Zero-size: a PopScope answers for its
  /// route wherever in it it sits.
  Widget _phoneBack() => PopScope(
    canPop: false,
    onPopInvokedWithResult: (didPop, _) {
      if (didPop) return;
      final shell = _scaffoldKey.currentState;
      if (shell?.isDrawerOpen ?? false) {
        shell!.closeDrawer();
      } else if (!pressInPageBack(context)) {
        SystemNavigator.pop();
      }
    },
    child: const SizedBox.shrink(),
  );

  /// Every destination, grouped as the sidebar groups them.
  /// A module, under its own name.
  ///
  /// Drawn HERE rather than by each screen, and that is the rule rather than a
  /// convenience. Sixteen screens rendered the shared tab row and exactly one
  /// of them printed the module's name; the other fifteen left the page unable
  /// to say where you were. Asking sixteen screens to each remember a header is
  /// how you get fifteen that forget — so the shell draws it, and a module
  /// cannot be built without one.
  ///
  /// The title is the destination's label, which is the SERVER's word for the
  /// module. Never a literal in a screen: an organization may rename any of
  /// them (§8), and a hardcoded name would go stale while still looking like a
  /// perfectly good title.
  ///
  /// The subtitle is optional and looked up by module key. `maybe` rather than
  /// `t` because a module this build has never heard of — which is what a
  /// server newer than the app sends — must render with no subtitle rather
  /// than with the string `module.forecasting.subtitle` under its name.
  ///
  /// Only the module rail passes through here. Settings, Development and Your
  /// account are the other branches of that switch: they are not modules, they
  /// have no server label, and each already says what it is.
  /// Design 161 T1: one toolbar slot per module, filled by its screen.
  final Map<String, ValueNotifier<Widget?>> _toolbars = {};

  /// D3: one primary-action slot per module, header right ([ModuleAction]).
  final Map<String, ValueNotifier<Widget?>> _actions = {};

  Widget _titled(Destination destination) {
    final toolbar = _toolbars.putIfAbsent(
      destination.id,
      () => ValueNotifier<Widget?>(null),
    );
    final action = _actions.putIfAbsent(
      destination.id,
      () => ValueNotifier<Widget?>(null),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // UI queue 67: a phone's browser hears about the app on every page;
        // a computer, once, on the Dashboard. Both draw nothing otherwise.
        PhoneAppBanner(session: widget.session),
        ListenableBuilder(
          listenable: Listenable.merge([toolbar, action]),
          builder: (context, _) => ModulePageHeader(
            actions: [?action.value],
            title: destination.label,
            subtitle: UiStrings.maybe('module.${destination.id}.subtitle'),
            // UI queue 81: the walkthrough and the how-to video, as A1 Tools
            // had them, each only where the tab has one.
            beside: TabHelpButtons(
              module: destination.id,
              label: destination.label,
              api: widget.api,
              session: widget.session,
            ),
            toolbar: toolbar.value,
          ),
        ),
        TourOffer(
          module: destination.id,
          label: destination.label,
          session: widget.session,
        ),
        if (destination.id == 'dashboard')
          PhoneAppCard(session: widget.session),
        Expanded(
          child: ModuleToolbarScope(
            slot: toolbar,
            action: action,
            child: destination.screen,
          ),
        ),
      ],
    );
  }

  /// The modules a field team uses all day, in order, when this person has
  /// them; the rest of the four come from the top of their own sidebar.
  static const _barFirst = ['jobs', 'scheduling', 'leads', 'phone'];

  Widget _bottomBar(List<Destination> destinations) {
    final byId = {for (final d in destinations) d.id: d};
    final slots = <Destination>[for (final id in _barFirst) ?byId[id]];
    for (final d in destinations) {
      if (slots.length >= 4) break;
      if (!slots.contains(d)) slots.add(d);
    }
    final at = _rail == _Rail.modules
        ? slots.indexWhere((d) => d.id == _selected)
        : -1;
    // One line under each icon at any width and text size (Jose,
    // 2026-11-16: "Communicati / on" on iOS). NavigationBar cannot hold a
    // label to one line, so the bar is drawn here: a short word, scaled
    // down to fit its slot rather than wrapped.
    final scheme = Theme.of(context).colorScheme;
    Widget slot(int i, IconData icon, String label, String tip, Key? key) {
      final on = i == at;
      final ink = on ? scheme.onSecondaryContainer : scheme.onSurfaceVariant;
      return Expanded(
        child: Tooltip(
          message: tip,
          child: InkWell(
            key: key,
            onTap: () => i == slots.length
                ? _scaffoldKey.currentState?.openDrawer()
                : _show(_Rail.modules, slots[i].id),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 56,
                  height: 30,
                  decoration: BoxDecoration(
                    color: on ? scheme.secondaryContainer : null,
                    borderRadius: BorderRadius.circular(15),
                  ),
                  child: Icon(icon, color: ink, size: 22),
                ),
                const SizedBox(height: 4),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 2),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      label,
                      maxLines: 1,
                      softWrap: false,
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: ink,
                        fontWeight: on ? FontWeight.w700 : FontWeight.w500,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Material(
      key: const Key('phone-bottom-bar'),
      color: scheme.surfaceContainer,
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 64,
          child: Row(
            children: [
              for (final (i, d) in slots.indexed)
                slot(i, d.icon, barLabel(d.id, d.label), d.label, null),
              slot(
                slots.length,
                Icons.menu,
                'More',
                'More',
                const Key('phone-bottom-more'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _navigationDrawer(
    List<Destination> destinations,
    Set<int> divideAfter,
    Map<int, String> headingAt,
    List<Destination> pinned,
    List<Destination> staffOnly,
    ValueChanged<List<String>> onArranged,
  ) {
    return Drawer(
      // Around the whole drawer, not just its header: from Android 15 an app
      // targeting SDK 35+ draws edge to edge, and with the inset on the header
      // alone the rows scrolled up under the clock once the header had gone.
      child: SafeArea(
        bottom: false,
        child: Builder(
          builder: (context) {
            Widget tile(Destination d, _Rail rail) => ListTile(
              leading: Icon(d.icon),
              title: Text(d.label),
              selected: _rail == rail && _selected == d.id,
              onTap: () {
                Navigator.of(context).pop();
                _show(rail, d.id);
              },
            );

            final header = Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 8, 8),
              child: EzBiziTheme.wordmark(context, size: 20),
            );

            // The phone has the easy half of this problem: its sidebar is
            // already a list of rows, so arranging it is the same list with
            // handles on. Nothing here is rebuilt to make dragging possible.
            if (_arrangingDrawer) {
              return Column(
                children: [
                  header,
                  const Divider(height: 1),
                  Expanded(
                    child: _SidebarArranger(
                      destinations: destinations,
                      divideAfter: divideAfter,
                      onDone: (order) {
                        setState(() => _arrangingDrawer = false);
                        if (order != null) onArranged(order);
                      },
                    ),
                  ),
                ],
              );
            }

            return ListView(
              padding: EdgeInsets.zero,
              children: [
                header,
                const Divider(height: 1),
                for (var i = 0; i < destinations.length; i++) ...[
                  if (headingAt.containsKey(i)) _GroupHeading(headingAt[i]!),
                  tile(destinations[i], _Rail.modules),
                  // Their own rule, nameless, exactly where they put it. The
                  // last position can never be in the set, so this cannot draw
                  // a second line immediately above the pinned group's one.
                  if (divideAfter.contains(i)) const Divider(),
                ],
                if (pinned.isNotEmpty) const Divider(),
                for (final d in pinned) tile(d, _Rail.pinned),
                if (staffOnly.isNotEmpty) const Divider(),
                for (final d in staffOnly) tile(d, _Rail.staff),

                // The foot, and the same two things the sidebar's foot carries
                // (Jose, 2026-09-14: mobile layout 1, and "move it next to the
                // version number").
                //
                // A phone had the version NOWHERE: `BuildStamp` was used at the
                // foot of the sidebar and on the sign-in screen, and a phone has
                // no sidebar and is signed in. Its own docblock called those
                // "the two places somebody already is when they wonder whether
                // the app has updated" — true when written, false the day this
                // drawer shipped.
                const Divider(),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 8, 0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      TextButton.icon(
                        onPressed: () =>
                            setState(() => _arrangingDrawer = true),
                        icon: const Icon(Icons.swap_vert, size: 16),
                        label: Text(UiStrings.t('shell.rearrange')),
                        style: TextButton.styleFrom(
                          visualDensity: VisualDensity.compact,
                          padding: const EdgeInsets.symmetric(horizontal: 6),
                          textStyle: Theme.of(context).textTheme.labelMedium,
                        ),
                      ),
                      BuildStamp(api: widget.api),
                    ],
                  ),
                ),
                SizedBox(height: MediaQuery.paddingOf(context).bottom + 8),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// The punch control in the top bar.
///
/// ── Why only one of the two states breathes ──────────────────────
///
/// A pulse is a statement that something is happening NOW. While a shift is
/// open that is true, and it is the one thing this bar can usefully keep
/// saying: the clock is running, on every screen, until somebody stops it.
///
/// On "Clock In" there is nothing happening to report, and the same animation
/// would be a control tugging at the corner of every page all day. That half
/// is deliberately still — which is also what makes the moving half mean
/// something. Two states that both pulse are one state.
///
/// The test worth applying to anything perpetual added to this bar: it has to
/// END, and by being acted on rather than by timing out.
/// The rule under the top bar, and on a phone the states that would otherwise
/// crowd the actions beside it.
///
/// Two of the bar's items are CONDITIONAL and neither is a control you press to
/// do the day's work: "Set up two-factor" and "Read only" are things that are
/// TRUE. On a desktop there is room for them among the buttons. On a phone
/// there is not -- with both showing, the actions row overflows -- and a strip
/// under the bar is where this file already says such a thing belongs.
///
/// Draws the bare 1px rule when neither applies, which is every healthy
/// account, so the common case costs exactly what it did before.
class _BarStates extends StatelessWidget implements PreferredSizeWidget {
  const _BarStates({
    required this.twoFactor,
    required this.readOnly,
    required this.emailConfirmation,
    required this.onTwoFactor,
    required this.onEmailConfirmation,
    this.scope,
  });

  /// The company picker's row on a phone, or null.
  final Widget? scope;

  final bool twoFactor;
  final bool readOnly;
  final bool emailConfirmation;
  final VoidCallback onTwoFactor;
  final VoidCallback onEmailConfirmation;

  bool get _any => twoFactor || readOnly || emailConfirmation;

  @override
  Size get preferredSize =>
      Size.fromHeight((_any ? 49 : 1) + (scope == null ? 0 : 44));

  @override
  Widget build(BuildContext context) {
    final rule = Container(height: 1, color: EzBiziTheme.edge(context));
    final row = scope == null
        ? null
        : SizedBox(
            key: const Key('top-bar-scope-row'),
            height: 44,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 2, 12, 6),
              child: scope,
            ),
          );
    if (!_any) {
      return row == null
          ? rule
          : Column(mainAxisSize: MainAxisSize.min, children: [row, rule]);
    }

    final scheme = Theme.of(context).colorScheme;
    final tint = scheme.errorContainer.withValues(alpha: 0.4);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        ?row,
        SizedBox(
          height: 48,
          // Scrolls rather than wraps: two chips fit at 375, and a third state
          // added later would otherwise silently push the strip to two rows and
          // move every screen down.
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            children: [
              if (twoFactor)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ActionChip(
                    avatar: const Icon(Icons.shield_outlined, size: 16),
                    label: const Text('Set up two-factor'),
                    backgroundColor: tint,
                    onPressed: onTwoFactor,
                  ),
                ),
              if (emailConfirmation)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ActionChip(
                    avatar: const Icon(
                      Icons.mark_email_unread_outlined,
                      size: 16,
                    ),
                    label: const Text('Confirm your email'),
                    backgroundColor: tint,
                    onPressed: onEmailConfirmation,
                  ),
                ),
              if (readOnly)
                Chip(
                  avatar: const Icon(Icons.lock_outline, size: 16),
                  label: const Text('Read only'),
                  backgroundColor: tint,
                ),
            ],
          ),
        ),
        rule,
      ],
    );
  }
}

class _ClockButton extends StatefulWidget {
  const _ClockButton({
    required this.open,
    required this.busy,
    required this.onPressed,
    this.compact = false,
  });

  /// Icon only, no label, for the phone bar (Jose, 2026-09-10: "make the clock
  /// out as an icon instead only without the words").
  ///
  /// The two states stay legible without the word because the ICON differs by
  /// shape, not only by colour -- `logout` against `login` -- so this does not
  /// rest on telling coral from green.
  final bool compact;

  /// A shift is open — the button says Clock Out, and the chip breathes.
  final bool open;

  /// A punch is in flight. The control goes dead rather than disappearing: a
  /// second tap posts the same punch twice.
  final bool busy;

  final VoidCallback onPressed;

  @override
  State<_ClockButton> createState() => _ClockButtonState();
}

class _ClockButtonState extends State<_ClockButton>
    with SingleTickerProviderStateMixin {
  /// Slow on purpose. At a heartbeat's pace this is a control demanding to be
  /// dealt with; at a breath's it is the bar holding a fact.
  late final _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sync();
  }

  @override
  void didUpdateWidget(_ClockButton old) {
    super.didUpdateWidget(old);
    _sync();
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  /// Running only while there is a shift to report, and never for somebody who
  /// has asked their device for less motion — this animation repeats for as
  /// long as the app is open, which is precisely what that setting is about.
  /// Same call as the structure canvas makes before it animates a layout.
  ///
  /// In `didChangeDependencies` as well as `didUpdateWidget` because the
  /// MediaQuery is a dependency: the answer can change without this widget's
  /// own arguments changing.
  void _sync() {
    final wanted = widget.open && !MediaQuery.disableAnimationsOf(context);

    if (wanted == _pulse.isAnimating) return;

    if (wanted) {
      _pulse.repeat(reverse: true);
    } else {
      // reset rather than stop: stopping leaves the chip at whatever alpha the
      // last frame happened to land on, so clocking out could finish on a
      // half-lit button.
      _pulse.reset();
    }
  }

  @override
  Widget build(BuildContext context) {
    final label = UiStrings.t(
      widget.open ? 'time_clock.clock_out' : 'time_clock.clock_in',
    );
    final color = widget.open
        ? EzBiziTheme.clockOut(context)
        : EzBiziTheme.clockIn(context);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Tooltip(
        message: label,
        child: AnimatedBuilder(
          animation: _pulse,
          // Handed through rather than rebuilt: only the fill and the halo
          // change per frame, so a 60fps breath does not rebuild an Icon and a
          // Text sixty times a second.
          child: OutlinedButton.icon(
            onPressed: widget.busy ? null : widget.onPressed,
            icon: Icon(widget.open ? Icons.logout : Icons.login, size: 18),
            // Compact keeps the Tooltip above and drops only the word. An
            // IconButton would have been the obvious swap and would have lost
            // the outline and the breathing halo, which are what make a live
            // shift visible at a glance.
            label: widget.compact ? const SizedBox.shrink() : Text(label),
            style: OutlinedButton.styleFrom(
              foregroundColor: color,
              disabledForegroundColor: color.withValues(alpha: 0.55),
              side: BorderSide(color: color),
              // Transparent so the animated fill behind it shows through
              // instead of the two stacking into a flat block.
              backgroundColor: Colors.transparent,
              // Tighter without a word: `OutlinedButton.icon` still lays out a
              // gap for the label, so 12 either side of an empty one reads as a
              // mis-centred icon.
              padding: EdgeInsets.symmetric(
                horizontal: widget.compact ? 6 : 12,
              ),
              minimumSize: const Size(0, 32),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              visualDensity: VisualDensity.compact,
              // The shape every other button has (theme.dart); it was the one
              // pill left once the rest stopped being pills.
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
            ),
          ),
          builder: (context, child) {
            // Eased, not linear: a breath swells and eases off, and a straight
            // ramp turning round at each end reads as a blink.
            final t = widget.open
                ? Curves.easeInOut.transform(_pulse.value)
                : 0.0;

            return DecoratedBox(
              key: const Key('clock-pulse'),
              decoration: BoxDecoration(
                // The button's own corner, so the glow follows its outline.
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
                // Never falls to nothing while a shift is open, so the chip
                // still reads as live in a still frame — a screenshot, or
                // somebody running with animations switched off.
                color: color.withValues(
                  alpha: widget.open ? 0.08 + 0.14 * t : 0.0,
                ),
                boxShadow: [
                  BoxShadow(
                    color: color.withValues(alpha: 0.28 * t),
                    blurRadius: 12 * t,
                    spreadRadius: 1.5 * t,
                  ),
                ],
              ),
              child: child,
            );
          },
        ),
      ),
    );
  }
}

/// Does this module get a row of its own in the sidebar?
///
/// Three answers, and the third is the one worth writing down.
///
///   - A module drawn INSIDE another does not. It is a chip in that module's
///     strip, and a row here as well would be the same screen in two places
///     disagreeing about which one you are on.
///   - An ordinary module does, when this person can reach it. Unchanged.
///   - A module this person CANNOT reach still does, when something drawn
///     inside it is reachable. Marketing's permission and Leads' are
///     independent — nesting is a layout fact, not a grant — so a role holding
///     `leads.view` and not `marketing.view` would otherwise have lost Leads
///     the moment it moved: the permission untouched, the screen simply gone.
///     They land on Marketing holding one chip, which is the honest answer.
bool _hasDestination(Me me, ModuleAccess module) {
  if (module.parent != null) return false;
  if (module.reachable) return true;

  return me.reachableModules.any((m) => m.parent == module.key);
}

/// Wrap in a different theme, or do not wrap at all.
///
/// Null means the two halves of the shell match, and an identical Theme pushed
/// down the tree would be a rebuild that changes nothing.
///
/// ── Why a builder and not a child ────────────────────────────────
///
/// It took a `child`, and that quietly did not work for anything that read the
/// theme while being CONSTRUCTED rather than while being laid out. `AppBar(...)`
/// and all of its arguments are built in the enclosing method's context — which
/// is above this Theme — so `EzBiziTheme.wordmark(context)` and
/// `Theme.of(context).dividerColor` in the title row resolved against the PAGE's
/// theme, not the chrome's.
///
/// In a mixed mode that is the opposite theme. The wordmark's second half is
/// drawn in the on-surface colour, so on a light bar it came out white: "Ez" and
/// then nothing. The divider beside it was wrong in the same direction and just
/// looked faint.
///
/// A builder makes the override reach everything by construction, so no widget
/// placed in that bar in future has to know any of this.
Widget _maybeThemed(ThemeData? theme, {required WidgetBuilder builder}) =>
    theme == null
    ? Builder(builder: builder)
    : Theme(
        data: theme,
        child: Builder(builder: builder),
      );

/// The sidebar: the modules, a rule, then the pinned group.
///
/// A widget rather than inline in HomeShell's build so it can be laid out in a
/// test at a real size. Its subtle failure — a Divider with no width to inherit
/// — depends entirely on the constraints it is given, which is not something
/// reading the code reliably catches.
class NavigationSidebar extends StatefulWidget {
  const NavigationSidebar({
    this.api,
    super.key,
    required this.expanded,
    required this.width,
    required this.destinations,
    required this.pinned,
    required this.staffOnly,
    required this.selectedIndex,
    required this.pinnedIndex,
    required this.staffIndex,
    required this.onSelected,
    required this.onPinnedSelected,
    required this.onStaffSelected,
    this.onResized,
    this.onArranged,
    this.divideAfter = const {},
    this.headingAt = const {},
  });

  /// Group heading drawn above a position in [destinations].
  final Map<int, String> headingAt;

  /// For the version stamp's release bar; null where no client is built.
  final ApiClient? api;

  final bool expanded;

  /// The stored width, honoured only while expanded — collapsed, the rail is
  /// its icon column and nothing else, at any setting.
  final double width;

  final List<Destination> destinations;

  /// Positions in [destinations] with a rule under them. Empty for everybody
  /// who has never rearranged anything, which is the state this sidebar was
  /// built in and still renders identically.
  final Set<int> divideAfter;

  final List<Destination> pinned;

  /// Development, when this person is also EzBizi staff (§4.2). Its own group
  /// because it is a different kind of thing, not a third setting.
  final List<Destination> staffOnly;

  /// Exactly one of these is non-null: the selection lives in one rail or the
  /// other, never both.
  final int? selectedIndex;
  final int? pinnedIndex;
  final int? staffIndex;

  final ValueChanged<int> onSelected;
  final ValueChanged<int> onPinnedSelected;
  final ValueChanged<int> onStaffSelected;

  /// Called once, on release. Not on every drag frame — that would be a request
  /// per pixel of travel.
  final ValueChanged<double>? onResized;

  /// The new order, on Done. Same reasoning as [onResized]: one write when the
  /// arrangement is finished, not one per row moved.
  final ValueChanged<List<String>>? onArranged;

  static const double collapsedWidth = 80;
  static const double minWidth = 180;
  static const double maxWidth = 420;

  @override
  State<NavigationSidebar> createState() => _NavigationSidebarState();
}

class _NavigationSidebarState extends State<NavigationSidebar> {
  /// The width during a drag, before it is committed. Null when not dragging.
  double? _dragWidth;

  /// Whether the rail has widened into a list of rows you can drag.
  ///
  /// Local rather than a preference: it is a mode you are in for thirty
  /// seconds, not a way you like your sidebar. Leaving the screen leaves it.
  bool _arranging = false;

  final TextEditingController _search = TextEditingController();
  final ScrollController _railScroll = ScrollController();

  /// What is typed in the module filter. Empty means no filter at all.
  ///
  /// Ignored while collapsed rather than cleared. At 80px the field is not on
  /// screen, and a list filtered by something you cannot see is a list that has
  /// silently lost modules. Re-expanding brings back the text and the filter it
  /// explains, together.
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    _railScroll.dispose();
    super.dispose();
  }

  /// What the sidebar says when the filter matched nothing.
  ///
  /// Scrollable because these two paragraphs are taller than the space left on
  /// the short window this whole feature is for.
  Widget _noMatch(BuildContext context, String query) {
    final theme = Theme.of(context);
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            UiStrings.t('shell.no_module').replaceFirst('{q}', query),
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            UiStrings.t(
              'shell.no_module_hint',
            ).replaceFirst('{total}', '${widget.destinations.length}'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    // Arranging forces the rail open and gives it room for a handle beside the
    // longest label. Dragging rows in an 80px icon column is dragging things
    // you cannot tell apart.
    final arranging = _arranging && widget.onArranged != null;
    final expanded = widget.expanded || arranging;
    final width = expanded
        ? math.max(_dragWidth ?? widget.width, arranging ? 260.0 : 0.0)
        : NavigationSidebar.collapsedWidth;

    // No filter while arranging. A list you are reordering has to be the whole
    // list: dropping a row between two that happen to be adjacent on screen
    // and not adjacent in the order would put it somewhere nobody chose.
    final query = expanded && !arranging ? _query.trim().toLowerCase() : '';
    final filtering = query.isNotEmpty;

    // Filtering a rail means filtering its INDICES too, and this list is the
    // whole reason the feature is safe.
    //
    // NavigationRail addresses destinations by position: it hands back the
    // index of the row that was tapped, and it asserts that the selected index
    // is inside the list it was given. Pass a shortened list without this map
    // and every row below the first removed one opens a different module --
    // quietly, with no error, because index 2 is still a perfectly valid index.
    // `shown` holds the ORIGINAL index of each visible row, so the rail's
    // positions can be translated back on the way in and on the way out.
    final shown = <int>[
      for (var i = 0; i < widget.destinations.length; i++)
        if (!filtering ||
            widget.destinations[i].label.toLowerCase().contains(query))
          i,
    ];

    // The module rail, cut into groups at this person's dividers.
    //
    // A NavigationRail cannot put a rule between its own items, so a divider is
    // a break BETWEEN rails -- the same shape the pinned and staff groups have
    // always had, for the same reason. Empty groups are dropped rather than
    // drawn, which is what enforces both rules a rule has to obey: it needs
    // something on each side, and two of them cannot touch. The filter is why
    // that is not merely tidiness -- it can empty a group that was perfectly
    // good when it was saved.
    final segments = <List<int>>[[]];
    for (final i in shown) {
      // A heading starts a new rail too (filtering hides headings).
      if (!filtering &&
          widget.headingAt.containsKey(i) &&
          segments.last.isNotEmpty) {
        segments.add([]);
      }
      segments.last.add(i);
      if (widget.divideAfter.contains(i)) segments.add([]);
    }
    segments.removeWhere((group) => group.isEmpty);

    // The ORIGINAL index of the selected row, or null when the filter hid it.
    // Not zero -- zero would paint the highlight on whatever happens to be
    // first.
    final railSelected = shown.contains(widget.selectedIndex)
        ? widget.selectedIndex
        : null;

    /// Where the selection sits inside one group, or null when it is in
    /// another. Exactly one group may claim it, which is what NavigationRail's
    /// own assert is about.
    int? within(List<int> group) {
      if (railSelected == null) return null;
      final at = group.indexOf(railSelected);

      return at < 0 ? null : at;
    }

    final sidebar = SizedBox(
      // Explicit, not intrinsic. A Row gives its non-flex children
      // `maxWidth: infinity`, and the Divider below has no width of its own —
      // under an unbounded width its line collapses to zero and it draws
      // nothing at all while still occupying its vertical space. A real width
      // is also what makes the rule's inset a proportion rather than a guess.
      width: width,
      child: DecoratedBox(
        decoration: BoxDecoration(
          // Tinted and edged. The rail and the top bar were both plain surface,
          // so on a light theme they met as one continuous field and the
          // sidebar read as part of the header.
          color: scheme.surfaceContainerLow,
          border: Border(right: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        // TWO rails, not one rail with a hand-drawn footer.
        //
        // Settings and Development used to be a Material + InkWell built to
        // look like destinations. It never quite did: the hover lit the whole
        // row instead of the indicator pill, the icons sat 20px left of the
        // column above them, there was no selection animation, and every change
        // to the rail's width moved them again. Four defects, one cause — they
        // were not destinations.
        //
        // NavigationRail has no minimum destination count, so the pinned group
        // is simply another rail. Hover, splash, indicator shape, selection
        // animation, icon column, label style and row height are identical
        // because they are the same widget, not because constants were copied
        // out of its source.
        child: Column(
          children: [
            // The filter, and only while there are labels to filter.
            //
            // Collapsed there is no search at all, not even an icon. The
            // collapsed rail is for somebody who already knows where things are
            // and wants their screen back; search is for FINDING, which implies
            // wanting labels. An icon at 80px would be a control that only
            // makes sense by first undoing the state you deliberately chose.
            if (expanded && !arranging)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
                child: TextField(
                  controller: _search,
                  onChanged: (value) => setState(() => _query = value),
                  textInputAction: TextInputAction.search,
                  style: Theme.of(context).textTheme.bodyMedium,
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: UiStrings.t('shell.find_module'),
                    prefixIcon: const Icon(Icons.search, size: 18),
                    prefixIconConstraints: const BoxConstraints(
                      minWidth: 34,
                      minHeight: 34,
                    ),
                    suffixIcon: filtering
                        ? IconButton(
                            icon: const Icon(Icons.close, size: 16),
                            visualDensity: VisualDensity.compact,
                            tooltip: UiStrings.t('shell.clear_filter'),
                            onPressed: () => setState(() {
                              _search.clear();
                              _query = '';
                            }),
                          )
                        : null,
                    suffixIconConstraints: const BoxConstraints(
                      minWidth: 34,
                      minHeight: 34,
                    ),
                  ),
                ),
              ),

            // How many of how many.
            //
            // With a filter on, "three modules match" and "three modules exist"
            // look exactly alike, and one of them means the app has lost
            // thirteen tabs. The total is what tells them apart.
            if (filtering && shown.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Text(
                  UiStrings.t('shell.modules_shown')
                      .replaceFirst('{n}', '${shown.length}')
                      .replaceFirst('{total}', '${widget.destinations.length}'),
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),

            // The destinations scroll; the group below does NOT.
            //
            // A NavigationRail lays its destinations out in a Column and has no
            // scrolling of its own. Inside a bare Expanded it therefore
            // OVERFLOWED on a short window: past about 780px of sidebar the
            // sixteen destinations drew straight over Settings and Development,
            // and the ones underneath were unreachable rather than merely
            // hidden — there was nothing to scroll with. Reported from a 900px
            // screen, where Services sat on top of Settings.
            //
            // The rail still has to FILL the column when there is room, or it
            // stops stretching and the selected-row highlight ends early. So it
            // is a scroll view with a minimum height of whatever it was given:
            // at full height nothing scrolls and this is identical to before;
            // below that the list scrolls and the tail stays put.
            //
            // The tail is deliberately outside this. Going to Settings is often
            // what somebody does BECAUSE they could not find a module in the
            // list, so it is the last thing that should require scrolling to
            // reach.
            Expanded(
              // Keyed: the search field above it comes and goes with the
              // collapse, and an unkeyed sibling shift remounted this whole
              // list (and its scroll view) on every toggle.
              key: const ValueKey('sidebar-list'),
              // Nothing matched is the state that matters more than the happy
              // path: a sidebar with no modules in it and no explanation is
              // indistinguishable from a broken one. So it names what was
              // typed, says the list is filtered rather than empty, and points
              // at the way back.
              child: arranging
                  ? _SidebarArranger(
                      destinations: widget.destinations,
                      divideAfter: widget.divideAfter,
                      onDone: (order) {
                        setState(() => _arranging = false);
                        if (order != null) widget.onArranged!(order);
                      },
                    )
                  : filtering && shown.isEmpty
                  ? _noMatch(context, _query.trim())
                  : Column(
                      children: [
                        Expanded(
                          child: LayoutBuilder(
                            // A visible thumb when the list is taller than the
                            // window: without it the lower group just stopped
                            // mid-row with no hint there was more (deep dive
                            // 2026-11-02).
                            builder: (context, constraints) => Stack(
                              children: [
                                Scrollbar(
                                  controller: _railScroll,
                                  thumbVisibility: true,
                                  child: SingleChildScrollView(
                                    controller: _railScroll,
                                    child: ConstrainedBox(
                                      constraints: BoxConstraints(
                                        minHeight: constraints.maxHeight,
                                      ),
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.stretch,
                                        children: [
                                          for (
                                            var g = 0;
                                            g < segments.length;
                                            g++
                                          ) ...[
                                            if (!filtering &&
                                                expanded &&
                                                widget.headingAt.containsKey(
                                                  segments[g].first,
                                                ))
                                              _GroupHeading(
                                                widget.headingAt[segments[g]
                                                    .first]!,
                                              )
                                            else if (g > 0)
                                              Divider(
                                                height: 17,
                                                thickness: 1,
                                                indent: width * 0.15,
                                                endIndent: width * 0.15,
                                                color: scheme.outlineVariant,
                                              ),
                                            IntrinsicHeight(
                                              child: _rail(
                                                context,
                                                expanded: expanded,
                                                width: width,
                                                destinations: [
                                                  for (final i in segments[g])
                                                    widget.destinations[i],
                                                ],
                                                selectedIndex: within(
                                                  segments[g],
                                                ),
                                                onSelected: (i) => widget
                                                    .onSelected(segments[g][i]),
                                                query: query,
                                              ),
                                            ),
                                          ],
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                        // "More below" on its own row under the list while
                        // rows remain under the fold (Jose 11-13 read the
                        // cut-off list as modules MISSING). Drawn over the
                        // list it covered a module name (desktop review
                        // 2026-11-13, Top 6).
                        _MoreBelow(controller: _railScroll),
                      ],
                    ),
            ),

            // The rule that says the group below is a different kind of thing:
            // places you go to change how the work behaves, rather than places
            // you go to do it.
            //
            // Inset to about 70% of the column. Edge to edge it reads as the
            // bottom of the sidebar — a border, the same language as the right
            // edge beside it. Pulled in, it reads as a break WITHIN one list,
            // which is what it is.
            Divider(
              height: 17,
              thickness: 1,
              indent: width * 0.15,
              endIndent: width * 0.15,
              color: scheme.outlineVariant,
            ),

            // IntrinsicHeight because a rail's root column is mainAxisSize.max
            // — it expects to own the full height. This asks it for exactly the
            // height its destinations need.
            IntrinsicHeight(
              child: _rail(
                context,
                expanded: expanded,
                width: width,
                destinations: widget.pinned,
                selectedIndex: widget.pinnedIndex,
                onSelected: widget.onPinnedSelected,
              ),
            ),

            // And a third group, for staff only.
            //
            // Development sat in the group above, which said it was the same
            // kind of thing as Settings. Settings configures the business and
            // every administrator has it; this is the platform underneath the
            // product (§4.2), it appears only when the server says this person
            // is also EzBizi staff, and most people signed into this app will
            // never see it.
            //
            // The whole group disappears with its rule when they are not, so
            // nobody is left looking at a divider with nothing under it.
            if (widget.staffOnly.isNotEmpty) ...[
              Divider(
                height: 17,
                thickness: 1,
                indent: width * 0.15,
                endIndent: width * 0.15,
                color: scheme.outlineVariant,
              ),
              IntrinsicHeight(
                child: _rail(
                  context,
                  expanded: expanded,
                  width: width,
                  destinations: widget.staffOnly,
                  selectedIndex: widget.staffIndex,
                  onSelected: widget.onStaffSelected,
                ),
              ),
            ],

            // The foot: what build you are on, and the way into rearranging.
            //
            // Rearranging used to sit under the search field. Jose, 2026-09-14:
            // "i dont like the rearrange being on the top, perhaps move it next
            // to the version number instead." It belongs here for the same
            // reason Settings does — a place you go to change how the sidebar
            // behaves rather than a place you go to work — and the top of a
            // list of modules is the one place it competes with the modules.
            //
            // Collapsed shows the version alone. It used to show nothing, on
            // the reasoning that half a build id is worse than none; the
            // version is a whole field rather than half of one, and it fits.
            // See [BuildStamp.compact].
            Padding(
              padding: EdgeInsets.fromLTRB(expanded ? 20 : 8, 10, 12, 0),
              // Stacked, not side by side, and that was measured rather than
              // chosen: `BuildStamp` is 160px wide on a desktop platform --
              // the version plus the update control beside it -- and this
              // sidebar is draggable down to 180. A Row gives its non-flex
              // children unbounded width, so the two of them overflowed by
              // 86px at the default 220 rather than shrinking. Stacked they
              // fit at every width, and the control keeps its WORD, which an
              // icon squeezed in beside the version could not have.
              child: expanded
                  ? Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (!arranging && widget.onArranged != null)
                          TextButton.icon(
                            onPressed: () => setState(() => _arranging = true),
                            icon: const Icon(Icons.swap_vert, size: 16),
                            label: Text(UiStrings.t('shell.rearrange')),
                            style: TextButton.styleFrom(
                              visualDensity: VisualDensity.compact,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                              ),
                              foregroundColor: scheme.onSurfaceVariant,
                              textStyle: Theme.of(
                                context,
                              ).textTheme.labelMedium,
                            ),
                          ),
                        BuildStamp(api: widget.api),
                      ],
                    )
                  : Center(child: BuildStamp(compact: true, api: widget.api)),
            ),
            const SizedBox(height: 12),
          ],
        ),
      ),
    );

    // Collapsed has one right width and dragging it would be a fight with the
    // toggle. The handle only exists when there is a width to change.
    //
    // The Stack itself is ALWAYS there. Returning the bare sidebar when
    // collapsed changed the root widget type, so every toggle threw the whole
    // subtree away and mounted a new one: the old and new list both held the
    // rail's scroll controller for a frame, `_MoreBelow` read
    // `controller.position` (`positions.single`) and threw "Too many
    // elements", and the column painted grey until the next click rebuilt it
    // (Jose 11-16, 0.0.0575; client_issues 01a1e4ea).
    final resizable = expanded && widget.onResized != null;
    return Stack(
      children: [
        sidebar,
        if (resizable)
          Positioned(
            top: 0,
            bottom: 0,
            // Straddles the border rather than sitting beside it, so the cursor
            // changes where the eye says the edge is.
            right: -3,
            width: 7,
            child: MouseRegion(
              cursor: SystemMouseCursors.resizeLeftRight,
              child: GestureDetector(
                behavior: HitTestBehavior.translucent,
                onHorizontalDragStart: (_) =>
                    setState(() => _dragWidth = width),
                onHorizontalDragUpdate: (d) => setState(() {
                  _dragWidth = ((_dragWidth ?? width) + d.delta.dx).clamp(
                    NavigationSidebar.minWidth,
                    NavigationSidebar.maxWidth,
                  );
                }),
                onHorizontalDragEnd: (_) {
                  final settled = _dragWidth;
                  setState(() => _dragWidth = null);
                  if (settled != null) widget.onResized!(settled);
                },
                // Double-click puts it back, because a resize handle without one
                // means somebody who has dragged it somewhere odd has to find
                // their way back by feel.
                child: GestureDetector(
                  onDoubleTap: () => widget.onResized!(220),
                  behavior: HitTestBehavior.translucent,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// The sidebar as a list you can drag, on both the rail and the phone drawer.
///
/// One widget for both because the mechanism is the whole feature: the words
/// on the buttons, what a divider looks like, whether a drop lands above or
/// below, and what is saved all have to be the same thing in both places, and
/// the only way to be sure of that is for it to BE the same thing.
///
/// It never touches [NavigationRail]. The rail is what the sidebar is when you
/// are using it; this is what it becomes while you are arranging it.
class _SidebarArranger extends StatefulWidget {
  const _SidebarArranger({
    required this.destinations,
    required this.divideAfter,
    required this.onDone,
  });

  final List<Destination> destinations;
  final Set<int> divideAfter;

  /// The order to save, or null when nothing moved — leaving without changing
  /// anything must not write a preference.
  final ValueChanged<List<String>?> onDone;

  @override
  State<_SidebarArranger> createState() => _SidebarArrangerState();
}

/// One row being dragged: a module, or a divider with an identity of its own.
///
/// Dividers are identical to look at and must NOT be identical to the reorder
/// animation — every child of a ReorderableListView needs a key that is unique
/// among its siblings, and two rows sharing one is a list that drops rows while
/// you drag.
class _ArrangeRow {
  _ArrangeRow(this.id, this.key);

  _ArrangeRow.divider() : id = sidebarDivider, key = UniqueKey();

  final String id;
  final Key key;
}

class _SidebarArrangerState extends State<_SidebarArranger> {
  late final List<String> _was;
  late final List<_ArrangeRow> _rows;

  @override
  void initState() {
    super.initState();
    _was = sidebarOrderOf(widget.destinations, widget.divideAfter, (d) => d.id);
    _rows = [
      for (final entry in _was)
        if (entry == sidebarDivider)
          _ArrangeRow.divider()
        else
          _ArrangeRow(entry, ValueKey(entry)),
    ];
  }

  List<String> get _order => [for (final row in _rows) row.id];

  void _done() {
    final order = _order;
    widget.onDone(listEquals(order, _was) ? null : order);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final labels = {for (final d in widget.destinations) d.id: d};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 8, 4),
          child: Row(
            children: [
              Expanded(
                child: TextButton.icon(
                  // After the first row, not at the end.
                  //
                  // A rule needs something on both sides to draw at all, so a
                  // new one appended to the bottom is one you add, see in the
                  // list, press Done on, and never see again. Here it is legal
                  // the moment it exists, and on screen without scrolling.
                  onPressed: () => setState(
                    () => _rows.insert(
                      math.min(1, _rows.length),
                      _ArrangeRow.divider(),
                    ),
                  ),
                  icon: const Icon(Icons.horizontal_rule, size: 16),
                  label: Text(UiStrings.t('shell.add_divider')),
                  style: TextButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    alignment: Alignment.centerLeft,
                    textStyle: theme.textTheme.labelMedium,
                  ),
                ),
              ),
              TextButton(
                onPressed: _done,
                child: Text(UiStrings.t('shell.done')),
              ),
            ],
          ),
        ),
        Expanded(
          child: ReorderableListView.builder(
            padding: const EdgeInsets.only(bottom: 12),
            itemCount: _rows.length,
            onReorder: (from, to) => setState(() {
              // The index a drop reports is a GAP, so moving a row downwards
              // counts the gap it vacated. Without this every downward drag
              // lands one row short of where it was dropped.
              if (to > from) to -= 1;
              _rows.insert(to, _rows.removeAt(from));
            }),
            itemBuilder: (context, i) {
              final row = _rows[i];
              if (row.id == sidebarDivider) {
                return _dividerRow(context, i, key: row.key);
              }
              final destination = labels[row.id];

              return ListTile(
                key: row.key,
                dense: true,
                leading: Icon(destination?.icon ?? Icons.circle_outlined),
                title: Text(
                  destination?.label ?? row.id,
                  overflow: TextOverflow.ellipsis,
                ),
                trailing: ReorderableDragStartListener(
                  index: i,
                  child: const Icon(Icons.drag_handle),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  /// A divider, as something you can pick up and throw away.
  ///
  /// It carries no name. Jose: "the dividers should not have names, just be
  /// dividers basically" — so there is nothing here to read, and the row is
  /// entirely its two controls and the rule between them.
  Widget _dividerRow(BuildContext context, int i, {required Key key}) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
      child: Row(
        children: [
          IconButton(
            icon: const Icon(Icons.close, size: 16),
            visualDensity: VisualDensity.compact,
            tooltip: UiStrings.t('shell.remove_divider'),
            onPressed: () => setState(() => _rows.removeAt(i)),
          ),
          Expanded(child: Divider(thickness: 1, color: scheme.outlineVariant)),
          ReorderableDragStartListener(
            index: i,
            child: const Padding(
              padding: EdgeInsets.all(8),
              child: Icon(Icons.drag_handle),
            ),
          ),
        ],
      ),
    );
  }
}

/// One rail, built one way.
///
/// Both the main group and the pinned group go through here, so there is no
/// route by which the two could end up with different widths, label types or
/// indicator behaviour. Getting that wrong is precisely what the hand-rolled
/// footer did.
Widget _rail(
  BuildContext context, {
  required bool expanded,
  required double width,
  required List<Destination> destinations,
  required int? selectedIndex,
  required ValueChanged<int> onSelected,
  Widget? leading,

  /// Lowercased, and empty when nothing is being filtered.
  String query = '',
}) {
  return NavigationRail(
    // A fresh rail per state, so it does not run its own extend animation.
    // The column around it already snapped to its new width, and the rail
    // animating its labels inside 80px overflowed every frame of a collapse.
    key: ValueKey(expanded),
    backgroundColor: Colors.transparent,
    // null when an off-rail screen is showing, or when the OTHER rail holds the
    // selection. The assert allows it; a stale index would throw.
    selectedIndex: selectedIndex,
    onDestinationSelected: onSelected,
    extended: expanded,
    labelType: expanded ? null : NavigationRailLabelType.none,
    minWidth: NavigationSidebar.collapsedWidth,
    // The rail fills the column it is given. minExtendedWidth is asserted to be
    // at least minWidth, so a drag below the icon column would otherwise throw
    // rather than simply stop.
    minExtendedWidth: math.max(width, NavigationSidebar.collapsedWidth),
    leading: leading,
    destinations: destinations
        .map(
          (d) => NavigationRailDestination(
            icon: Icon(d.icon),
            label: _railLabel(context, d.label, query),
          ),
        )
        .toList(),
  );
}

/// A destination's label, with the matched run marked while a filter is on.
///
/// Which letters matched is the whole answer to "why is Fleet Management in a
/// list I filtered by 'man'". Without it a short query looks like the filter is
/// returning nonsense, and the rows it kept look arbitrary.
Widget _railLabel(BuildContext context, String label, String query) {
  if (query.isEmpty) return Text(label);
  final at = label.toLowerCase().indexOf(query);
  if (at < 0) return Text(label);
  return Text.rich(
    TextSpan(
      children: [
        TextSpan(text: label.substring(0, at)),
        TextSpan(
          text: label.substring(at, at + query.length),
          style: TextStyle(
            color: Theme.of(context).colorScheme.primary,
            fontWeight: FontWeight.w700,
          ),
        ),
        TextSpan(text: label.substring(at + query.length)),
      ],
    ),
  );
}

class Destination {
  const Destination(this.label, this.icon, this.screen, {String? id})
    : id = id ?? label;

  /// What the shell remembers when this one is selected.
  ///
  /// For a module it is the module KEY, which is stable in a way the label is
  /// not: the label is the server's wording and an organization renaming things
  /// (§8) must not move somebody to a different screen. The fixed destinations
  /// have no key of their own and use their label, which is fixed with them.
  final String id;

  final String label;
  final IconData icon;
  final Widget screen;
}

/// A fade and a "More" chevron over the bottom of a scrolling list, shown only
/// while there is still something below the fold. Tapping it scrolls down.
class _MoreBelow extends StatefulWidget {
  const _MoreBelow({required this.controller});

  final ScrollController controller;

  @override
  State<_MoreBelow> createState() => _MoreBelowState();
}

class _MoreBelowState extends State<_MoreBelow> {
  ScrollController get controller => widget.controller;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    // The list lays out after this row's first build, and a controller only
    // notifies on a scroll: look again once the list has its size.
    if (controller.positions.length != 1 ||
        !controller.positions.first.hasContentDimensions) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && controller.hasClients) setState(() {});
      });
    }

    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        // `positions`, never `position`: during a rebuild the controller can
        // briefly hold two, and `position` throws on more than one.
        final positions = controller.positions;
        final more =
            positions.length == 1 &&
            positions.first.hasContentDimensions &&
            positions.first.extentAfter > 8;
        if (!more) return const SizedBox.shrink();

        // The sidebar panel's own fill (see `color: scheme.surfaceContainerLow` above).
        final bg = scheme.surfaceContainerLow;

        return DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [bg.withValues(alpha: 0), bg],
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.only(top: 16),
            child: Center(
              child: TextButton.icon(
                key: const Key('sidebar-more-below'),
                onPressed: () => controller.animateTo(
                  controller.position.maxScrollExtent,
                  duration: const Duration(milliseconds: 250),
                  curve: Curves.easeOut,
                ),
                icon: Icon(Icons.expand_more, size: 18, color: scheme.primary),
                label: const Text('More'),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// A sidebar group heading ("Work", "Customers"...): small, muted capitals.
class _GroupHeading extends StatelessWidget {
  const _GroupHeading(this.label);

  final String label;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 2),
    child: Text(
      label.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
        letterSpacing: 0.6,
        fontWeight: FontWeight.w700,
      ),
    ),
  );
}

/// A module's name under its icon in the phone bar: one short word. The
/// full name stays in the tooltip and the drawer.
String barLabel(String id, String label) =>
    const {
      'phone': 'Comms',
      'project_board': 'Projects',
      'customer_experience': 'Experience',
    }[id] ??
    (label.length <= 10 ? label : label.split(' ').first);
