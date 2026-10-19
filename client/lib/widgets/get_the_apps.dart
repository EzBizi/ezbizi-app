import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/client_platform.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// Letting people on a browser or a computer know there's an app (UI queue
/// 67).
///
/// Jose, 2026-10-08: "Feel like we need a way to promote the app as well, so
/// people who are using the web or desktop know there's an app, something
/// seamless". He chose 1 and 4: one card on the Dashboard for somebody who has
/// never used the phone app, Get the apps in the account menu for any time
/// after, and a slim banner in a phone's browser.
///
/// The phone app marks itself used ([markPhoneAppUsed]), so nobody who has it
/// is asked. No thanks is one per-person preference, so a choice on one
/// computer holds on every other. A server that does not know either
/// preference yet asks nobody.
const appStoreUrl = 'https://apps.apple.com/us/app/ezbizi/id6805979795';
const googlePlayUrl =
    'https://play.google.com/store/apps/details?id=com.ezbizi.app';

/// The website's download page: both stores and the computer apps. What the
/// QR code opens, so a phone's camera lands somewhere with the right button.
const downloadUrl = 'https://www.ezbizi.com/download';

/// The published Windows installer and the Mac App Store listing, both live
/// (measured 2026-10-19: the installer the download page links, and the same
/// App Store app id, whose listing names macOS).
const windowsInstallerUrl = 'https://www.ezbizi.com/download/EzBizi-setup.exe';
const macAppStoreUrl = 'https://apps.apple.com/app/id6805979795?platform=mac';

const _used = 'apps.phone_used';
const _dismissed = 'apps.phone_card_dismissed';

/// On the phone app, every start: so no computer asks this person again.
///
/// [platform] is for a test; the app reports its own.
void markPhoneAppUsed(Session session, {String? platform}) {
  final name = platform ?? ClientPlatform.name;
  final phone = name == 'android' || name == 'ios';
  final preferences = session.me?.preferences ?? const {};
  if (phone && preferences[_used] == false) {
    unawaited(session.setPreference(_used, true));
  }
}

/// A phone's browser rather than the app: Flutter on the web reads the
/// platform from the browser.
bool get _phoneBrowser =>
    kIsWeb &&
    (defaultTargetPlatform == TargetPlatform.android ||
        defaultTargetPlatform == TargetPlatform.iOS);

bool get _computer =>
    !_phoneBrowser &&
    ClientPlatform.name != 'android' &&
    ClientPlatform.name != 'ios';

Future<void> _open(String url) =>
    launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

/// Once, at the top of the Dashboard on a computer, for somebody who has never
/// used the phone app.
class PhoneAppCard extends StatelessWidget {
  const PhoneAppCard({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: session,
      builder: (context, _) {
        final preferences = session.me?.preferences ?? const {};
        // Only when the server has said so: a missing value is a server that
        // does not know these yet, not somebody without the app.
        if (!_computer ||
            preferences[_used] != false ||
            preferences[_dismissed] != false) {
          return const SizedBox.shrink();
        }

        final theme = Theme.of(context);
        final noThanks = TextButton.icon(
          key: const Key('phone-app-no-thanks'),
          onPressed: () => session.setPreference(_dismissed, true),
          iconAlignment: IconAlignment.end,
          icon: const Icon(Icons.close, size: 16),
          label: const Text('No thanks'),
        );
        return Padding(
          key: const Key('phone-app-card'),
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.primaryContainer.withValues(alpha: 0.45),
              border: Border.all(color: EzBiziTheme.edge(context)),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            // Narrow (a small browser window): no QR code beside the words,
            // which would leave them a sliver; the store buttons do its job.
            child: LayoutBuilder(
              builder: (context, box) {
                final narrow = box.maxWidth < 640;
                final words = Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'EzBizi on your phone',
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      narrow
                          ? 'Jobs, calls and alerts in your pocket, with '
                                "notifications even when EzBizi isn't open."
                          : 'Jobs, calls and alerts in your pocket, with '
                                "notifications even when EzBizi isn't open. "
                                "Scan with your phone's camera to install.",
                      style: theme.textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 8),
                    const _StoreButtons(),
                  ],
                );
                if (narrow) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      words,
                      Align(alignment: Alignment.centerRight, child: noThanks),
                    ],
                  );
                }
                return Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const _Qr(size: 84),
                    const SizedBox(width: 16),
                    Expanded(child: words),
                    noThanks,
                  ],
                );
              },
            ),
          ),
        );
      },
    );
  }
}

