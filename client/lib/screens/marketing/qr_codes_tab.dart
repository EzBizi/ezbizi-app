import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'qr_stats.dart';

/// Marketing › QR Codes (UI queue 55; Jose, relayed by Marketing: "Go ahead
/// with ur recommendations" — desktop 1, phone 4).
///
/// A code printed for good on a van or a sign, whose web address changes here
/// without reprinting (Jose: "1 consistent qr code"). The printed image
/// encodes EzBizi's own permanent address (`url`), which forwards to wherever
/// the code points now (`destination_url`); so the code drawn here is always
/// `url`, the address every printed copy carries, and never the destination.
///
/// A card per code: the code, where it goes with Change on the card, its
/// scans, and the SVG (vehicle wraps) and PNG downloads. A code is never
/// deleted (Jose, 2026-10-06: "i dont want anyone deleting qr codes that we
/// printed by accident"): it is deactivated, asked inside the card, which
/// pauses every printed copy, and Reactivate brings it back as it was.
/// Changing, creating, deactivating and reactivating need `marketing.edit`;
/// without it the codes, where they go and the downloads are still here.
class QrCodesTab extends StatefulWidget {
  const QrCodesTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<QrCodesTab> createState() => _QrCodesTabState();
}

class _QrCodesTabState extends State<QrCodesTab> {
  bool _loading = true;
  String? _error;
  List<QrCodeRow> _codes = const [];
  String _query = '';

  /// The picture in the middle of each code (Bar, 01a1f4ba), by
  /// `id:choice`; null while none is drawn. Re-read when the choice moves.
  final Map<String, Uint8List?> _pictures = {};

  /// The codes the search keeps: name, the printed address and where it goes.
  List<QrCodeRow> get _shown {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return _codes;
    return [
      for (final code in _codes)
        if ([
          code.name,
          code.url,
          code.destinationUrl,
          code.active ? 'active' : 'deactivated',
        ].any((text) => text.toLowerCase().contains(needle)))
          code,
    ];
  }

  /// The code whose address is being changed, and the one asking to be
  /// deactivated. One at a time: a second card opening closes the first.
  String? _editing;
  String? _pausing;
  bool _saving = false;
  String? _fieldError;
  final _destination = TextEditingController();

