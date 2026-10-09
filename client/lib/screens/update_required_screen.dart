import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../core/api_client.dart';
import '../core/build_info.dart';
import '../core/client_platform.dart';
import '../core/theme.dart';

/// The whole screen, when the server has refused this app for being too old.
///
/// ── Why a screen and not a dialog ────────────────────────────────
///
/// House rule first: no pop-ups, ever. But this one earns the rule twice over.
/// A modal implies the thing behind it is still there to go back to, and it is
/// not — the server is refusing this build, so every list behind the dim layer
/// is either stale or empty and every button on it will fail. Dimming a working
/// app is a different message from replacing an app that has stopped working,
/// and only the second one is true here.
///
/// ── What this screen owes the person reading it ──────────────────
///
/// Three things, in this order:
///
///   - what happened, in words that do not require knowing what a version is;
///   - what to do about it, which is NOT the same sentence on every platform;
///   - a way to leave, because a screen with no exit is a dead end even when it
///     is correct.
///
/// ── Why the sentence is the server's and not one written here ────
///
/// Because a sentence compiled into this app was written on the day this app was
/// built, and the entire premise of this screen is that that day was too long
/// ago. The server knows which version it now needs and where a newer build
/// lives under whichever channel this platform uses — App Store, Play Store,
/// MSIX, a self-hosted installer. This screen relays that. It has words of its
/// own only for the case where the server offered none, so that a thin payload
/// degrades to something plain rather than to a blank space.
class UpdateRequiredScreen extends StatefulWidget {
  /// Not `const`, because both platform defaults below are resolved by asking
  /// [ClientPlatform] — which is a runtime answer and therefore not something a
  /// constant constructor may reach for. Worth naming: every other small widget
  /// in this app is const, so the absence looks like an oversight otherwise.
  UpdateRequiredScreen({
    super.key,
    required this.notice,
    bool? isWeb,
    bool Function()? reload,
    this.onTryAgain,
    this.onSignOut,
  })  : isWeb = isWeb ?? _detectedIsWeb,
        reload = reload ?? _detectedReload;

  /// What the server said. See [UpgradeRequired] — every one of its fields may
  /// be absent, and this screen is written on the assumption that they will be.
  final UpgradeRequired notice;

  /// Whether this is a browser, deciding which remedy is offered.
  ///
  /// Injectable rather than read straight from [ClientPlatform] so both halves
  /// can be tested. A test process is not a browser, so without this the web
  /// copy — the copy most customers will actually see, since web is the build
  /// that ships today — could never be exercised at all.
  final bool isWeb;

  /// Fetch the app again. Injectable for the same reason, and because a real
  /// reload inside a test would be either a no-op or a disaster depending on the
  /// runner.
  final bool Function() reload;

  /// Drop the gate and let the app carry on. Null hides the control.
  final VoidCallback? onTryAgain;

  /// End the session locally. Null when nobody is signed in, which is most of
  /// the time this screen appears at the sign-in form.
  final VoidCallback? onSignOut;

  static bool get _detectedIsWeb => ClientPlatform.isWeb;
  static bool _detectedReload() => ClientPlatform.reload();

  @override
  State<UpdateRequiredScreen> createState() => _UpdateRequiredScreenState();
}

class _UpdateRequiredScreenState extends State<UpdateRequiredScreen> {
  /// Set when the address has just been put on the clipboard, so the button can
  /// say so in place.
  ///
  /// In place rather than as a snack bar or a toast: those are pop-ups by
  /// another name, they cover the bottom of a 375px screen where the buttons
  /// are, and they announce success somewhere other than where the person is
  /// looking. Changing the label of the control they just pressed puts the
  /// answer under their finger.
  bool _copied = false;

  /// Set when a reload was asked for and the browser refused it. Rare — some
  /// embedded webviews block programmatic navigation — and worth a sentence
  /// rather than a button that appears to do nothing.
  bool _reloadRefused = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final url = widget.notice.upgradeUrl;
    final note = widget.notice.note;

