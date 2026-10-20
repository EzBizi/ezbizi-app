import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/job_money.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tag.dart';

/// What Workiz v2 knows about a job beyond its money summary (UI queue 58;
/// Jose: "as recommended", desktop 1 and phone 4, relayed by Jobs).
///
/// Three pieces for the job panel's three tabs: Workiz's own fields as facts
/// under the status ([WorkizFacts]), the invoice, payments, Workiz's own
/// estimates and time on site under the money card ([WorkizMoneyDetail]), and
/// the job's photos and files ([WorkizFiles]). All read only, from the
/// server's `data.workiz` (WorkizExtras): Workiz is where A-1's invoices are
/// kept, and every piece here says so.

/// `data.workiz`, read safely.
class WorkizExtras {
  const WorkizExtras._(this._map);

  final Map<String, dynamic> _map;

  /// Null for a job Workiz does not have.
  static WorkizExtras? of(Object? raw) =>
      raw is Map ? WorkizExtras._(Map<String, dynamic>.from(raw)) : null;

  List<Map<String, dynamic>> _list(String key) => [
    for (final row in (_map[key] as List? ?? const []))
      if (row is Map) Map<String, dynamic>.from(row),
  ];

  /// Workiz's custom fields, in its own names.
  List<({String name, String value})> get fields => [
    for (final f in _list('fields'))
      if ('${f['name'] ?? ''}'.trim().isNotEmpty &&
          '${f['value'] ?? ''}'.trim().isNotEmpty)
        (name: '${f['name']}'.trim(), value: '${f['value']}'.trim()),
  ];

  /// Photos and files, https links on Workiz's host.
  List<({String name, String url})> get files => [
    for (final f in _list('files'))
      if ('${f['url'] ?? ''}'.startsWith('https://'))
        (name: '${f['name'] ?? ''}', url: '${f['url']}'),
  ];

  /// Present only for a reader with Jobs Financials — the server leaves the
  /// keys out otherwise, so their absence is the permission, not "none".
  bool get hasMoney => _map.containsKey('invoices');

  String get currency => '${_map['currency'] ?? 'USD'}';
  List<Map<String, dynamic>> get invoices => _list('invoices');
  List<Map<String, dynamic>> get payments => _list('payments');
  List<Map<String, dynamic>> get estimates => _list('estimates');
  List<Map<String, dynamic>> get timesheets => _list('timesheets');
}

/// A file that the browser can draw as a picture.
bool isPicture(String name, String url) {
  final probe = (name.contains('.') ? name : Uri.tryParse(url)?.path ?? '')
      .toLowerCase();
  return const ['.jpg', '.jpeg', '.png', '.gif', '.webp'].any(probe.endsWith);
}

Future<void> _open(String url) =>
    launchUrl(Uri.parse(url), webOnlyWindowName: '_blank');

/// The small grey heading every Workiz piece carries, with its read-only mark.
class _Heading extends StatelessWidget {
  const _Heading(this.title, {this.tag = true});

  final String title;

  /// Once per tab is enough to say where it all comes from.
  final bool tag;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        Expanded(
          child: Text(
            title.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        if (tag) const Tag('From Workiz · read only'),
      ],
    );
  }
}

/// Workiz's fields as facts, two to a row: what the job IS, before anything
/// to edit.
class WorkizFacts extends StatelessWidget {
  const WorkizFacts({super.key, required this.fields});

  final List<({String name, String value})> fields;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return Container(
      key: const Key('job-workiz-facts'),
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'From Workiz',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const Tag('read only'),
            ],
          ),
          const SizedBox(height: 8),
          LayoutBuilder(
            builder: (context, box) {
              final across = box.maxWidth >= 360 ? 2 : 1;
              final width = (box.maxWidth - 14 * (across - 1)) / across;
              return Wrap(
                spacing: 14,
                runSpacing: 8,
                children: [
                  for (final f in fields)
                    SizedBox(
                      width: width,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            f.name.toUpperCase(),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: muted,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          Text(
                            f.value,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: theme.colorScheme.onSurface,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              );
            },
          ),
        ],
      ),
    );
  }
}

