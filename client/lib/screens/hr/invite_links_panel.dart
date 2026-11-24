import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/files.dart';
import '../../core/hr.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// Shareable invite links (Jose, 2026-11-23: "an invite link in which they can
/// create their accounts on their own, I would choose which role and where to
/// assign them"). One link, a role and a place; anyone with it makes their own
/// account there. Make one, copy it or show its QR, extend it, switch it off,
/// and see who joined through it.
class InviteLinksPanel extends StatefulWidget {
  const InviteLinksPanel({
    super.key,
    required this.api,
    required this.options,
    this.formats = DisplayFormats.fallback,
  });

  final ApiClient api;
  final AssignmentOptions options;
  final DisplayFormats formats;

  @override
  State<InviteLinksPanel> createState() => _InviteLinksPanelState();
}

class _InviteLinksPanelState extends State<InviteLinksPanel> {
  List<Map<String, dynamic>>? _links;
  String? _error;
  String? _roleId;
  String? _scope;
  final _days = TextEditingController(text: '14');
  final _maxUses = TextEditingController();
  bool _busy = false;
  String? _qrFor;

  @override
  void initState() {
    super.initState();
    final places = widget.options.scopes.where((s) => s.isActive).toList();
    if (places.length == 1) _scope = places.first.value;
    _load();
  }

  @override
  void dispose() {
    _days.dispose();
    _maxUses.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/hr/invite-links');
    if (!mounted) return;
    setState(() {
      if (response.ok && response.data is List) {
        _links = [
          for (final row in response.data as List)
            if (row is Map) Map<String, dynamic>.from(row),
        ];
        _error = null;
      } else {
        _error = response.reason;
      }
    });
  }

