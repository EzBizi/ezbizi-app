import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/clock_window.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/ui_strings.dart';

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

    final response = await widget.api.post('/time-clock/clock-in', {});
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

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final today = me?.timeClock.today;
    final now = TimeOfDay.now().format(context);
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: theme.colorScheme.surface,
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'EzBizi',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.headlineSmall,
                ),
                const SizedBox(height: 24),
                Text(
                  now,
                  textAlign: TextAlign.center,
                  // displayMedium: theme.dart fills it now (UI/UX), so the
                  // clock is 40 rather than a section heading's 28.
                  style: theme.textTheme.displayMedium,
                ),
                const SizedBox(height: 8),
                Text(
                  me?.name ?? '',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 4),
                Text(
                  today == null
                      ? UiStrings.t('time_clock.day_off')
                      : '${today.start} – ${today.end}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 24),
                Text(
                  // Past the end of the day, Clock In is not "start your day"
                  // — it is one extension, and the sweep closes it. Two people
                  // pressed it at 5 PM to get their machine back and were
                  // billed sixteen hours for it (2026-09-24).
                  me != null && me.timeClock.afterHours && today != null
                      ? UiStrings.t('time_clock.after_hours')
                            .replaceAll('{end}', today.end)
                            .replaceAll(
                              '{minutes}',
                              '${me.timeClock.extensionMinutes}',
                            )
                      : UiStrings.t('time_clock.lock_sentence'),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _busy ? null : _clockIn,
                  style: FilledButton.styleFrom(
                    backgroundColor: EzBiziTheme.clockIn(context),
                  ),
                  icon: const Icon(Icons.login),
                  label: Text(UiStrings.t('time_clock.clock_in')),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                ],
                const SizedBox(height: 24),
                TextButton(
                  onPressed: _busy ? null : _signOut,
                  child: Text(UiStrings.t('time_clock.sign_out')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