/// What makes the money card up: the invoice and the payments on one dated
/// line, Workiz's own estimates with their status, and time on site.
class WorkizMoneyDetail extends StatelessWidget {
  const WorkizMoneyDetail({
    super.key,
    required this.extras,
    required this.formats,
  });

  final WorkizExtras extras;
  final DisplayFormats formats;

  String _money(Object? minor) =>
      formatMoney((minor as num?)?.toInt() ?? 0, currency: extras.currency);

  String _day(Object? ymd) {
    final at = DateTime.tryParse('${ymd ?? ''}');
    return at == null ? '' : formats.formatDate(at);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Invoices and payments in the order they happened. An invoice sorts
    // before a payment made the same day: it is what the payment pays.
    final events =
        <(String, int, Map<String, dynamic>)>[
          for (final i in extras.invoices)
            ('${i['issued_on'] ?? ''}', 0, {...i, '_kind': 'invoice'}),
          for (final p in extras.payments)
            ('${p['paid_on'] ?? ''}', 1, {...p, '_kind': 'payment'}),
        ]..sort((a, b) {
          final byDay = a.$1.compareTo(b.$1);
          return byDay != 0 ? byDay : a.$2.compareTo(b.$2);
        });

    return Column(
      key: const Key('job-workiz-money'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (events.isNotEmpty) ...[
          const _Heading('Invoice and payments'),
          const SizedBox(height: 6),
          for (final e in events) _event(context, e.$3),
          const SizedBox(height: 14),
        ],
        if (extras.estimates.isNotEmpty) ...[
          _Heading('Workiz estimates', tag: events.isEmpty),
          const SizedBox(height: 6),
          for (final e in extras.estimates)
            _row(
              context,
              key: ValueKey('workiz-estimate-${e['number']}'),
              lead: null,
              text: [
                if (e['number'] != null) '#${e['number']}',
                if (_day(e['dated_on']).isNotEmpty) _day(e['dated_on']),
              ].join(' · '),
              pill: StatusPill(
                '${e['status'] ?? 'Unknown'}',
                tone: estimateTone('${e['status']}'),
              ),
              amount: _money(e['total_minor']),
            ),
          const SizedBox(height: 14),
        ],
        if (extras.timesheets.isNotEmpty) ...[
          _Heading(
            'Time on site',
            tag: events.isEmpty && extras.estimates.isEmpty,
          ),
          const SizedBox(height: 6),
          for (final t in extras.timesheets) _timesheet(context, t),
        ],
        if (events.isEmpty &&
            extras.estimates.isEmpty &&
            extras.timesheets.isEmpty)
          Text(
            'Workiz has no invoice, payment or estimate on this job yet.',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  Widget _event(BuildContext context, Map<String, dynamic> e) {
    if (e['_kind'] == 'invoice') {
      final notes = [
        if (((e['tax_minor'] as num?) ?? 0) > 0)
          'tax ${_money(e['tax_minor'])}',
        if (((e['tip_minor'] as num?) ?? 0) > 0)
          'tip ${_money(e['tip_minor'])}',
        if (((e['due_minor'] as num?) ?? 0) > 0)
          '${_money(e['due_minor'])} due${_day(e['due_on']).isEmpty ? '' : ' by ${_day(e['due_on'])}'}',
      ];
      return _row(
        context,
        key: ValueKey('workiz-invoice-${e['number']}'),
        lead: _day(e['issued_on']),
        dot: Theme.of(context).colorScheme.primary,
        text: [
          'Invoice${e['number'] == null ? '' : ' #${e['number']}'}',
          ...notes,
        ].join(' · '),
        amount: _money(e['total_minor']),
      );
    }

    final refund = e['kind'] == 'refund';
    final pending = e['status'] == 'pending';
    final amount = _money(e['amount_minor']);
    return _row(
      context,
      key: ValueKey('workiz-payment-${e['paid_on']}-${e['amount_minor']}'),
      lead: _day(e['paid_on']),
      dot: refund
          ? ToneColours.of(context, Tone.bad).ink
          : pending
          ? ToneColours.of(context, Tone.caution).ink
          : ToneColours.of(context, Tone.ok).ink,
      text: [
        refund ? 'Refunded' : 'Paid',
        if (pending) 'pending',
        if (e['collected_by'] != null) '${e['collected_by']}',
      ].join(' · '),
      amount: refund ? '−$amount' : amount,
    );
  }

  Widget _timesheet(BuildContext context, Map<String, dynamic> t) {
    final from = DateTime.tryParse('${t['starts_at'] ?? ''}')?.toLocal();
    final to = DateTime.tryParse('${t['ends_at'] ?? ''}')?.toLocal();
    final span = from == null
        ? ''
        : '${formats.formatDate(from)} ${formats.formatTime(from.hour, from.minute)}'
              '${to == null ? '' : ' – ${formats.formatTime(to.hour, to.minute)}'}';
    final minutes = from == null || to == null
        ? null
        : to.difference(from).inMinutes;
    final length = minutes == null || minutes < 0
        ? null
        : minutes >= 60
        ? '${minutes ~/ 60} h ${minutes % 60} m'
        : '$minutes m';

    return _row(
      context,
      key: ValueKey('workiz-timesheet-${t['starts_at']}'),
      lead: null,
      text: [
        '${t['who'] ?? 'Somebody'}',
        if (span.isNotEmpty) span,
        ?length,
      ].join(' · '),
      amount: _money(t['labor_cost_minor']),
    );
  }

  Widget _row(
    BuildContext context, {
    required Key key,
    required String? lead,
    required String text,
    required String amount,
    Color? dot,
    Widget? pill,
  }) {
    final theme = Theme.of(context);
    return Padding(
      key: key,
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          if (lead != null)
            SizedBox(
              width: 86,
              child: Text(lead, style: theme.textTheme.bodySmall),
            ),
          if (dot != null) ...[
            Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
            ),
            const SizedBox(width: 10),
          ],
          Expanded(child: Text(text, style: theme.textTheme.bodyMedium)),
          if (pill != null) ...[pill, const SizedBox(width: 10)],
          Text(
            amount,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w800,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

/// Workiz's own estimate words, in colour: won is good, pending is waiting,
/// declined is lost, and the rest are nothing to act on.
Tone estimateTone(String status) => switch (status.toLowerCase()) {
  'won' || 'approved' => Tone.ok,
  'pending' || 'unsent' => Tone.caution,
  'declined' => Tone.bad,
  _ => Tone.neutral,
};

/// The job's photos as a grid, and its other files (inspection reports) as
/// rows that open in a new tab. A photo opens large in place.
class WorkizFiles extends StatefulWidget {
  const WorkizFiles({super.key, required this.files, this.across});

  final List<({String name, String url})> files;

  /// Pictures per row; null picks by width.
  final int? across;

  @override
  State<WorkizFiles> createState() => _WorkizFilesState();
}

class _WorkizFilesState extends State<WorkizFiles> {
  /// The picture open large, by its place among the pictures.
  int? _viewing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pictures = [
      for (final f in widget.files)
        if (isPicture(f.name, f.url)) f,
    ];
    final others = [
      for (final f in widget.files)
        if (!isPicture(f.name, f.url)) f,
    ];

    final viewing = _viewing;
    if (viewing != null && viewing < pictures.length) {
      final picture = pictures[viewing];
      return Column(
        key: const Key('job-photo-viewer'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              TextButton.icon(
                onPressed: () => setState(() => _viewing = null),
                icon: const Icon(Icons.arrow_back),
                label: const Text('All photos'),
              ),
              const Spacer(),
              Text(
                '${viewing + 1} of ${pictures.length}',
                style: theme.textTheme.bodySmall,
              ),
              IconButton(
                tooltip: 'Previous photo',
                onPressed: viewing == 0
                    ? null
                    : () => setState(() => _viewing = viewing - 1),
                icon: const Icon(Icons.chevron_left),
              ),
              IconButton(
                tooltip: 'Next photo',
                onPressed: viewing >= pictures.length - 1
                    ? null
                    : () => setState(() => _viewing = viewing + 1),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: _Picture(
              url: picture.url,
              name: picture.name,
              decodeWidth: 1600,
              fit: BoxFit.contain,
            ),
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Text(picture.name, style: theme.textTheme.bodySmall),
              ),
              TextButton(
                onPressed: () => _open(picture.url),
                child: const Text('Open the original'),
              ),
            ],
          ),
        ],
      );
    }

    return Column(
      key: const Key('job-workiz-files'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Under the job's own (queue 119): what is still only in Workiz.
        Wrap(
          spacing: 10,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              'From Workiz · read-only · ${widget.files.length}'.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: FontWeight.w800,
                letterSpacing: 0.6,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            Text(
              "They'll move here once they're copied in.",
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (pictures.isNotEmpty)
          LayoutBuilder(
            builder: (context, box) {
              const gap = 6.0;
              final across = widget.across ?? (box.maxWidth >= 420 ? 4 : 3);
              final side = (box.maxWidth - gap * (across - 1)) / across;
              // ponytail: every tile is built, so every photo is fetched when
              // the tab opens: about eight full-size pictures on the jobs that
              // have any. A thumbnail proxy on our side is the upgrade if that
              // ever costs too much (Jobs, 2026-10-06).
              return Wrap(
                spacing: gap,
                runSpacing: gap,
                children: [
                  for (var i = 0; i < pictures.length; i++)
                    SizedBox.square(
                      dimension: side,
                      child: Material(
                        clipBehavior: Clip.antiAlias,
                        borderRadius: BorderRadius.circular(8),
                        child: InkWell(
                          key: ValueKey('job-photo-$i'),
                          onTap: () => setState(() => _viewing = i),
                          child: Tooltip(
                            message: pictures[i].name,
                            child: _Picture(
                              url: pictures[i].url,
                              name: pictures[i].name,
                              decodeWidth: 300,
                              fit: BoxFit.cover,
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        for (final f in others)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Material(
              color: theme.colorScheme.surface,
              shape: RoundedRectangleBorder(
                side: BorderSide(color: theme.colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
              child: InkWell(
                key: ValueKey('job-file-${f.name}'),
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
                onTap: () => _open(f.url),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 10,
                  ),
                  child: Row(
                    children: [
                      Icon(
                        f.name.toLowerCase().endsWith('.pdf')
                            ? Icons.picture_as_pdf_outlined
                            : Icons.insert_drive_file_outlined,
                        color: ToneColours.of(context, Tone.bad).ink,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          f.name.isEmpty ? 'A file' : f.name,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      Text(
                        'Opens in a new tab',
                        style: theme.textTheme.bodySmall,
                      ),
                      const SizedBox(width: 4),
                      const Icon(Icons.open_in_new, size: 16),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// One of Workiz's pictures. Drawn by the browser's own `<img>` on web:
/// Workiz's host sends no CORS headers, which a canvas fetch needs and an
/// `<img>` does not (measured by Jobs, 2026-10-06). Decoded small, because
/// the files are full size.
class _Picture extends StatelessWidget {
  const _Picture({
    required this.url,
    required this.name,
    required this.decodeWidth,
    required this.fit,
  });

  final String url;
  final String name;
  final int decodeWidth;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Image.network(
      url,
      key: ValueKey('job-picture-$url'),
      fit: fit,
      cacheWidth: decodeWidth,
      webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
      semanticLabel: name,
      errorBuilder: (context, _, _) => Container(
        color: theme.colorScheme.surfaceContainerHighest,
        alignment: Alignment.center,
        padding: const EdgeInsets.all(6),
        child: Text(
          'Could not load $name',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
      ),
    );
  }
}
