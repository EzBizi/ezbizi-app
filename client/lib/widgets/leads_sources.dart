import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import 'error_note.dart';
import 'search_picker.dart';
import 'side_panel.dart';
import 'status_pill.dart';

/// Leads › Manage › Connections: the sources beyond Google Ads and Thumbtack
/// (design 156, options 1 + 4).
///
/// Read from `GET /leads/sources` (server 6aff39145). Every field name it
/// carries is read in [parseLeadSources] and nowhere else, so a renamed field
/// changes one place. A key is absent when the role cannot see that source.
/// Writes go to `/web/sites/{id}`, each gated by the site's own flag.

/// One source in the list: Website, WhatsApp, Facebook / Messenger, Instagram
/// or Google Business Profile.
class LeadSource {
  const LeadSource({
    required this.key,
    required this.count,
    required this.needsAttention,
    required this.items,
  });

  /// `website`, `whatsapp`, `facebook`, `instagram`, `google_business_profile`.
  final String key;
  final int count;
  final bool needsAttention;

  /// Sites for `website`; numbers, pages or listings for the rest.
  final List<SourceItem> items;
}

/// A site, a number, a page or a listing.
class SourceItem {
  const SourceItem({
    required this.id,
    required this.title,
    this.detail,
    this.company,
    this.branch,
    this.branchId,
    this.status,
    this.needsAttention = false,
    this.isPrimary = false,
    this.pluginVersion,
    this.pluginBehind = false,
    this.lastSeenAt,
    this.chatEnabled,
    this.bookingEnabled,
    this.lastLeadAt,
    this.leads,
    this.mayEdit = false,
    this.mayEditChat = false,
    this.maySetPrimary = false,
  });

  final String id;

  /// The host for a site, the name for the rest.
  final String title;

  /// A site's own label; a number or handle for the rest.
  final String? detail;
  final String? company;
  final String? branch;
  final String? branchId;
  final String? status;
  final bool needsAttention;

  // Website only.
  final bool isPrimary;
  final String? pluginVersion;
  final bool pluginBehind;
  final String? lastSeenAt;

  /// Null is unknown: the plugin has not said.
  final bool? chatEnabled;
  final bool? bookingEnabled;
  final String? lastLeadAt;

  /// Null where the source brings no leads (Business Profile).
  final int? leads;

  /// A site's own permissions: the branch picker, the Chat switch and
  /// Make primary. False draws the value as text.
  final bool mayEdit;
  final bool mayEditChat;
  final bool maySetPrimary;

  /// "Company · Branch", or whichever half is known.
  String get place => [
    if ((company ?? '').isNotEmpty) company!,
    if ((branch ?? '').isNotEmpty) branch!,
  ].join(' · ');
}

/// THE mapper: `GET /leads/sources` to [LeadSource]s, in the list's order.
/// Nothing else reads the response's field names.
List<LeadSource> parseLeadSources(Object? body) {
  String? s(Object? v) => v == null || '$v'.isEmpty ? null : '$v';
  int? n(Object? v) => (v as num?)?.toInt();
  bool? b(Object? v) => v is bool ? v : null;
  List<Map> list(Object? v) =>
      (v is List ? v : const []).whereType<Map>().toList();

  final data = body is Map ? body['data'] : null;
  if (data is! Map) return const [];

  bool ok(String? status) =>
      status == null ||
      const {'connected', 'active', 'mapped', 'verified'}.contains(status);

  final sources = <LeadSource>[];

  final web = data['web'];
  if (web is Map) {
    final sites = [
      for (final m in list(web['sites']))
        SourceItem(
          id: '${m['id']}',
          title: s(m['host']) ?? '${m['id']}',
          detail: s(m['label']),
          company: s((m['company'] as Map?)?['name']),
          branch: s((m['franchise'] as Map?)?['name']),
          branchId: s((m['franchise'] as Map?)?['id']),
          isPrimary: m['is_primary'] == true,
          pluginVersion: s(m['plugin_version']),
          pluginBehind: m['outdated'] == true,
          lastSeenAt: s(m['last_seen_at']),
          chatEnabled: b(m['chat_on']),
          bookingEnabled: b(m['booking_on']),
          lastLeadAt: s(m['last_lead_at']),
          leads: n(m['leads']) ?? 0,
          mayEdit: m['may_edit'] == true,
          mayEditChat: m['may_edit_chat'] == true,
          maySetPrimary: m['may_set_primary'] == true,
        ),
    ];
    sources.add(
      LeadSource(
        key: 'website',
        count: sites.length,
        needsAttention: sites.any((i) => i.pluginBehind),
        items: sites,
      ),
    );
  }

  for (final key in const [
    'whatsapp',
    'facebook',
    'instagram',
    'google_business_profile',
  ]) {
    if (!data.containsKey(key)) continue;
    final gbp = key == 'google_business_profile';
    final items = [
      for (final m in list(data[key]))
        SourceItem(
          id: '${m['id']}',
          title: s(m['name']) ?? s(m['number']) ?? '${m['id']}',
          detail: s(m['name']) == null
              ? null
              : s(m['number']) ?? s(m['handle']),
          status:
              s(m['status']) ??
              (gbp && m['mapped'] != null
                  ? (m['mapped'] == true ? 'mapped' : 'not_mapped')
                  : null),
          needsAttention: !ok(s(m['status'])),
          lastLeadAt: s(m['last_lead_at']),
          leads: gbp ? null : n(m['leads']) ?? 0,
        ),
    ];
    sources.add(
      LeadSource(
        key: key,
        count: items.length,
        needsAttention: items.any((i) => i.needsAttention),
        items: items,
      ),
    );
  }

  return sources;
}