  Future<void> _act(Future<ApiResponse> Function() call, String done) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await call();
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) _error = response.reason;
    });
    if (response.ok) {
      showFloatingSnack(context, done);
      await _load();
    }
  }

  Future<void> _create() {
    final parts = _scope!.split(':');
    final days = int.tryParse(_days.text.trim());
    final max = int.tryParse(_maxUses.text.trim());
    return _act(
      () => widget.api.post('/hr/invite-links', {
        'role_id': _roleId,
        'scope_type': parts.first,
        'scope_id': parts.sublist(1).join(':'),
        'expires_in_days': days,
        'max_uses': ?max,
      }),
      'Invite link made. Copy it or show its QR below.',
    );
  }

  String _placeName(Map<String, dynamic> link) {
    final value = '${link['scope_type']}:${link['scope_id']}';
    for (final scope in widget.options.scopes) {
      if (scope.value == value) return scope.pathLabel;
    }
    return '';
  }

  String? _date(String? iso) {
    final at = DateTime.tryParse(iso ?? '');
    return at == null ? null : widget.formats.formatDate(at.toLocal());
  }

  /// Who joined, each opening their HR record (the panel closes first).
  Widget _joinedRow(BuildContext context, Map j) {
    final theme = Theme.of(context);
    final id = j['user_id']?.toString();
    final name = '${j['name'] ?? 'Unnamed'}';
    final day = _date(j['joined_at'] as String?);
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(
        children: [
          Flexible(
            child: id == null
                ? Text(name, style: theme.textTheme.bodyMedium)
                : InkWell(
                    key: ValueKey('invite-link-joined-$id'),
                    onTap: () {
                      Navigator.of(context).maybePop();
                      DeepLinks.open(
                        DeepLink(module: 'hr', type: 'person', id: id),
                      );
                    },
                    child: Text(
                      name,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.primary,
                        decoration: TextDecoration.underline,
                        decorationColor: theme.colorScheme.primary,
                      ),
                    ),
                  ),
          ),
          if (day != null) ...[
            const SizedBox(width: 8),
            Text('joined $day', style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }

  Future<void> _download(String url, Map<String, dynamic> link) async {
    final image = await QrPainter(
      data: url,
      version: QrVersions.auto,
      gapless: true,
      // Printed dark on white, whatever the theme.
      eyeStyle: const QrEyeStyle(
        eyeShape: QrEyeShape.square,
        color: EzBiziTheme.paperInk,
      ),
      dataModuleStyle: const QrDataModuleStyle(
        dataModuleShape: QrDataModuleShape.square,
        color: EzBiziTheme.paperInk,
      ),
    ).toImageData(1024, format: ui.ImageByteFormat.png);
    if (!mounted || image == null) return;
    final slug = '${(link['role'] as Map?)?['name'] ?? ''} ${_placeName(link)}'
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+|-+$'), '');
    Files.download(
      'invite-link${slug.isEmpty ? '' : '-$slug'}.png',
      image.buffer.asUint8List(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final canCreate = !_busy && _roleId != null && _scope != null;

    return SidePanel(
      title: 'Invite links',
      subtitle:
          'One link many people can use to make their own account, in the role '
          'and place you choose. Paste it in WhatsApp or an email.',
      width: 560,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('New link', style: theme.textTheme.titleSmall),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _roleId,
            label: 'Role',
            helperText:
                'Administrator is never offered: anyone with the link '
                'would get it.',
            border: const OutlineInputBorder(),
            options: [
              for (final role in widget.options.roles)
                SearchOption(
                  value: role.id,
                  label: role.assignable
                      ? role.name
                      : '${role.name} — not yours to give',
                  enabled: role.assignable,
                ),
            ],
            onChanged: _busy ? null : (v) => setState(() => _roleId = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _scope,
            label: 'Where they are assigned',
            border: const OutlineInputBorder(),
            options: [
              for (final scope in widget.options.scopes)
                if (scope.isActive)
                  SearchOption(value: scope.value, label: scope.pathLabel),
            ],
            onChanged: _busy ? null : (v) => setState(() => _scope = v),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  key: const ValueKey('invite-link-days'),
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  controller: _days,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Expires after (days)',
                    helperText: 'Blank: open until you switch it off.',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  key: const ValueKey('invite-link-max'),
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  controller: _maxUses,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Most people who can join',
                    helperText: 'Blank: no limit.',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              key: const ValueKey('invite-link-create'),
              onPressed: canCreate ? _create : null,
              child: Text(_busy ? 'Working…' : 'Make the link'),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
          const Divider(height: 32),
          Text('Links', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (_links == null && _error == null)
            const LinearProgressIndicator(minHeight: 2)
          else if ((_links ?? const []).isEmpty)
            Text('No invite links yet.', style: theme.textTheme.bodySmall)
          else
            for (final link in _links!) _linkCard(context, link),
        ],
      ),
    );
  }

  Widget _linkCard(BuildContext context, Map<String, dynamic> link) {
    final theme = Theme.of(context);
    final open = link['open'] == true;
    final url = link['url'] as String? ?? '';
    final max = link['max_uses'];
    final joined = (link['joined'] as List? ?? const []).cast<Map>();
    final expires = link['expires_at'] as String?;

    return Card(
      key: ValueKey('invite-link-${link['id']}'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${(link['role'] as Map?)?['name'] ?? ''} · ${_placeName(link)}',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                StatusPill(
                  open
                      ? 'Open'
                      : link['revoked_at'] != null
                      ? 'Switched off'
                      : 'Closed',
                  tone: open ? Tone.ok : Tone.neutral,
                  dense: true,
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [
                'Used ${link['uses']}${max == null ? '' : ' of $max'}',
                if (_date(expires) case final day?)
                  '${open ? 'expires' : 'ended'} $day'
                else
                  'no expiry',
                if (link['created_by'] != null) 'made by ${link['created_by']}',
              ].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
            if (!open && link['closed_because'] != null)
              Text(
                link['closed_because'] as String,
                style: theme.textTheme.bodySmall,
              ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: url));
                    if (context.mounted) {
                      showFloatingSnack(context, 'Link copied.');
                    }
                  },
                  icon: const Icon(Icons.copy, size: 16),
                  label: const Text('Copy'),
                ),
                OutlinedButton.icon(
                  onPressed: () => setState(
                    () => _qrFor = _qrFor == link['id']
                        ? null
                        : link['id'] as String?,
                  ),
                  icon: const Icon(Icons.qr_code, size: 16),
                  label: const Text('QR'),
                ),
                if (_qrFor == link['id'])
                  OutlinedButton.icon(
                    key: ValueKey('invite-link-download-${link['id']}'),
                    onPressed: () => _download(url, link),
                    icon: const Icon(Icons.download_outlined, size: 16),
                    label: const Text('Download PNG'),
                  ),
                if (link['revoked_at'] == null)
                  OutlinedButton(
                    onPressed: _busy
                        ? null
                        : () => _act(
                            () => widget.api.patch(
                              '/hr/invite-links/${link['id']}',
                              {'expires_in_days': 14},
                            ),
                            'Open for another 14 days.',
                          ),
                    child: const Text('Extend 14 days'),
                  ),
                if (link['revoked_at'] == null)
                  OutlinedButton(
                    onPressed: _busy
                        ? null
                        : () => _act(
                            () => widget.api.delete(
                              '/hr/invite-links/${link['id']}',
                            ),
                            'Switched off. Nobody else can join through it.',
                          ),
                    child: const Text('Switch off'),
                  ),
              ],
            ),
            if (_qrFor == link['id'])
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Center(
                  child: Container(
                    color: EzBiziTheme.paper,
                    padding: const EdgeInsets.all(8),
                    child: QrImageView(
                      data: url,
                      size: 180,
                      eyeStyle: const QrEyeStyle(
                        eyeShape: QrEyeShape.square,
                        color: EzBiziTheme.paperInk,
                      ),
                      dataModuleStyle: const QrDataModuleStyle(
                        dataModuleShape: QrDataModuleShape.square,
                        color: EzBiziTheme.paperInk,
                      ),
                    ),
                  ),
                ),
              ),
            if (joined.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('Joined', style: theme.textTheme.labelMedium),
              for (final j in joined) _joinedRow(context, j),
            ],
          ],
        ),
      ),
    );
  }
}