    return Scaffold(
      body: SafeArea(
        child: Center(
          // Scrollable even though it usually fits. At 375 wide with the text
          // scale turned up — which is exactly the person most likely to be on
          // an old build of anything — this content is taller than the viewport,
          // and the alternative to scrolling is the sign-out button being
          // unreachable off the bottom edge.
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: ConstrainedBox(
              // Wide enough for a comfortable line, narrow enough that the text
              // does not run the full width of a desktop monitor.
              constraints: const BoxConstraints(maxWidth: 520),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  EzBiziTheme.wordmark(context, size: 26),
                  const SizedBox(height: 32),

                  // Plain words, and no version number in the headline. "Version
                  // 0.0.0030 is below the minimum of 0.0.0034" is a true
                  // sentence that means nothing to a chimney sweep holding a
                  // phone in somebody's driveway.
                  Text(
                    'This app is too old to keep working',
                    style: theme.textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 16),

                  Text(_explanation, style: theme.textTheme.bodyMedium),
                  const SizedBox(height: 28),

                  _RemedyCard(
                    isWeb: widget.isWeb,
                    url: url,
                    copied: _copied,
                    reloadRefused: _reloadRefused,
                    onReload: _reload,
                    onCopy: url == null ? null : () => _copy(url),
                  ),

                  // Whatever staff wrote against this minimum, under the remedy
                  // and never in place of it.
                  //
                  // It is the only part of this screen that can answer "why is
                  // this happening to me today", and being told to install
                  // something for no stated reason is how an update prompt
                  // becomes a support call instead of an action. Under, because
                  // what to do outranks why; and quieter, because it is context
                  // rather than instruction.
                  //
                  // This field was stored, validated, audited and shipped in the
                  // refusal for a while before anything read it — a staff-editable
                  // box that changed nothing a customer could see.
                  if (note != null) ...[
                    const SizedBox(height: 16),
                    Text(note, style: theme.textTheme.bodyMedium),
                  ],

                  const SizedBox(height: 24),

                  // The exits, deliberately quieter than the remedy above them
                  // and deliberately present. See main.dart's onTryAgain for
                  // why trying again is offered at all.
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      if (widget.onTryAgain != null)
                        TextButton(
                          onPressed: widget.onTryAgain,
                          child: const Text('Try again'),
                        ),
                      if (widget.onSignOut != null)
                        TextButton(
                          onPressed: widget.onSignOut,
                          child: const Text('Sign out'),
                        ),
                    ],
                  ),

                  if (widget.onSignOut != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Signing out works even now, and does not need the '
                      'server. Your work is saved where it always is.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ],

                  const SizedBox(height: 32),
                  const Divider(),
                  const SizedBox(height: 12),

                  // The version, with a reason to care about it attached. A
                  // build stamp on its own is decoration; a build stamp with
                  // "read this out to whoever helps you" is a support call that
                  // starts from a fact instead of from "it says something about
                  // being old".
                  Text(
                    'This app is ${BuildInfo.short}. Whoever helps you with '
                    'EzBizi may ask for that.',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// The server's sentence, or ours only if it sent none.
  ///
  /// The fallbacks differ by platform for the same reason the remedy does: an
  /// app installed on a machine and a page loaded into a tab became out of date
  /// by different routes, and saying "this page has been open a while" to
  /// somebody running the Windows app would be simply false.
  String get _explanation {
    final message = widget.notice.message;

    if (message.isNotEmpty) return message;

    return widget.isWeb
        ? 'EzBizi has been updated since this page was loaded, and the server '
            'no longer accepts the copy running in this tab.'
        : 'EzBizi has been updated, and the server no longer accepts the '
            'version installed on this device.';
  }

  void _reload() {
    final worked = widget.reload();

    if (!worked) setState(() => _reloadRefused = true);
  }

  Future<void> _copy(String url) async {
    await Clipboard.setData(ClipboardData(text: url));

    if (!mounted) return;

    setState(() => _copied = true);
  }
}

/// What to do about it — the half of this screen that is genuinely different per
/// platform.
class _RemedyCard extends StatelessWidget {
  const _RemedyCard({
    required this.isWeb,
    required this.url,
    required this.copied,
    required this.reloadRefused,
    required this.onReload,
    required this.onCopy,
  });

  final bool isWeb;
  final String? url;
  final bool copied;
  final bool reloadRefused;
  final VoidCallback onReload;
  final VoidCallback? onCopy;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: isWeb ? _web(context, theme) : _installed(context, theme),
        ),
      ),
    );
  }

  /// ── Web ──────────────────────────────────────────────────────────
  ///
  /// A browser is handed the whole app fresh on every load, so it can only ever
  /// be stale because a tab has been sitting open — often for days, which is
  /// exactly how somebody ends up here. The remedy is a reload and it takes a
  /// second.
  ///
  /// There is no download link on this branch even when the server sent one.
  /// A URL is configured per platform for the installed builds, and offering an
  /// installer to somebody in a browser sends them looking for a file they do
  /// not need and cannot use — while the actual fix, one button away, goes
  /// unread. The server may still send a URL; this branch ignores it on purpose.
  List<Widget> _web(BuildContext context, ThemeData theme) => [
        Text('Reload this page', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          'The web version is served fresh every time you load it, so '
          'reloading is all it takes. Nothing to install.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: onReload,
          child: const Text('Reload EzBizi'),
        ),
        if (reloadRefused) ...[
          const SizedBox(height: 12),
          Text(
            'This browser would not reload the page by itself. Press the '
            'reload button in the browser, or close this tab and open EzBizi '
            'again.',
            style: theme.textTheme.bodyMedium,
          ),
        ],
      ];

  /// ── Installed apps ───────────────────────────────────────────────
  ///
  /// Windows today, then Android, iOS and macOS. An installed app cannot fetch a
  /// new copy of itself, so the remedy is a download and it is a bigger ask —
  /// which is why the address matters and why the branch with no address still
  /// has to end in something a person can do.
  List<Widget> _installed(BuildContext context, ThemeData theme) {
    final url = this.url;

    if (url == null) {
      // No configured address. It is not an error state and must not read like
      // one: it means nobody has set a download location for this platform yet,
      // which is a normal thing on the day a platform is new.
      return [
        Text('Install the newer version', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          'There is no download address set for this kind of device yet. '
          'Whoever set EzBizi up for your business can tell you where to get '
          'the newer version.',
          style: theme.textTheme.bodyMedium,
        ),
      ];
    }

    return [
      Text('Install the newer version', style: theme.textTheme.titleMedium),
      const SizedBox(height: 8),
      Text(
        'Get the newer version from the address below, install it, then open '
        'EzBizi again.',
        style: theme.textTheme.bodyMedium,
      ),
      const SizedBox(height: 16),

      // The address in full, selectable, in a box of its own.
      //
      // Not a button that opens it. Opening a browser from an installed app
      // needs a plugin this client does not have, and adding a dependency to
      // this screen — the one screen that has to work on the oldest build any
      // customer is running — is the wrong trade. Shown in full instead, so it
      // can be copied, read aloud down a phone, or typed by hand. All three are
      // things somebody can actually do; a button that silently fails is not.
      Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: EzBiziTheme.edge(context)),
        ),
        child: SelectableText(
          url,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurface,
          ),
        ),
      ),
      const SizedBox(height: 12),

      FilledButton(
        onPressed: onCopy,
        child: Text(copied ? 'Address copied' : 'Copy the address'),
      ),
    ];
  }
}