/// Google said so in 2024; the line stays until a listing can bring a lead.
const gbpNoChatLine =
    'Google ended Business Profile chat in 2024, so listings bring no leads '
    'here.';

bool _matches(SourceItem i, String query) =>
    query.isEmpty ||
    [
      i.title,
      i.detail ?? '',
      i.place,
      i.status ?? '',
    ].join(' ').toLowerCase().contains(query);

Widget _searchField(
  TextEditingController controller,
  String key,
  String hint,
) => Padding(
  padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
  child: TextField(
    key: ValueKey(key),
    controller: controller,
    decoration: InputDecoration(
      isDense: true,
      prefixIcon: const Icon(Icons.search),
      hintText: hint,
    ),
  ),
);

String _stamp(Session session, String? iso, String fallback) => formatListStamp(
  iso,
  fallback,
  formats: session.me?.display ?? DisplayFormats.platform,
);

// ── Website ──────────────────────────────────────────────────────────────

/// The sites: a table on a desk, a list of cards on a phone.
class LeadsWebsitePane extends StatelessWidget {
  const LeadsWebsitePane({
    super.key,
    required this.source,
    required this.session,
    required this.search,
    required this.query,
    required this.onOpen,
    this.openId,
    this.table = true,
  });

  final LeadSource source;
  final Session session;
  final TextEditingController search;
  final String query;
  final ValueChanged<SourceItem> onOpen;
  final String? openId;
  final bool table;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final shown = [
      for (final i in source.items)
        if (_matches(i, query)) i,
    ];

    Widget chip(String text, Tone tone) => Padding(
      padding: const EdgeInsets.only(right: 4),
      child: StatusPill(text, tone: tone),
    );

    Widget flag(String name, bool? on) => on == null
        ? chip('$name unknown', Tone.caution)
        : chip(on ? name : '$name off', on ? Tone.ok : Tone.neutral);

    Widget widgets(SourceItem i) => Wrap(
      children: [
        flag('Chat', i.chatEnabled),
        flag('Booking', i.bookingEnabled),
      ],
    );

    Widget plugin(SourceItem i) => Wrap(
      children: [
        if (i.pluginVersion != null)
          chip(i.pluginVersion!, i.pluginBehind ? Tone.caution : Tone.ok),
        if (i.pluginBehind)
          KeyedSubtree(
            key: ValueKey('site-update-${i.id}'),
            child: chip('update', Tone.caution),
          ),
      ],
    );

