import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/side_panel.dart';
import 'listing_copy.dart';

/// A listing's photos and videos on Google, with Copy to locations (design
/// 154 #1, Jose: "yes go ahead with all ur recommendations").
///
/// Logo and Cover first, then the rest; chips All · Photos · Videos · Logo ·
/// Cover; tick thumbnails, then Copy opens a SidePanel of the other listings.
/// Copies are new uploads on each listing; nothing is removed.
class ListingMedia extends StatefulWidget {
  const ListingMedia({
    super.key,
    required this.api,
    required this.locationId,
    required this.others,
  });

  final ApiClient api;
  final String locationId;
  final List<ListingTarget> others;

  @override
  State<ListingMedia> createState() => _ListingMediaState();
}

enum _Kind { all, photos, videos, logo, cover }

const _kindLabels = {
  _Kind.all: 'All',
  _Kind.photos: 'Photos',
  _Kind.videos: 'Videos',
  _Kind.logo: 'Logo',
  _Kind.cover: 'Cover',
};

bool _isVideo(Map m) => m['format'] == 'VIDEO';

bool _isKind(Map m, _Kind k) => switch (k) {
  _Kind.all => true,
  _Kind.photos => !_isVideo(m),
  _Kind.videos => _isVideo(m),
  _Kind.logo => m['category'] == 'LOGO',
  _Kind.cover => m['category'] == 'COVER',
};

/// LOGO → Logo, ADDITIONAL → Additional.
String mediaCategoryLabel(Object? c) {
  final s = '${c ?? ''}'.replaceAll('_', ' ').toLowerCase();
  return s.isEmpty ? 'Photo' : s[0].toUpperCase() + s.substring(1);
}

class _ListingMediaState extends State<ListingMedia> {
  List<Map<String, dynamic>>? _media;
  String? _error;
  _Kind _kind = _Kind.all;
  String _q = '';
  final Set<String> _ticked = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ListingMedia old) {
    super.didUpdateWidget(old);
    if (old.locationId != widget.locationId) {
      _ticked.clear();
      _media = null;
      _load();
    }
  }

  Future<void> _load() async {
    final r = await widget.api.get(
      '/business-profile/locations/${widget.locationId}/media',
    );
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      final items = [
        for (final m in (r.data as List? ?? const []).whereType<Map>())
          Map<String, dynamic>.from(m),
      ];
      int rank(Map m) => switch (m['category']) {
        'LOGO' => 0,
        'COVER' => 1,
        _ => 2,
      };
      items.sort((a, b) => rank(a).compareTo(rank(b)));
      _media = r.ok ? items : null;
    });
  }

  List<Map<String, dynamic>> get _shown {
    final q = _q.trim().toLowerCase();
    return [
      for (final m in _media ?? const <Map<String, dynamic>>[])
        if (_isKind(m, _kind) &&
            (q.isEmpty ||
                mediaCategoryLabel(m['category']).toLowerCase().contains(q) ||
                '${m['name']}'.toLowerCase().contains(q)))
          m,
    ];
  }

  Future<void> _copy() async {
    await showSidePanel<bool>(
      context,
      builder: (_) => CopyMediaPanel(
        api: widget.api,
        locationId: widget.locationId,
        media: _ticked.toList(),
        others: widget.others,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: refusedNote(context, _error!),
      );
    }
    final media = _media;
    if (media == null) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final shown = _shown;

    return Column(
      key: const ValueKey('cx-media'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final k in _Kind.values)
              ChoiceChip(
                key: ValueKey('cx-media-kind-${k.name}'),
                label: Text(
                  '${_kindLabels[k]} · ${media.where((m) => _isKind(m, k)).length}',
                ),
                selected: _kind == k,
                onSelected: (_) => setState(() => _kind = k),
              ),
          ],
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('cx-media-search'),
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search, size: 18),
            hintText: 'Search media',
            isDense: true,
          ),
          onChanged: (v) => setState(() => _q = v),
        ),
        const SizedBox(height: 8),
        if (_ticked.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    '${_ticked.length} selected',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
                FilledButton.tonal(
                  key: const ValueKey('cx-media-copy'),
                  onPressed: widget.others.isEmpty ? null : _copy,
                  child: Text(
                    'Copy ${_ticked.length} to locations',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        if (shown.isEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              media.isEmpty
                  ? 'Google has no photos or videos on this listing.'
                  : 'Nothing here matches.',
              style: theme.textTheme.bodySmall,
            ),
          )
        else
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [for (final m in shown) _tile(theme, m)],
          ),
      ],
    );
  }

  Widget _tile(ThemeData theme, Map<String, dynamic> m) {
    final name = '${m['name']}';
    final ticked = _ticked.contains(name);
    final thumb = (m['thumbnail'] ?? m['url']) as String?;
    final scheme = theme.colorScheme;

    return InkWell(
      key: ValueKey('cx-media-$name'),
      borderRadius: BorderRadius.circular(8),
      onTap: () =>
          setState(() => ticked ? _ticked.remove(name) : _ticked.add(name)),
      child: Container(
        width: 104,
        height: 104,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: ticked ? scheme.primary : EzBiziTheme.edge(context),
            width: ticked ? 2 : 1,
          ),
        ),
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (thumb != null)
              Image.network(
                thumb,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) =>
                    Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
              )
            else
              Icon(
                _isVideo(m) ? Icons.videocam_outlined : Icons.image_outlined,
                color: scheme.onSurfaceVariant,
              ),
            Positioned(
              left: 4,
              bottom: 4,
              right: 4,
              child: Text(
                mediaCategoryLabel(m['category']),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: scheme.onSurface,
                  backgroundColor: scheme.surface,
                ),
              ),
            ),
            if (ticked)
              Positioned(
                top: 4,
                right: 4,
                child: Icon(Icons.check_circle, color: scheme.primary),
              ),
          ],
        ),
      ),
    );
  }
}