  /// Absent `/me` answers NO.
  bool get _canEdit =>
      widget.session.me?.permissions.contains('marketing.edit') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
    // A code made or changed elsewhere: re-read quietly.
    _live = LiveChanges.of(widget.api).subscribe({'qr_codes'}, (_) {
      if (!_loading) return _load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _destination.dispose();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the codes on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _codes.isEmpty;
        _error = null;
      });
    }

    final response = await widget.api.get('/marketing/qr-codes');
    if (!mounted || (quiet && !response.ok)) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      if (response.ok) _codes = QrCodeRow.listFrom(response.body);
    });
    if (response.ok) await _loadPictures();
  }

  /// Fetches the centre picture of every code not yet read for its choice.
  Future<void> _loadPictures({bool again = false}) async {
    for (final code in _codes) {
      final key = code.pictureKey;
      if (code.centerImage == QrCodeRow.noPicture) continue;
      if (!again && _pictures.containsKey(key)) continue;
      final bytes = await widget.api.getBytes(
        '/marketing/qr-codes/${code.id}/center-image',
      );
      if (!mounted) return;
      setState(() {
        _pictures[key] = bytes != null && isPicture(bytes)
            ? Uint8List.fromList(bytes)
            : null;
      });
    }
  }

  Uint8List? _pictureOf(QrCodeRow code) =>
      code.centerImage == QrCodeRow.noPicture
      ? null
      : _pictures[code.pictureKey];

  /// Logo or nothing in the middle; the server redraws both downloads.
  Future<void> _setPicture(QrCodeRow code, String choice) async {
    final response = await widget.api.put('/marketing/qr-codes/${code.id}', {
      'center_image': choice,
    });
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    _pictures.remove('${code.id}:$choice');
    await _load(quiet: true);
  }

  /// Any picture for the middle: checked and kept by the server (2 MB).
  Future<void> _uploadPicture(QrCodeRow code) async {
    final picked = await Files.pickBytes(accept: 'image/*');
    if (picked == null || !mounted) return;
    final response = await widget.api.postFile(
      '/marketing/qr-codes/${code.id}/center-image',
      'file',
      picked.name,
      picked.bytes,
    );
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    _pictures.remove('${code.id}:${QrCodeRow.uploadedPicture}');
    await _load(quiet: true);
  }

  void _startChange(QrCodeRow code) => setState(() {
    _editing = code.id;
    _pausing = null;
    _fieldError = null;
    _destination.text = code.destinationUrl;
  });

  Future<void> _saveDestination(QrCodeRow code) async {
    setState(() {
      _saving = true;
      _fieldError = null;
    });

    final response = await widget.api.put('/marketing/qr-codes/${code.id}', {
      'destination_url': _destination.text.trim(),
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // The server's own words for a refused address ("Enter a full web
        // address starting with https://."), under the field.
        _fieldError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _editing = null;
    });
    await _load();
  }

  /// Pauses a code, or brings it back to where it went before.
  Future<void> _setActive(QrCodeRow code, {required bool active}) async {
    setState(() => _saving = true);
    // Each path whole, so the route guard can read it.
    final response = active
        ? await widget.api.post(
            '/marketing/qr-codes/${code.id}/reactivate',
            const {},
          )
        : await widget.api.post(
            '/marketing/qr-codes/${code.id}/deactivate',
            const {},
          );
    if (!mounted) return;

    setState(() {
      _saving = false;
      _pausing = null;
    });
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
    }
    await _load();
  }

  Future<void> _download(QrCodeRow code, String format) async {
    final bytes = await widget.api.getBytes(
      '/marketing/qr-codes/${code.id}/image',
      format == 'png' ? const {'format': 'png'} : null,
    );
    if (!mounted) return;

    if (bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not download that code.')),
      );
      return;
    }

    final slug = code.name
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+|-+$'), '');
    Files.download('${slug.isEmpty ? 'qr-code' : slug}.$format', bytes);
  }

  /// One code's numbers over 30 or 90 days (UI queue 63).
  Future<void> _openStats(QrCodeRow code) => showSidePanel<void>(
    context,
    builder: (context) => SidePanel(
      title: '${code.name} · stats',
      child: QrStatsPanel(
        api: widget.api,
        codeId: code.id,
        allTimeScans: code.scans,
        deactivatedAt: code.active ? null : code.deactivatedAt,
        formats: _formats,
      ),
    ),
  );

  Future<void> _create() async {
    final made = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'New QR code',
        child: _NewQrCode(api: widget.api),
      ),
    );

    if (made == true && mounted) await _load();
  }

  /// The link the code holds, on the clipboard: for an email, a text or a
  /// page where a printed code will not do.
  Future<void> _copy(QrCodeRow code) async {
    await Clipboard.setData(ClipboardData(text: code.url));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Link copied.')));
  }

  /// The code large enough to scan from the screen, where it sends people,
  /// and the link itself to follow: after a change, the way to see the
  /// printed copies now land on the new place (Rodrigo 01a1ef57).
  Future<void> _preview(QrCodeRow code) => showSidePanel<void>(
    context,
    builder: (panel) => StatefulBuilder(
      builder: (panel, refresh) {
        // The row as it stands now: a picture change re-reads the list.
        code = _codes.firstWhere((c) => c.id == code.id, orElse: () => code);
        final theme = Theme.of(panel);
        return SidePanel(
          key: const Key('qr-preview-panel'),
          title: code.name,
          subtitle: code.active ? 'Active' : 'Deactivated',
          actions: [
            OutlinedButton.icon(
              key: const Key('qr-preview-copy'),
              onPressed: () => _copy(code),
              icon: const Icon(Icons.copy, size: 18),
              label: const Text('Copy link'),
            ),
            FilledButton.icon(
              key: const Key('qr-preview-open'),
              onPressed: () => launchUrl(
                Uri.parse(code.url),
                mode: LaunchMode.externalApplication,
              ),
              icon: const Icon(Icons.open_in_new, size: 18),
              label: const Text('Test the link'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: EzBiziTheme.paper,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: EzBiziTheme.edge(panel)),
                  ),
                  child: QrWithPicture(
                    key: const Key('qr-preview-image'),
                    data: code.url,
                    size: 240,
                    picture: _pictureOf(code),
                  ),
                ),
              ),
              if (_canEdit) ...[
                const SizedBox(height: 16),
                Text(
                  'Picture in the middle',
                  style: theme.textTheme.labelMedium,
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final (choice, label) in const [
                      (QrCodeRow.logoPicture, 'Company logo'),
                      (QrCodeRow.noPicture, 'No picture'),
                    ])
                      ChoiceChip(
                        key: Key('qr-picture-$choice'),
                        label: Text(label),
                        selected: code.centerImage == choice,
                        onSelected: (_) async {
                          await _setPicture(code, choice);
                          refresh(() {});
                        },
                      ),
                    ActionChip(
                      key: const Key('qr-picture-upload'),
                      avatar: const Icon(Icons.upload, size: 16),
                      label: Text(
                        code.centerImage == QrCodeRow.uploadedPicture
                            ? 'Your picture · change'
                            : 'Upload a picture',
                      ),
                      onPressed: () async {
                        await _uploadPicture(code);
                        refresh(() {});
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  code.centerImage == QrCodeRow.logoPicture &&
                          _pictureOf(code) == null
                      ? 'No logo is set for this company yet, so the code has '
                            'no picture. Add one in Settings, or upload a picture.'
                      : 'Kept small, on white, so the code still scans. It is '
                            'in the SVG and PNG downloads too.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
              const SizedBox(height: 16),
              Text('Scanning it opens', style: theme.textTheme.labelMedium),
              SelectableText(
                code.destinationUrl,
                key: const Key('qr-preview-destination'),
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
              Text('The link in the code', style: theme.textTheme.labelMedium),
              SelectableText(code.url, style: theme.textTheme.bodySmall),
              const SizedBox(height: 8),
              Text(
                code.active
                    ? 'Test the link follows it the way a scan does, so it '
                          'lands where the code sends people now.'
                    : 'Deactivated: a scan lands on a page saying so until '
                          'it is reactivated.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        );
      },
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return ErrorRetry(_error!, onRetry: _load);
    }

    final narrow = MediaQuery.sizeOf(context).width < 600;
    final shown = _shown;

    return ListView(
      padding: EdgeInsets.all(narrow ? 16 : 24),
      children: [
        Row(
          key: const ValueKey('tour-marketing-qr-header'),
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('QR codes', style: theme.textTheme.titleMedium),
                  Text(
                    'Print one once. Change where it goes here, any time, '
                    'without reprinting.',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (!narrow) ...[
              _searchField(),
              if (_canEdit) const SizedBox(width: 8),
            ],
            if (_canEdit && !narrow)
              FilledButton.icon(
                key: const Key('qr-new'),
                onPressed: _create,
                icon: const Icon(Icons.add),
                label: const Text('New QR code'),
              ),
          ],
        ),
        if (narrow) ...[
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _searchField(),
              if (_canEdit)
                FilledButton.icon(
                  key: const Key('qr-new'),
                  onPressed: _create,
                  icon: const Icon(Icons.add),
                  label: const Text('New QR code'),
                ),
            ],
          ),
        ],
        const SizedBox(height: 16),
        if (_codes.isNotEmpty && shown.isEmpty)
          Text(
            'No QR codes match "${_query.trim()}".',
            style: theme.textTheme.bodyMedium,
          )
        else if (_codes.isEmpty)
          Text(
            key: const ValueKey('tour-marketing-qr-empty'),
            _canEdit
                ? 'No QR codes yet. Make one for a van, a sign or your cards: '
                      'where it sends people can change later without reprinting.'
                : 'No QR codes yet.',
            style: theme.textTheme.bodyMedium,
          ),
        // Rodrigo 01a1ef57: the genuine empty state offers the first one.
        if (_codes.isEmpty && _canEdit) ...[
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.icon(
              key: const Key('qr-create-empty'),
              onPressed: _create,
              icon: const Icon(Icons.qr_code_2),
              label: const Text('Create QR code'),
            ),
          ),
        ] else
          LayoutBuilder(
            key: const ValueKey('tour-marketing-qr-list'),
            builder: (context, box) {
              const gap = 12.0;
              final across = box.maxWidth >= 900 ? 2 : 1;

              // Cards side by side are one height whatever each holds, an
              // open Change box included.
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (var i = 0; i < shown.length; i += across) ...[
                    if (i > 0) const SizedBox(height: gap),
                    IntrinsicHeight(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          for (var j = i; j < i + across; j++) ...[
                            if (j > i) const SizedBox(width: gap),
                            Expanded(
                              child: j < shown.length
                                  ? _card(theme, shown[j], narrow)
                                  : const SizedBox(),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ],
              );
            },
          ),
        if (!_canEdit) ...[
          const SizedBox(height: 12),
          Text(
            'You can see the codes and download them, but not change them.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  // Standard field height, as tall as Leads search (Jose, 2026-10-27).
  Widget _searchField() => SizedBox(
    width: 240,
    child: ListSearch(
      key: const Key('qr-codes-search'),
      hint: 'Find a QR code',
      onChanged: (v) => setState(() => _query = v),
    ),
  );

  Widget _card(ThemeData theme, QrCodeRow code, bool narrow) {
    final editing = _editing == code.id;
    final pausing = _pausing == code.id;
    final paused = !code.active;
    final caution = ToneColours.of(context, Tone.caution);

    // Deactivate is in a menu, a step away (option 1): it pauses every
    // printed copy, so it is not a button beside the downloads.
    final more = _canEdit && !paused
        ? PopupMenuButton<String>(
            key: ValueKey('qr-more:${code.id}'),
            tooltip: 'More for “${code.name}”',
            onSelected: (_) => setState(() {
              _pausing = code.id;
              _editing = null;
            }),
            itemBuilder: (context) => [
              PopupMenuItem<String>(
                key: ValueKey('qr-deactivate:${code.id}'),
                value: 'deactivate',
                child: const Text('Deactivate'),
              ),
            ],
          )
        : null;

    return Card(
      key: ValueKey('qr:${code.id}'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // The permanent address, never the destination: this is the
            // image that is printed, so it is the image shown. Faded while
            // paused, as the printed copies are.
            Opacity(
              opacity: paused ? 0.35 : 1,
              child: Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: EzBiziTheme.paper,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: EzBiziTheme.edge(context)),
                ),
                // A fixed square: QrImageView sizes itself in a LayoutBuilder,
                // which cannot answer the row's IntrinsicHeight.
                child: QrWithPicture(
                  key: ValueKey('qr-image:${code.id}'),
                  data: code.url,
                  size: narrow ? 84 : 96,
                  picture: _pictureOf(code),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          code.name,
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: paused ? theme.disabledColor : null,
                          ),
                        ),
                      ),
                      if (paused)
                        StatusPill(
                          'Deactivated',
                          key: ValueKey('qr-paused:${code.id}'),
                        ),
                      // Beside the name on a phone, where the downloads
                      // leave it no room.
                      if (more != null && narrow) more,
                    ],
                  ),
                  const SizedBox(height: 4),
                  if (editing)
                    _changeBox(theme, code)
                  else
                    Wrap(
                      spacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text('Goes to', style: theme.textTheme.bodySmall),
                        Text(
                          code.destinationLabel,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        if (_canEdit)
                          TextButton(
                            key: ValueKey('qr-change:${code.id}'),
                            onPressed: () => _startChange(code),
                            child: Text(
                              narrow ? 'Change where it goes' : 'Change',
                            ),
                          ),
                      ],
                    ),
                  const SizedBox(height: 4),
                  Text(
                    code.monthLine(_formats) ?? code.scansLine(_formats),
                    key: ValueKey('qr-scans:${code.id}'),
                    style: theme.textTheme.bodySmall,
                  ),
                  // The last 30 days at a glance; Stats has the rest.
                  if (code.daily30d case final days? when days.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: QrDayStrip(
                        key: ValueKey('qr-strip:${code.id}'),
                        days: days,
                        pausedFrom: code.pausedIndex(days.length),
                      ),
                    ),
                  // What a scan does now, in Marketing's words for the page a
                  // paused code shows.
                  if (paused)
                    Text(
                      [
                        if (code.deactivatedAt case final at?)
                          'Paused since ${_formats.formatDate(at.toLocal())}.',
                        'Scanning it shows “This QR code is paused.” Scans '
                            'still count.',
                      ].join(' '),
                      key: ValueKey('qr-paused-line:${code.id}'),
                      style: theme.textTheme.bodySmall,
                    ),
                  const SizedBox(height: 8),
                  if (pausing)
                    Container(
                      key: ValueKey('qr-deactivate-ask:${code.id}'),
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: caution.fill,
                        borderRadius: BorderRadius.circular(
                          EzBiziTheme.controlRadius,
                        ),
                      ),
                      child: Wrap(
                        spacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            'Deactivate it? Anyone who scans a printed copy '
                            'sees “This QR code is paused.” until you '
                            'reactivate it.',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: caution.ink,
                            ),
                          ),
                          FilledButton(
                            key: ValueKey('qr-deactivate-yes:${code.id}'),
                            onPressed: _saving
                                ? null
                                : () => _setActive(code, active: false),
                            child: const Text('Deactivate'),
                          ),
                          TextButton(
                            onPressed: () => setState(() => _pausing = null),
                            child: const Text('Keep'),
                          ),
                        ],
                      ),
                    )
                  else
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        OutlinedButton.icon(
                          key: ValueKey('qr-preview:${code.id}'),
                          onPressed: () => _preview(code),
                          icon: const Icon(Icons.visibility_outlined, size: 16),
                          label: const Text('Preview'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('qr-copy:${code.id}'),
                          onPressed: () => _copy(code),
                          icon: const Icon(Icons.copy, size: 16),
                          label: const Text('Copy link'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('qr-svg:${code.id}'),
                          onPressed: () => _download(code, 'svg'),
                          icon: const Icon(Icons.download, size: 16),
                          label: const Text('SVG'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('qr-png:${code.id}'),
                          onPressed: () => _download(code, 'png'),
                          icon: const Icon(Icons.download, size: 16),
                          label: const Text('PNG'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('qr-stats:${code.id}'),
                          onPressed: () => _openStats(code),
                          icon: const Icon(Icons.insights_outlined, size: 16),
                          label: const Text('Scan history'),
                        ),
                        if (paused && _canEdit)
                          FilledButton.tonal(
                            key: ValueKey('qr-reactivate:${code.id}'),
                            onPressed: _saving
                                ? null
                                : () => _setActive(code, active: true),
                            child: const Text('Reactivate'),
                          ),
                        if (more != null && !narrow) more,
                      ],
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Where a code goes, changed on its card, with the one sentence that
  /// matters: the printed code does not change.
  Widget _changeBox(ThemeData theme, QrCodeRow code) {
    final colours = ToneColours.of(context, Tone.info);

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'The printed code stays the same; people who scan it go here '
            'instead.',
            style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
          ),
          const SizedBox(height: 8),
          TextField(
            key: ValueKey('qr-destination:${code.id}'),
            controller: _destination,
            autofocus: true,
            keyboardType: TextInputType.url,
            decoration: InputDecoration(
              isDense: true,
              hintText: 'https://',
              errorText: _fieldError,
              errorMaxLines: 2,
              border: const OutlineInputBorder(),
            ),
            onSubmitted: (_) => _saveDestination(code),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              FilledButton(
                key: ValueKey('qr-save:${code.id}'),
                onPressed: _saving ? null : () => _saveDestination(code),
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
              const SizedBox(width: 8),
              TextButton(
                onPressed: () => setState(() => _editing = null),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// The side panel that makes a code: what it is called and where it goes.
class _NewQrCode extends StatefulWidget {
  const _NewQrCode({required this.api});

  final ApiClient api;

  @override
  State<_NewQrCode> createState() => _NewQrCodeState();
}

class _NewQrCodeState extends State<_NewQrCode> {
  final _name = TextEditingController();
  final _destination = TextEditingController();
  bool _saving = false;
  String? _nameError;
  String? _destinationError;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _destination.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _nameError = null;
      _destinationError = null;
      _error = null;
    });

    final response = await widget.api.post('/marketing/qr-codes', {
      'name': _name.text.trim(),
      'destination_url': _destination.text.trim(),
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _nameError = _errorFor(response, 'name');
        _destinationError = _errorFor(response, 'destination_url');
        if (_nameError == null && _destinationError == null) {
          _error = response.message;
        }
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Name it for where it will be printed. Where it sends people can '
          'change later, without reprinting.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('qr-new-name'),
          controller: _name,
          decoration: InputDecoration(
            labelText: 'Name',
            hintText: 'Van 1, side door',
            errorText: _nameError,
            border: const OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('qr-new-destination'),
          controller: _destination,
          keyboardType: TextInputType.url,
          decoration: InputDecoration(
            labelText: 'Where it goes',
            hintText: 'https://',
            errorText: _destinationError,
            errorMaxLines: 2,
            border: const OutlineInputBorder(),
          ),
          onSubmitted: (_) => _save(),
        ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
        const SizedBox(height: 20),
        FilledButton(
          key: const Key('qr-new-save'),
          onPressed: _saving ? null : _save,
          child: Text(_saving ? 'Making it…' : 'Make the code'),
        ),
      ],
    );
  }
}

/// The server's sentence for one field of a 422, if it sent one.
String? _errorFor(ApiResponse response, String field) {
  final errors = response.body['errors'];
  final said = errors is Map ? errors[field] : null;
  if (said is List && said.isNotEmpty) return '${said.first}';
  return said is String ? said : null;
}

/// One code as GET /marketing/qr-codes sends it.
class QrCodeRow {
  const QrCodeRow({
    required this.id,
    required this.name,
    required this.url,
    required this.destinationUrl,
    required this.scans,
    this.lastScannedAt,
    this.active = true,
    this.deactivatedAt,
    this.scans30d,
    this.scanners30d,
    this.daily30d,
    this.centerImage = logoPicture,
  });

  /// The picture in the middle: the company logo (the default), none, or
  /// one uploaded.
  static const logoPicture = 'logo';
  static const noPicture = 'none';
  static const uploadedPicture = 'upload';
  final String centerImage;

  /// What the picture cache is keyed by: a new choice is a new picture.
  String get pictureKey => '$id:$centerImage';

  final String id;
  final String name;

  /// What the printed image encodes. It never changes.
  final String url;

  /// Where a scan is sent now.
  final String destinationUrl;
  final int scans;
  final DateTime? lastScannedAt;

  /// False while paused: a scan shows "This QR code is paused." until it is
  /// reactivated.
  final bool active;
  final DateTime? deactivatedAt;

  /// The last 30 days, when the server sends them: scans, daily scanners
  /// (one phone or browser a day, the days added up), and scans per day,
  /// oldest first, ending today. Null is "not sent", never zero.
  final int? scans30d;
  final int? scanners30d;
  final List<int>? daily30d;

  /// "128 scans · 96 daily scanners in 30 days · last 3 hours ago", or null
  /// when the server sent no 30-day figures.
  String? monthLine(DisplayFormats formats) {
    final scans = scans30d;
    if (scans == null) return null;
    final scanners = scanners30d ?? 0;
    final last = scansLine(formats).split(' · ').skip(1).join(' · ');
    return [
      '${scans == 1 ? '1 scan' : '$scans scans'} · '
          '$scanners daily ${scanners == 1 ? 'scanner' : 'scanners'} in 30 days',
      if (last.isNotEmpty) last,
    ].join(' · ');
  }

  /// Where the paused stretch starts in [count] days ending today, or null
  /// while the code is active (or was paused after the last of them).
  int? pausedIndex(int count, [DateTime? today]) {
    final at = deactivatedAt;
    if (active || at == null) return null;
    final day = DateUtils.dateOnly(at.toLocal());
    final now = DateUtils.dateOnly(today ?? DateTime.now());
    final index = count - 1 - now.difference(day).inDays;
    if (index >= count) return null;
    return index < 0 ? 0 : index;
  }

  /// The address without its scheme, as people say it.
  String get destinationLabel => destinationUrl
      .replaceFirst(RegExp(r'^https?://'), '')
      .replaceFirst(RegExp(r'/$'), '');

  String scansLine(DisplayFormats formats) {
    final count = scans == 1 ? '1 scan' : '$scans scans';
    final last = lastScannedAt;
    if (last == null) return scans == 0 ? 'No scans yet' : count;

    final ago = DateTime.now().difference(last);
    final when = ago.inMinutes < 1
        ? 'just now'
        : ago.inHours < 1
        ? '${ago.inMinutes} min ago'
        : ago.inDays < 1
        ? (ago.inHours == 1 ? '1 hour ago' : '${ago.inHours} hours ago')
        : ago.inDays == 1
        ? 'yesterday'
        : formats.formatDate(last.toLocal());

    return '$count · last $when';
  }

  static List<QrCodeRow> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        QrCodeRow(
          id: '${row['id']}',
          name: '${row['name'] ?? ''}',
          url: '${row['url'] ?? ''}',
          destinationUrl: '${row['destination_url'] ?? ''}',
          scans: row['scans'] is int ? row['scans'] as int : 0,
          lastScannedAt: row['last_scanned_at'] is String
              ? DateTime.tryParse(row['last_scanned_at'] as String)
              : null,
          // Only an explicit false pauses a code.
          active: row['active'] != false,
          deactivatedAt: row['deactivated_at'] is String
              ? DateTime.tryParse(row['deactivated_at'] as String)
              : null,
          scans30d: (row['scans_30d'] as num?)?.toInt(),
          scanners30d: (row['people_30d'] as num?)?.toInt(),
          daily30d: row['daily_30d'] is List
              ? [
                  for (final n in row['daily_30d'] as List)
                    (n as num?)?.toInt() ?? 0,
                ]
              : null,
          centerImage: row['center_image'] is String
              ? row['center_image'] as String
              : logoPicture,
        ),
    ];
  }
}

/// PNG, JPEG, GIF or WebP by their first bytes: what the server accepts,
/// and the guard against drawing an error page as a picture.
bool isPicture(List<int> b) =>
    (b.length > 8 && b[0] == 0x89 && b[1] == 0x50 && b[2] == 0x4E) ||
    (b.length > 3 && b[0] == 0xFF && b[1] == 0xD8) ||
    (b.length > 6 && b[0] == 0x47 && b[1] == 0x49 && b[2] == 0x46) ||
    (b.length > 12 &&
        b[8] == 0x57 &&
        b[9] == 0x45 &&
        b[10] == 0x42 &&
        b[11] == 0x50);

/// The code as printed: [picture] on a white pad in the middle, the same
/// proportions as the server's downloads (QrImage::PAD_RATIO, 22% of the
/// width; the picture 82% of the pad), at error correction H.
class QrWithPicture extends StatelessWidget {
  const QrWithPicture({
    super.key,
    required this.data,
    required this.size,
    this.picture,
  });

  static const padRatio = 0.22;
  static const pictureInPad = 0.82;

  final String data;
  final double size;
  final Uint8List? picture;

  @override
  Widget build(BuildContext context) {
    final bytes = picture;
    final pad = size * padRatio;
    // A fixed square: QrImageView sizes itself in a LayoutBuilder, which
    // cannot answer a row's IntrinsicHeight.
    return SizedBox.square(
      dimension: size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          QrImageView(
            data: data,
            version: QrVersions.auto,
            errorCorrectionLevel: QrErrorCorrectLevel.H,
            size: size,
            padding: EdgeInsets.zero,
            backgroundColor: EzBiziTheme.paper,
          ),
          if (bytes != null)
            Container(
              key: const Key('qr-center-picture'),
              width: pad,
              height: pad,
              color: EzBiziTheme.paper,
              alignment: Alignment.center,
              child: Image.memory(
                bytes,
                width: pad * pictureInPad,
                height: pad * pictureInPad,
                fit: BoxFit.contain,
                gaplessPlayback: true,
                errorBuilder: (_, _, _) => const SizedBox.shrink(),
              ),
            ),
        ],
      ),
    );
  }
}
