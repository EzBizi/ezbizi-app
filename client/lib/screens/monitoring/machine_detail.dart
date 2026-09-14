import 'package:flutter/material.dart';


/// Everything one machine has reported, for the panel beside the grid.
///
/// The grid answers "is anything wrong across the room". This answers "what is
/// going on with THAT one", which is a different question and wants a
/// different shape: labelled rows, grouped, nothing summarised away.
///
/// ── The rule this whole file follows ──────────────────────────────
///
/// A section that has nothing to show is not rendered at all — no header, no
/// empty list, no "None". Every one of these fields is optional at the source:
/// an older agent does not report drives, a machine with no browser reports no
/// tabs, and a viewer without `monitoring.screenshots.view` is not sent the
/// screen context at all. Rendering "Browser tabs: none" in that last case
/// would be a lie told to the person least able to check it.
class MachineDetail extends StatelessWidget {
  const MachineDetail({
    super.key,
    required this.title,
    required this.person,
    required this.specs,
    required this.screenContext,
  });

  final String title;
  final String person;

  /// The device's `specs` map, or null where it was withheld or never sent.
  final Map<String, dynamic>? specs;

  /// The device's `screen_context`, present only for a viewer holding
  /// `monitoring.screenshots.view` and only for a non-exempt device.
  final Map<String, dynamic>? screenContext;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final s = specs ?? const <String, dynamic>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: theme.textTheme.titleMedium),
        Text(person, style: theme.textTheme.labelMedium),
        const SizedBox(height: 12),

        _Section(
          title: 'System',
          rows: [
            _Row('Computer', s['computer_name']),
            _Row('OS', s['os_version']),
            _Row('Processor', _processor(s)),
            _Row('Graphics', s['gpu_name']),
            _Row('Memory', _memory(s)),
            _Row('Uptime', _uptime(s['uptime_seconds'])),
          ],
        ),

        _Drives(drives: s['drives']),

        _Section(
          title: 'Network',
          rows: [
            _Row('Wi-Fi', _wifi(s)),
            _Row('Type', s['connection_type']),
            _Row('Local IP', s['local_ip']),
            _Row('Sent', _megabytes(s['net_sent_mb'])),
            _Row('Received', _megabytes(s['net_received_mb'])),
          ],
        ),

        _Section(
          title: 'Security',
          rows: [
            _Row('Antivirus', _health(_security(s)['antivirus'])),
            _Row('Firewall', _health(_security(s)['firewall'])),
            _Row('Updates', _health(_security(s)['updates'])),
          ],
        ),

        _Section(
          title: 'Activity',
          rows: [
            _Row('Idle', _idle(s['idle_seconds'])),
            _Row('Screen', _screen(s['screen_locked'])),
            // Screen content, so it arrives in its own map and only for a
            // viewer who may see screenshots — see MonitoredDevice's §9 entry.
            _Row('Active window', screenContext?['active_window']),
          ],
        ),

        _Names(title: 'Open programs', values: s['open_apps']),
        _Names(title: 'Browser tabs', values: screenContext?['browser_tabs']),
        _Connections(rows: s['connections']),
        _Names(
          title: 'Installed',
          values: s['installed_programs'],
          limit: 12,
        ),
      ],
    );
  }

  static Map<String, dynamic> _security(Map<String, dynamic> s) {
    final block = s['security'];
    return block is Map<String, dynamic> ? block : const <String, dynamic>{};
  }

  /// Windows' own Security Center words, said the way somebody reading a
  /// roster would say them.
  ///
  /// An unrecognised value is passed through rather than mapped to anything:
  /// inventing "On" for a state we have not seen before is the one wrong
  /// answer here that would actually matter, because it says a machine is
  /// protected on the strength of not knowing.
  static String? _health(Object? value) {
    switch (value) {
      case 'good':
        return 'On';
      case 'poor':
        return 'Needs attention';
      case 'snoozed':
        return 'Paused';
      case 'not_monitored':
        return 'Not monitored by Windows';
      case null:
        return null;
    }
    return value.toString();
  }

  static String? _processor(Map<String, dynamic> s) {
    final model = s['cpu_model'];
    final cores = s['cpu_cores'];
    if (model == null) return null;
    return cores == null ? '$model' : '$model ($cores cores)';
  }

  static String? _memory(Map<String, dynamic> s) {
    final total = s['ram_gb'];
    if (total == null) return null;
    final free = s['ram_free_gb'];
    return free == null ? '$total GB' : '$total GB total, $free GB free';
  }

  static String? _wifi(Map<String, dynamic> s) {
    final ssid = s['wifi_ssid'];
    if (ssid == null) return null;
    final percent = s['wifi_signal_percent'];
    return percent == null ? '$ssid' : '$ssid ($percent%)';
  }

  static String? _megabytes(Object? mb) => mb == null ? null : '$mb MB';

  /// "1d 14h 27m" — days only once there are any, same for hours.
  static String? _uptime(Object? seconds) {
    if (seconds is! int || seconds < 0) return null;
    final d = seconds ~/ 86400;
    final h = (seconds % 86400) ~/ 3600;
    final m = (seconds % 3600) ~/ 60;
    return [
      if (d > 0) '${d}d',
      if (d > 0 || h > 0) '${h}h',
      '${m}m',
    ].join(' ');
  }

  /// Under a minute reads "Active now" rather than "0m": the question this
  /// answers is whether somebody is there, not the exact number.
  static String? _idle(Object? seconds) {
    if (seconds is! int || seconds < 0) return null;
    if (seconds < 60) return 'Active now';
    final m = seconds ~/ 60;
    if (m < 60) return '$m minutes';
    final h = m ~/ 60;
    return h < 24 ? '$h hours' : '${h ~/ 24} days';
  }

  static String? _screen(Object? locked) {
    if (locked is! bool) return null;
    return locked ? 'Locked' : 'Unlocked';
  }
}

