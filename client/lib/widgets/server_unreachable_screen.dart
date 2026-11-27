import 'dart:async';

import 'package:flutter/material.dart';

import '../core/session.dart';

/// Shown instead of the sign-in form when a kept session exists but the
/// server could not be reached to check it (Session.waitingForServer).
///
/// Launching before Wi-Fi was up, or during a deploy, used to land on the
/// sign-in screen with nothing retrying — and signing in again replaced the
/// kept token. This keeps the token, says what is happening, tries again on
/// its own every [every], and offers a Retry for the impatient.
class ServerUnreachableScreen extends StatefulWidget {
  const ServerUnreachableScreen({
    super.key,
    required this.session,
    this.every = const Duration(seconds: 15),
  });

  final Session session;
  final Duration every;

  @override
  State<ServerUnreachableScreen> createState() =>
      _ServerUnreachableScreenState();
}

class _ServerUnreachableScreenState extends State<ServerUnreachableScreen> {
  Timer? _timer;
  bool _trying = false;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(widget.every, (_) => _retry());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _retry() async {
    if (_trying) return;
    setState(() => _trying = true);
    await widget.session.restore();
    if (mounted) setState(() => _trying = false);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.cloud_off_outlined, size: 40),
                const SizedBox(height: 16),
                Text(
                  'Cannot reach EzBizi',
                  style: theme.textTheme.titleMedium,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                Text(
                  'You are still signed in. This tries again on its own as '
                  'soon as the connection is back.',
                  style: theme.textTheme.bodyMedium,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: _trying ? null : _retry,
                  child: _trying
                      ? const SizedBox(
                          height: 18,
                          width: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Retry'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
