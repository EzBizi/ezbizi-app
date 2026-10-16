import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/clock_window.dart';
import '../core/punch_position.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../core/ui_strings.dart';
import '../widgets/logo_badge.dart';
import '../widgets/user_avatar.dart';

/// Replaces the shell while the server says `lock` (design 2026-09-08 §10.2).
///
/// On web this covers the app. On Windows and macOS [ClockWindow] also takes
/// the window: fullscreen, always on top, refuses close — released the moment
/// the punch succeeds, and before signing out. It cannot stop Ctrl-Alt-Del.
class LockScreen extends StatefulWidget {
  const LockScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> with WidgetsBindingObserver {
  Timer? _tick;
  Timer? _poll;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // AFTER the first frame, not during it. The 0.0.0173 build asked for
    // fullscreen from inside initState — the frame where this route is built
    // is the app's very first — and the window people saw still had its title
    // bar. ClockWindow verifies and retries as well; this removes the reason
    // it should have to.
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => unawaited(ClockWindow.lock()),
    );
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });

    // The lock screen has to be able to let go on its own.
    //
    // The shell's poll does not run here — the shell is not mounted, this
    // route replaced it — so until now the ONLY way out was clocking in from
    // this window. Anything else the server decided (the shift opened on
    // another device, the organization switching Clock in or Lock screen off,
    // somebody marking this person exempt) never arrived, and the machine
    // stayed locked on an answer that was no longer true.
    _poll = Timer.periodic(
      const Duration(seconds: 60),
      (_) => unawaited(_refresh()),
    );
  }

  /// Ask the server whether this is still locked, and apply whatever it says.
  /// `lock: false` rebuilds the root into the shell, which disposes this route
  /// and hands the window back.
  Future<void> _refresh() async {
    final response = await widget.api.get('/time-clock/me');

    if (!mounted || !response.ok || response.data is! Map) return;

    final block = (response.data as Map)['time_clock'];
    if (block is Map) {
      widget.session.applyTimeClock(Map<String, dynamic>.from(block));
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from sleep or a minimize: re-assert the grip.
    if (state == AppLifecycleState.resumed) unawaited(ClockWindow.lock());
  }

  @override
  void dispose() {
    _tick?.cancel();
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    unawaited(ClockWindow.release());
    super.dispose();
  }

  Future<void> _clockIn() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/time-clock/clock-in',
      await punchPosition(widget.session.me?.timeClock.needsLocation ?? false),
    );
    if (!mounted) return;

    if (response.ok && response.data is Map) {
      final block = (response.data as Map)['time_clock'];
      if (block is Map) {
        // Released first, not awaited: window_manager queues its calls in
        // order, so the grip is gone before the shell paints, and a platform
        // future that never completes (a widget test) cannot hold the
        // session hostage. dispose() releases again, harmlessly.
        unawaited(ClockWindow.release());
        widget.session.applyTimeClock(Map<String, dynamic>.from(block));
      }
      if (mounted) setState(() => _busy = false);
      return;
    }

    setState(() {
      _busy = false;
      _error = response.reason;
    });
  }

  Future<void> _signOut() async {
    unawaited(ClockWindow.release());
    await widget.api.post('/auth/logout', {});
    widget.session.signOut();
  }

  /// UI queue 36, Jose's 1 + 4 (2026-09-30: "the clock in lock screen could
  /// also use some love, show the app logo, etc." - "go with ur
  /// recommendations"): the sign-in page's dark surface, one card with the
  /// app's mark, a large clock and the date, the person and today's shift in
  /// the organization's own clock, then Clock In. On a phone the card is the
  /// screen, with Clock In at the foot.
  @override
  Widget build(BuildContext context) {
    // Always the dark theme: the surface is the brand's, not the reader's
    // setting, and in light mode "Bizi" and every line on it would be drawn
    // dark on dark.
    return Theme(
      data: EzBiziTheme.dark(),
      child: Builder(builder: _page),
    );
  }

  Widget _page(BuildContext context) {
    final me = widget.session.me;
    final today = me?.timeClock.today;
    final formats = me?.display ?? DisplayFormats.fallback;
    // The big clock is the device's: it is decoration. The day is the
    // organization's, from the server, so a device in another zone - or one
    // just past midnight - cannot name the wrong one (Clock In, 2026-09-30).
    final clock = DateTime.now();
    final day = me?.timeClock.todayLabel ?? '';
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    // Past the end of the day, Clock In is not "start your day" - it is one
    // extension, and the sweep closes it. Two people pressed it at 5 PM to get
    // their machine back and were billed sixteen hours for it (2026-09-24).
    final sentence = me != null && me.timeClock.afterHours && today != null
        ? UiStrings.t('time_clock.after_hours')
              .replaceAll('{end}', today.end)
              .replaceAll('{minutes}', '${me.timeClock.extensionMinutes}')
        : UiStrings.t('time_clock.lock_sentence');

    final top = <Widget>[
      Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: Image.asset(
              'assets/logo/app-icon-120.png',
              key: const ValueKey('lock-app-icon'),
              width: 34,
              height: 34,
            ),
          ),
          const SizedBox(width: 10),
          EzBiziTheme.wordmark(context, size: 24),
        ],
      ),
      const SizedBox(height: 22),
      Text(
        formats.formatTime(clock.hour, clock.minute),
        key: const ValueKey('lock-clock'),
        textAlign: TextAlign.center,
        style: theme.textTheme.displayMedium?.copyWith(
          fontWeight: FontWeight.w800,
          letterSpacing: -1.5,
        ),
      ),
      if (day.isNotEmpty) ...[
        const SizedBox(height: 4),
        Text(
          day,
          key: const ValueKey('lock-day'),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(color: muted),
        ),
      ],
      const SizedBox(height: 22),
      if (me != null)
        UserAvatar(
          api: widget.api,
          userId: me.id,
          name: me.name,
          radius: 28,
          hasAvatar: me.hasAvatar,
        ),
      const SizedBox(height: 10),
      Text(
        me?.name ?? '',
        textAlign: TextAlign.center,
        style: theme.textTheme.titleMedium?.copyWith(
          fontWeight: FontWeight.w700,
        ),
      ),
      if ((me?.roleName ?? '').isNotEmpty)
        Text(
          me!.roleName!,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
      const SizedBox(height: 10),
      _shift(context, today, formats),
    ];

    final bottom = <Widget>[
      Text(sentence, textAlign: TextAlign.center),
      const SizedBox(height: 12),
      SizedBox(
        height: 48,
        child: FilledButton.icon(
          onPressed: _busy ? null : _clockIn,
          style: FilledButton.styleFrom(
            backgroundColor: EzBiziTheme.clockIn(context),
          ),
          icon: const Icon(Icons.login),
          label: Text(UiStrings.t('time_clock.clock_in')),
        ),
      ),
      if (_error != null) ...[
        const SizedBox(height: 12),
        Text(
          _error!,
          textAlign: TextAlign.center,
          style: TextStyle(color: theme.colorScheme.error),
        ),
      ],
      const SizedBox(height: 12),
      TextButton(
        onPressed: _busy ? null : _signOut,
        child: Text(UiStrings.t('time_clock.sign_out')),
      ),
    ];

    final organization = me == null
        ? null
        : Row(
            key: const ValueKey('lock-organization'),
            mainAxisSize: MainAxisSize.min,
            children: [
              LogoBadge(
                api: widget.api,
                path: me.scope.logoPath,
                version: me.scope.logoVersion,
                hasOwn: false,
                mayEdit: false,
                bare: true,
                aspect: me.scope.logoAspect,
                size: 18,
              ),
              const SizedBox(width: 8),
              Text(
                me.organization.name,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ],
          );

    return Scaffold(
      body: DecoratedBox(
        decoration: const BoxDecoration(gradient: EzBiziTheme.brandGradient),
        child: SafeArea(
          child: LayoutBuilder(
            builder: (context, box) {
              // A phone: the card is the screen, and Clock In sits at the
              // foot where a thumb is.
              if (box.maxWidth < 600) {
                return SingleChildScrollView(
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: box.maxHeight),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(24, 40, 24, 20),
                      child: IntrinsicHeight(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            for (final w in top) Center(child: w),
                            const Spacer(),
                            const SizedBox(height: 24),
                            ...bottom,
                            if (organization != null)
                              Center(child: organization),
                          ],
                        ),
                      ),
                    ),
                  ),
                );
              }

              return Stack(
                children: [
                  Center(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(24),
                      child: Container(
                        key: const ValueKey('lock-card'),
                        width: 440,
                        padding: const EdgeInsets.fromLTRB(30, 28, 30, 18),
                        decoration: BoxDecoration(
                          color: EzBiziTheme.brandCard,
                          border: Border.all(color: EzBiziTheme.brandEdge),
                          borderRadius: BorderRadius.circular(18),
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            for (final w in top) Center(child: w),
                            const SizedBox(height: 20),
                            ...bottom,
                          ],
                        ),
                      ),
                    ),
                  ),
                  if (organization != null)
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 16,
                      child: Center(child: organization),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  /// Today's shift as a chip, in the organization's own clock: it read a raw
  /// 08:00 - 17:00 whatever the organization's setting.
  Widget _shift(
    BuildContext context,
    TimeClockWindow? today,
    DisplayFormats f,
  ) {
    final theme = Theme.of(context);
    final text = today == null
        ? UiStrings.t('time_clock.day_off')
        : UiStrings.t('time_clock.today_hours')
              .replaceAll('{start}', f.formatWallClock(today.start))
              .replaceAll('{end}', f.formatWallClock(today.end));

    return Container(
      key: const ValueKey('lock-shift'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.brandEdge),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (today != null) ...[
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(
                color: EzBiziTheme.clockIn(context),
                shape: BoxShape.circle,
              ),
            ),
            const SizedBox(width: 6),
          ],
          Text(text, style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }
}
