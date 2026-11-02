import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// The WhatsApp chat link in a listing's Info tab (design 154 #16): the
/// wa.me link with Open, Copy and a QR for flyers and vans. Drawn only when
/// the organization has a WhatsApp number (`whatsapp_link` is null otherwise).
class WhatsAppRow extends StatelessWidget {
  const WhatsAppRow({super.key, required this.link, this.onBulk, this.onSet});

  final String link;

  /// Opens WhatsApp links in bulk (UI queue 163); null draws no link.
  final VoidCallback? onBulk;

  /// Sets this listing's link on Google (one-by-one); null draws no button.
  final VoidCallback? onSet;

  /// Opens a link outside the app. A seam so a test can see the call.
  static Future<bool> Function(Uri) opener = (uri) =>
      launchUrl(uri, mode: LaunchMode.externalApplication);

  String get _short => link.replaceFirst(RegExp(r'^https?://'), '');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      key: const ValueKey('cx-whatsapp'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('WHATSAPP CHAT LINK', style: theme.textTheme.labelSmall),
          const SizedBox(height: 4),
          SelectableText(_short, style: theme.textTheme.bodyMedium),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              const StatusPill('Ready', tone: Tone.ok),
              TextButton.icon(
                key: const ValueKey('cx-whatsapp-open'),
                icon: const Icon(Icons.open_in_new, size: 16),
                label: const Text('Open'),
                onPressed: () => opener(Uri.parse(link)),
              ),
              TextButton.icon(
                key: const ValueKey('cx-whatsapp-copy'),
                icon: const Icon(Icons.copy, size: 16),
                label: const Text('Copy'),
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: link));
                  if (context.mounted) {
                    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
                      const SnackBar(content: Text('Link copied')),
                    );
                  }
                },
              ),
              TextButton.icon(
                key: const ValueKey('cx-whatsapp-qr'),
                icon: const Icon(Icons.qr_code, size: 16),
                label: const Text('QR'),
                onPressed: () => showSidePanel<void>(
                  context,
                  builder: (context) => SidePanel(
                    title: 'WhatsApp QR',
                    width: 360,
                    child: Center(
                      child: ColoredBox(
                        color: theme.colorScheme.surface,
                        child: QrImageView(
                          key: const ValueKey('cx-whatsapp-qr-code'),
                          data: link,
                          size: 240,
                          eyeStyle: QrEyeStyle(
                            eyeShape: QrEyeShape.square,
                            color: theme.colorScheme.onSurface,
                          ),
                          dataModuleStyle: QrDataModuleStyle(
                            dataModuleShape: QrDataModuleShape.square,
                            color: theme.colorScheme.onSurface,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              if (onSet != null)
                TextButton.icon(
                  key: const ValueKey('cx-whatsapp-set'),
                  icon: const Icon(Icons.check_circle_outline, size: 16),
                  label: const Text('Set on this listing'),
                  onPressed: onSet,
                ),
              if (onBulk != null)
                TextButton.icon(
                  key: const ValueKey('cx-whatsapp-bulk'),
                  icon: const Icon(Icons.library_add_check_outlined, size: 16),
                  label: const Text('Set on many listings'),
                  onPressed: onBulk,
                ),
            ],
          ),
          Text(
            'Customers who open it land in the WhatsApp inbox in '
            'Communication.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