/// Copy N photos to… : the other listings, ticked, then each one's result.
class CopyMediaPanel extends StatefulWidget {
  const CopyMediaPanel({
    super.key,
    required this.api,
    required this.locationId,
    required this.media,
    required this.others,
  });

  final ApiClient api;
  final String locationId;
  final List<String> media;
  final List<ListingTarget> others;

  @override
  State<CopyMediaPanel> createState() => _CopyMediaPanelState();
}

class _CopyMediaPanelState extends State<CopyMediaPanel> {
  final Set<String> _ticked = {};
  String _q = '';
  bool _busy = false;
  String? _refused;
  Map<String, CopyResult> _results = {};

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _refused = null;
    });
    final answer = await sendCopy(
      widget.api,
      '/business-profile/locations/${widget.locationId}/media/copy',
      {'media': widget.media, 'targets': _ticked.toList()},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      _refused = answer.refused;
      _results = {for (final r in answer.results ?? const []) r.id: r};
    });
  }

  @override
  Widget build(BuildContext context) {
    final n = widget.media.length;
    return SidePanel(
      title: 'Copy $n ${n == 1 ? 'item' : 'items'} to…',
      actions: [
        FilledButton(
          key: const ValueKey('cx-media-copy-send'),
          onPressed: _busy || _ticked.isEmpty ? null : _send,
          child: Text(
            'Copy to ${_ticked.length} '
            '${_ticked.length == 1 ? 'listing' : 'listings'}',
          ),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_refused != null) refusedNote(context, _refused!),
          ListingTargets(
            targets: widget.others,
            ticked: _ticked,
            query: _q,
            onQuery: (v) => setState(() => _q = v),
            onChanged: (v) => setState(() {
              _ticked
                ..clear()
                ..addAll(v);
            }),
            trailing: (t) => switch (_results[t.id]) {
              final r? => copyResultPill(r),
              null => null,
            },
          ),
          const SizedBox(height: 12),
          Text(
            'Copies are new uploads; nothing is removed.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