/// A titled block of label/value rows, which renders nothing at all when every
/// row is empty — see this file's own doc comment for why that is the rule.
class _Section extends StatelessWidget {
  const _Section({required this.title, required this.rows});

  final String title;
  final List<_Row> rows;

  @override
  Widget build(BuildContext context) {
    final shown = rows.where((r) => r.text != null).toList();
    if (shown.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          ...shown,
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row(this.label, Object? value)
      : text = value == null ? null : '$value';

  final String label;
  final String? text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (text == null) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 96,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text!,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Every fixed drive, in words rather than as a meter: "324 GB free of 953
/// GB". The card already shows the biggest drive as a bar, so repeating the
/// bar here would say the same thing twice and still not answer the question
/// the panel is open for, which is what the OTHER drives look like.
class _Drives extends StatelessWidget {
  const _Drives({required this.drives});

  final Object? drives;

  @override
  Widget build(BuildContext context) {
    if (drives is! List || (drives as List).isEmpty) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Storage', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          for (final drive in (drives as List).whereType<Map>())
            if (drive['total_gb'] is int && drive['free_gb'] is int)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: _Row(
                  '${drive['letter'] ?? ''}'.trim(),
                  '${drive['free_gb']} GB free of ${drive['total_gb']} GB',
                ),
              ),
        ],
      ),
    );
  }
}

/// A titled list of plain strings — open programs, browser tabs, installed
/// software. [limit] caps a long list and says how many more there are, rather
/// than letting three hundred installed programs own the panel.
class _Names extends StatelessWidget {
  const _Names({required this.title, required this.values, this.limit});

  final String title;
  final Object? values;
  final int? limit;

  @override
  Widget build(BuildContext context) {
    if (values is! List || (values as List).isEmpty) {
      return const SizedBox.shrink();
    }

    final all = (values as List).map((v) => '$v').toList();
    final cap = limit ?? all.length;
    final shown = all.take(cap).toList();
    final hidden = all.length - shown.length;

    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$title (${all.length})', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          for (final name in shown)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 1),
              child: Text(
                name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (hidden > 0)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(
                'and $hidden more',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Which program is talking to which address. The port is shown because
/// "chrome to 142.250.1.1" and "chrome to 142.250.1.1:8443" are different
/// facts to somebody looking for something odd.
class _Connections extends StatelessWidget {
  const _Connections({required this.rows});

  final Object? rows;

  @override
  Widget build(BuildContext context) {
    if (rows is! List || (rows as List).isEmpty) {
      return const SizedBox.shrink();
    }

    final list = (rows as List).whereType<Map>().toList();
    if (list.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Connections (${list.length})',
            style: theme.textTheme.labelLarge,
          ),
          const SizedBox(height: 4),
          for (final row in list.take(12))
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 1),
              child: Row(
                children: [
                  SizedBox(
                    width: 96,
                    child: Text(
                      '${row['process'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      '${row['remote_address'] ?? ''}'
                      '${row['remote_port'] == null ? '' : ':${row['remote_port']}'}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      // onSurfaceVariant, NOT EzBiziTheme.edge: edge is a
                      // divider tint chosen to disappear against the surface,
                      // which is the one thing text must not do.
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          if (list.length > 12)
            Text(
              'and ${list.length - 12} more',
              style: theme.textTheme.labelSmall,
            ),
        ],
      ),
    );
  }
}