    Widget host(SourceItem i) => Row(
      children: [
        Flexible(
          child: Text(
            i.title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        if (i.isPrimary) ...[
          const SizedBox(width: 6),
          const StatusPill('Primary'),
        ],
      ],
    );

    final Widget body;
    if (source.items.isEmpty) {
      body = Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No site sends leads yet. Chat and form enquiries arrive through the '
          'EzBizi WordPress plugin on your site.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    } else if (shown.isEmpty) {
      body = Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No site matches “${search.text.trim()}”. Clear the box to see all '
          '${source.items.length} again.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    } else if (table) {
      final head = theme.textTheme.labelSmall?.copyWith(color: muted);
      Widget cells(List<Widget> c) => Row(
        children: [
          Expanded(flex: 5, child: c[0]),
          Expanded(flex: 4, child: c[1]),
          Expanded(flex: 4, child: c[2]),
          Expanded(flex: 3, child: c[3]),
          Expanded(flex: 2, child: c[4]),
          SizedBox(width: 64, child: c[5]),
        ],
      );
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: cells([
              Text('SITE', style: head),
              Text('COMPANY · BRANCH', style: head),
              Text('WIDGETS', style: head),
              Text('PLUGIN', style: head),
              Text('LAST LEAD', style: head),
              Text('LEADS', style: head, textAlign: TextAlign.end),
            ]),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView.builder(
              itemCount: shown.length,
              itemBuilder: (context, n) {
                final i = shown[n];
                return Material(
                  color: i.id == openId
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surface,
                  child: InkWell(
                    key: ValueKey('site-${i.id}'),
                    onTap: () => onOpen(i),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 10,
                      ),
                      child: cells([
                        host(i),
                        Text(
                          i.place,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        widgets(i),
                        plugin(i),
                        Text(_stamp(session, i.lastLeadAt, '—')),
                        Text('${i.leads ?? 0}', textAlign: TextAlign.end),
                      ]),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      );
    } else {
      body = ListView.builder(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        itemCount: shown.length,
        itemBuilder: (context, n) {
          final i = shown[n];
          return Card(
            child: InkWell(
              key: ValueKey('site-${i.id}'),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              onTap: () => onOpen(i),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    host(i),
                    const SizedBox(height: 2),
                    Text(
                      [
                        if (i.place.isNotEmpty) i.place,
                        '${i.leads ?? 0} leads',
                        if (i.lastLeadAt != null)
                          _stamp(session, i.lastLeadAt, ''),
                      ].join(' · '),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                    const SizedBox(height: 6),
                    Wrap(children: [widgets(i), plugin(i)]),
                  ],
                ),
              ),
            ),
          );
        },
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _searchField(search, 'website-search', 'Host, company or branch'),
        Expanded(child: body),
      ],
    );
  }
}

/// One site: its widgets, where its leads go, primary, and when it was last
/// seen. Docked beside the table on a desk, in place of it on a phone.
///
/// Each control is drawn only when the site's own flag allows it
/// (`may_edit`, `may_edit_chat`, `may_set_primary`); otherwise its value is
/// plain text. Every save reloads, because primary moves between sites.
class LeadsWebsitePanel extends StatefulWidget {
  const LeadsWebsitePanel({
    super.key,
    required this.api,
    required this.session,
    required this.site,
    required this.onChanged,
    required this.onClose,
    this.inPlace = false,
  });

  final ApiClient api;
  final Session session;
  final SourceItem site;
  final Future<void> Function() onChanged;
  final VoidCallback onClose;
  final bool inPlace;

  @override
  State<LeadsWebsitePanel> createState() => _LeadsWebsitePanelState();
}

class _LeadsWebsitePanelState extends State<LeadsWebsitePanel> {
  bool _busy = false;
  String? _error;

  String get _path => '/web/sites/${widget.site.id}';

  Future<void> _run(Future<ApiResponse> Function() write) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await write();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = response.ok ? null : response.message;
    });
    if (response.ok) await widget.onChanged();
  }

  /// The whole widgets config goes back with only chat's flag changed: the
  /// PUT replaces it, so a partial one would wipe the rest.
  Future<void> _setChat(bool on) => _run(() async {
    final read = await widget.api.get('$_path/widgets');
    if (!read.ok) return read;
    final data = read.body['data'];
    final config = Map<String, dynamic>.from(
      (data is Map ? data['config'] : null) as Map? ?? const {},
    );
    config['chat'] = {
      ...Map<String, dynamic>.from(config['chat'] as Map? ?? const {}),
      'enabled': on,
    };
    return widget.api.put('$_path/widgets', {'config': config});
  });

  Future<void> _place(String id) =>
      _run(() => widget.api.patch(_path, {'franchise_id': id}));

  Future<void> _makePrimary() =>
      _run(() => widget.api.post('$_path/primary', const {}));

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final site = widget.site;
    final me = widget.session.me;
    final branches = me == null
        ? const <BranchOption>[]
        : branchOptionsInView(me);

    Widget label(String text) => Padding(
      padding: const EdgeInsets.only(top: 18, bottom: 6),
      child: Text(
        text,
        style: theme.textTheme.labelMedium?.copyWith(color: muted),
      ),
    );

    String onOff(bool? on) => on == null ? 'unknown' : (on ? 'on' : 'off');

    return SidePanel(
      key: const ValueKey('website-panel'),
      title: site.title,
      subtitle: [
        'Last seen ${_stamp(widget.session, site.lastSeenAt, 'never')}',
        if (site.pluginVersion != null)
          'plugin ${site.pluginVersion}'
              '${site.pluginBehind ? ' (update available)' : ''}',
      ].join(' · '),
      docked: true,
      onBack: widget.inPlace ? widget.onClose : null,
      onClose: widget.inPlace ? null : widget.onClose,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (site.detail != null) Text(site.detail!),
          label('Widgets'),
          if (site.mayEditChat)
            SwitchListTile(
              key: const ValueKey('site-chat'),
              contentPadding: EdgeInsets.zero,
              title: const Text('Chat'),
              subtitle: site.chatEnabled == null ? const Text('Unknown') : null,
              value: site.chatEnabled ?? false,
              onChanged: _busy ? null : _setChat,
            )
          else
            Text(
              'Chat ${onOff(site.chatEnabled)}',
              key: const ValueKey('site-chat-text'),
            ),
          const SizedBox(height: 4),
          Text('Booking ${onOff(site.bookingEnabled)}'),
          Text(
            'Booking is a block on the site\'s pages.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          label('Leads go to'),
          if (site.mayEdit)
            SearchPickerField<String>(
              key: const ValueKey('site-branch'),
              label: 'Company · Branch',
              value: site.branchId,
              options: [
                for (final b in branches)
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: _busy
                  ? null
                  : (id) {
                      if (id != null) _place(id);
                    },
            )
          else
            Text(site.place.isEmpty ? 'Not set' : site.place),
          const SizedBox(height: 12),
          if (site.isPrimary)
            const Text('Primary site for this company')
          else if (site.maySetPrimary)
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton(
                key: const ValueKey('site-make-primary'),
                onPressed: _busy ? null : _makePrimary,
                child: const Text('Make primary'),
              ),
            ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}

// ── WhatsApp, Facebook, Instagram, Google Business Profile ──────────────

/// A source's numbers, pages or listings with their status. Read-only here:
/// each is connected and managed on Integrations.
class LeadsSourceItemsPane extends StatelessWidget {
  const LeadsSourceItemsPane({
    super.key,
    required this.source,
    required this.search,
    required this.query,
  });

  final LeadSource source;
  final TextEditingController search;
  final String query;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final gbp = source.key == 'google_business_profile';
    final shown = [
      for (final i in source.items)
        if (_matches(i, query)) i,
    ];

    Tone tone(SourceItem i) {
      if (i.needsAttention) return Tone.caution;
      return switch (i.status) {
        'connected' || 'active' || 'mapped' || 'verified' => Tone.ok,
        _ => Tone.neutral,
      };
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _searchField(search, 'source-search', 'Name, number or branch'),
        if (gbp)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Text(
              gbpNoChatLine,
              key: const ValueKey('gbp-no-chat'),
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
        Expanded(
          child: source.items.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    'Nothing connected yet.',
                    style: theme.textTheme.bodyMedium,
                  ),
                )
              : shown.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    'Nothing matches “${search.text.trim()}”.',
                    style: theme.textTheme.bodyMedium,
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  itemCount: shown.length,
                  itemBuilder: (context, n) {
                    final i = shown[n];
                    final second = [
                      if (i.detail != null) i.detail!,
                      if (i.place.isNotEmpty) i.place,
                    ].join(' · ');
                    return ListTile(
                      key: ValueKey('source-item-${i.id}'),
                      title: Text(i.title),
                      subtitle: second.isEmpty ? null : Text(second),
                      trailing: i.status == null
                          ? null
                          : StatusPill(
                              i.status![0].toUpperCase() +
                                  i.status!.substring(1).replaceAll('_', ' '),
                              tone: tone(i),
                            ),
                    );
                  },
                ),
        ),
      ],
    );
  }
}