/// The account menu's Get the apps, any time: the phone app, and on a browser
/// the computer app.
class GetTheAppsPanel extends StatelessWidget {
  const GetTheAppsPanel({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final heading = theme.textTheme.labelMedium?.copyWith(
      fontWeight: FontWeight.w800,
    );
    final mac = defaultTargetPlatform == TargetPlatform.macOS;

    return Column(
      key: const Key('get-the-apps'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('ON YOUR PHONE', style: heading),
        const SizedBox(height: 10),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const _Qr(size: 132),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    "Scan with your phone's camera.",
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                  const _StoreButtons(vertical: true),
                ],
              ),
            ),
          ],
        ),
        // A browser on a computer: the app for this machine too.
        if (kIsWeb && _computer) ...[
          const SizedBox(height: 20),
          Text('ON THIS COMPUTER', style: heading),
          const SizedBox(height: 10),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    mac ? 'EzBizi for Mac' : 'EzBizi for Windows',
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Opens on its own, with notifications. Same account, '
                    'nothing to set up.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 10),
                  FilledButton(
                    key: const Key('get-the-apps-computer'),
                    onPressed: () =>
                        _open(mac ? macAppStoreUrl : windowsInstallerUrl),
                    child: Text(
                      mac
                          ? 'Get it on the Mac App Store'
                          : 'Download for Windows',
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// The sign-in page's Get the app (UI queue 106, design 1 and 4; Jose,
/// 2026-10-19: "we should also have the offer to download the app, desktop -
/// android - ios"). The web only. A phone's browser sees its own store; a
/// computer sees all four, its own platform first.
class GetTheAppRow extends StatelessWidget {
  const GetTheAppRow({super.key, this.web = kIsWeb});

  /// For tests, which do not run as the web.
  final bool web;

  @override
  Widget build(BuildContext context) {
    if (!web) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final platform = defaultTargetPlatform;

    final windows = (
      'windows',
      'Windows',
      Icons.desktop_windows_outlined,
      windowsInstallerUrl,
    );
    final mac = ('mac', 'Mac App Store', Icons.laptop_mac, macAppStoreUrl);
    final android = ('android', 'Google Play', Icons.shop, googlePlayUrl);
    final ios = ('ios', 'App Store', Icons.apple, appStoreUrl);

    final buttons = switch (platform) {
      TargetPlatform.android => [android],
      TargetPlatform.iOS => [ios],
      TargetPlatform.macOS => [mac, windows, android, ios],
      _ => [windows, mac, android, ios],
    };

    return Column(
      key: const Key('get-the-app-row'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Get the app',
          style: theme.textTheme.labelMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final (i, (key, label, icon, url)) in buttons.indexed)
              i == 0
                  ? FilledButton.tonalIcon(
                      key: Key('get-the-app-$key'),
                      onPressed: () => _open(url),
                      icon: Icon(icon, size: 18),
                      label: Text(label),
                    )
                  : OutlinedButton.icon(
                      key: Key('get-the-app-$key'),
                      onPressed: () => _open(url),
                      icon: Icon(icon, size: 18),
                      label: Text(label),
                    ),
          ],
        ),
      ],
    );
  }
}

/// In a phone's browser on Android: a slim Get the app banner, until No
/// thanks. An iPhone needs none of this: Safari draws Apple's own Smart App
/// Banner from one tag in web/index.html.
class PhoneAppBanner extends StatelessWidget {
  const PhoneAppBanner({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: session,
      builder: (context, _) {
        final preferences = session.me?.preferences ?? const {};
        if (!kIsWeb ||
            defaultTargetPlatform != TargetPlatform.android ||
            preferences[_dismissed] != false) {
          return const SizedBox.shrink();
        }
        final theme = Theme.of(context);
        return Material(
          key: const Key('phone-app-banner'),
          color: theme.colorScheme.surfaceContainerHighest,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(4, 6, 12, 6),
            child: Row(
              children: [
                IconButton(
                  key: const Key('phone-app-banner-close'),
                  tooltip: 'No thanks',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: () => session.setPreference(_dismissed, true),
                ),
                EzBiziTheme.wordmark(context, size: 16),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'The app: faster, with notifications',
                    style: theme.textTheme.bodySmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                // Google Play shows Open instead when it is installed.
                FilledButton(
                  key: const Key('phone-app-banner-get'),
                  onPressed: () => _open(googlePlayUrl),
                  child: const Text('Get'),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Qr extends StatelessWidget {
  const _Qr({required this.size});

  final double size;

  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(
      color: EzBiziTheme.paper,
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      border: Border.all(color: EzBiziTheme.edge(context)),
    ),
    padding: const EdgeInsets.all(6),
    child: QrImageView(
      key: const Key('get-the-apps-qr'),
      data: downloadUrl,
      size: size,
      padding: EdgeInsets.zero,
      backgroundColor: EzBiziTheme.paper,
    ),
  );
}

class _StoreButtons extends StatelessWidget {
  const _StoreButtons({this.vertical = false});

  final bool vertical;

  @override
  Widget build(BuildContext context) {
    // Dark, as the stores draw their own badges.
    final scheme = Theme.of(context).colorScheme;
    final store = FilledButton.styleFrom(
      backgroundColor: scheme.inverseSurface,
      foregroundColor: scheme.onInverseSurface,
    );
    final buttons = [
      FilledButton.icon(
        key: const Key('get-the-apps-ios'),
        style: store,
        onPressed: () => _open(appStoreUrl),
        icon: const Icon(Icons.apple, size: 18),
        label: const Text('App Store'),
      ),
      FilledButton.icon(
        key: const Key('get-the-apps-android'),
        style: store,
        onPressed: () => _open(googlePlayUrl),
        icon: const Icon(Icons.shop, size: 18),
        label: const Text('Google Play'),
      ),
    ];
    return Wrap(
      direction: vertical ? Axis.vertical : Axis.horizontal,
      spacing: 8,
      runSpacing: 8,
      children: buttons,
    );
  }
}
