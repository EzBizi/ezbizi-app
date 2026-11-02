import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../screens/phone/recording_player.dart';
import '../screens/phone/recording_playback_bar.dart';
import 'side_panel.dart';

/// WhatsApp media in a lead thread (design 160, A1). Each attachment URL
/// names its kind in a `kind` query parameter; nothing is stored, the URL
/// streams it from Meta when opened.
const whatsAppMediaKinds = {
  'audio',
  'voice',
  'image',
  'video',
  'document',
  'sticker',
};

/// The kind a media URL names, or null for a plain attachment (a photo
/// Google hosts, say), which keeps its old drawing.
String? mediaKind(String url) {
  final kind = Uri.tryParse(url)?.queryParameters['kind'];
  return whatsAppMediaKinds.contains(kind) ? kind : null;
}

/// Ours, typed in the WhatsApp Business app rather than in EzBizi. The flag
/// is Meta's; the sender string is what rows said before the flag existed.
bool sentFromPhone(Map<String, dynamic> c) =>
    c['sent_from_phone'] == true ||
    c['sent_by'] == 'Phone (WhatsApp Business app)';

/// One media attachment. On a refusal or a failed load it asks the thread
/// to re-read itself once (a stale link is renewed by the read), then says
/// it is gone.
class LeadMedia extends StatefulWidget {
  const LeadMedia({
    super.key,
    required this.url,
    required this.kind,
    required this.api,
    required this.player,
    required this.onExpired,
    required this.ink,
  });

  final String url;
  final String kind;
  final ApiClient api;
  final RecordingPlayer player;

  /// Re-reads the thread; a fresh URL arrives as a new [url].
  final Future<void> Function() onExpired;

  /// The bubble's text colour, so the media reads on either side.
  final Color ink;

  @override
  State<LeadMedia> createState() => _LeadMediaState();
}

class _LeadMediaState extends State<LeadMedia> {
  bool _refetched = false;
  bool _gone = false;
  int _attempt = 0;

  Future<void> _failed() async {
    if (_refetched) {
      if (mounted) setState(() => _gone = true);
      return;
    }
    _refetched = true;
    await widget.onExpired();
    if (mounted) setState(() => _attempt++);
  }

  void _open() =>
      launchUrl(Uri.parse(widget.url), mode: LaunchMode.externalApplication);

  /// The bytes, through the API when the URL is ours (it carries the token),
  /// null on any refusal.
  Future<List<int>?> _fetch() async {
    final uri = Uri.parse(widget.url);
    final at = uri.path.indexOf('/api/v1');
    if (at < 0) return null;
    final response = await widget.api.getBytesResponse(
      uri.path.substring(at + '/api/v1'.length),
      uri.queryParameters,
    );
    return response.bytes;
  }

  Future<List<int>?> _loadAudio() async {
    var bytes = await _fetch();
    if (bytes == null && !_refetched) {
      _refetched = true;
      await widget.onExpired();
      bytes = await _fetch();
    }
    if (bytes == null && mounted) setState(() => _gone = true);
    return bytes;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_gone) {
      return Text(
        'No longer available from WhatsApp',
        key: const ValueKey('media-gone'),
        style: theme.textTheme.bodySmall?.copyWith(
          color: widget.ink,
          fontStyle: FontStyle.italic,
        ),
      );
    }

    switch (widget.kind) {
      case 'audio':
      case 'voice':
        final inApi = Uri.parse(widget.url).path.contains('/api/v1');
        if (!inApi) {
          return TextButton.icon(
            key: const ValueKey('media-audio'),
            onPressed: _open,
            icon: Icon(Icons.play_arrow, color: widget.ink),
            label: Text(
              widget.kind == 'voice' ? 'Voice note' : 'Audio',
              style: TextStyle(color: widget.ink),
            ),
          );
        }
        return SizedBox(
          key: const ValueKey('media-audio'),
          width: 260,
          child: RecordingPlaybackBar(
            key: ValueKey('media-audio-bar-$_attempt'),
            player: widget.player,
            dense: true,
            load: _loadAudio,
          ),
        );
      case 'image':
      case 'sticker':
        return InkWell(
          key: ValueKey('media-${widget.kind}'),
          borderRadius: BorderRadius.circular(8),
          onTap: () => _viewer(context),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: SizedBox(
              width: widget.kind == 'sticker' ? 96 : 180,
              height: widget.kind == 'sticker' ? 96 : 110,
              child: Image.network(
                widget.url,
                key: ValueKey('media-img-$_attempt'),
                fit: widget.kind == 'sticker' ? BoxFit.contain : BoxFit.cover,
                errorBuilder: (_, _, _) {
                  WidgetsBinding.instance.addPostFrameCallback(
                    (_) => _failed(),
                  );
                  return ColoredBox(
                    color: theme.colorScheme.surfaceContainerHighest,
                  );
                },
              ),
            ),
          ),
        );
      case 'video':
        return InkWell(
          key: const ValueKey('media-video'),
          borderRadius: BorderRadius.circular(8),
          onTap: _open,
          child: Container(
            width: 180,
            height: 110,
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(
              Icons.play_circle_fill,
              size: 40,
              semanticLabel: 'Play video',
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        );
      default:
        return InkWell(
          key: const ValueKey('media-document'),
          borderRadius: BorderRadius.circular(8),
          onTap: _open,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
            decoration: BoxDecoration(
              color: theme.colorScheme.surface,
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.description_outlined,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 8),
                Text('Document', style: theme.textTheme.bodyMedium),
              ],
            ),
          ),
        );
    }
  }

  Future<void> _viewer(BuildContext context) => showSidePanel<void>(
    context,
    builder: (_) => SidePanel(
      title: widget.kind == 'sticker' ? 'Sticker' : 'Photo',
      width: 640,
      actions: [
        TextButton(onPressed: _open, child: const Text('Open in browser')),
      ],
      child: Image.network(
        widget.url,
        key: const ValueKey('media-full'),
        fit: BoxFit.contain,
        errorBuilder: (_, _, _) =>
            const Text('No longer available from WhatsApp'),
      ),
    ),
  );
}
