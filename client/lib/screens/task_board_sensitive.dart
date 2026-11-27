import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../widgets/side_panel.dart';

/// Sensitive tasks (Jose, 2026-11-12: "a safety filter in our task board
/// which required the authenticator to see"). The server sends a sensitive
/// task `locked` (no values, no updates, no files) until this session enters
/// a current authenticator code; it opens for 5 minutes and every reveal is
/// recorded. The title stays readable, shown with a lock.

bool taskIsSensitive(Map t) => t['sensitive'] == true;

bool taskIsLocked(Map t) => t['locked'] == true;

/// The title as every view shows it: "🔒 Logins" for a sensitive task.
String taskTitle(Map t) =>
    taskIsSensitive(t) ? '🔒 ${t['title'] ?? ''}' : '${t['title'] ?? ''}';

/// How long an unlock lasts; the server's UNLOCK_SECONDS.
const sensitiveUnlockFor = Duration(minutes: 5);

/// Asks for the code and unlocks [taskId]; pops with the open task (its
/// `unlocked_until` beside it), or null.
Future<Map<String, dynamic>?> unlockSensitiveTask(
  BuildContext context, {
  required ApiClient api,
  required String taskId,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (_) => SensitiveUnlockPanel(api: api, taskId: taskId),
);

class SensitiveUnlockPanel extends StatefulWidget {
  const SensitiveUnlockPanel({
    super.key,
    required this.api,
    required this.taskId,
  });

  final ApiClient api;
  final String taskId;

  @override
  State<SensitiveUnlockPanel> createState() => _SensitiveUnlockPanelState();
}

class _SensitiveUnlockPanelState extends State<SensitiveUnlockPanel> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _code.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  bool get _ready => RegExp(r'^\d{6}$').hasMatch(_code.text) && !_busy;

  Future<void> _go() async {
    if (!_ready) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final res = await widget.api.post('/tasks/${widget.taskId}/unlock', {
      'code': _code.text,
    });
    if (!mounted) return;
    if (res.ok && res.body['data'] is Map) {
      Navigator.of(
        context,
      ).pop(Map<String, dynamic>.from(res.body['data'] as Map));
      return;
    }
    setState(() {
      _busy = false;
      _error = res.reason;
      _code.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SidePanel(
      title: 'Unlock with authenticator',
      subtitle: 'Sensitive task',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        const SizedBox(width: 8),
        FilledButton(
          key: const Key('sensitive-unlock-confirm'),
          onPressed: _ready ? _go : null,
          child: Text(_busy ? 'Unlocking…' : 'Unlock'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'This task is marked sensitive. Enter the 6-digit code from your '
            'authenticator app to see it for 5 minutes. Every look is recorded.',
          ),
          const SizedBox(height: 16),
          TextField(
            key: const Key('sensitive-unlock-code'),
            controller: _code,
            enabled: !_busy,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: theme.textTheme.headlineSmall?.copyWith(letterSpacing: 8),
            decoration: const InputDecoration(
              counterText: '',
              hintText: '······',
              border: OutlineInputBorder(),
            ),
            onSubmitted: (_) => _go(),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const Key('sensitive-unlock-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// What the drawer shows in place of a locked task's fields and updates.
class SensitiveLockedNotice extends StatelessWidget {
  const SensitiveLockedNotice({super.key, required this.onUnlock});

  final VoidCallback onUnlock;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Container(
      key: const Key('task-locked'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.lock_outline, color: scheme.onSurfaceVariant),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Sensitive task',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Its fields, updates and files are hidden until you unlock it.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            key: const Key('task-unlock'),
            onPressed: onUnlock,
            icon: const Icon(Icons.key_outlined, size: 18),
            label: const Text('Unlock with authenticator'),
          ),
        ],
      ),
    );
  }
}
