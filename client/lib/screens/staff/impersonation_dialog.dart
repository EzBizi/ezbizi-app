import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../widgets/error_note.dart';

/// Opening a session on a client's data (plan §4.2).
///
/// The reason field is mandatory and the server enforces a minimum length. That
/// is not bureaucracy: when a client asks why their records were opened on a
/// Tuesday, "no reason recorded" and "we don't know" are the same answer.
///
/// The expiry is not optional either. A session that never ends is standing
/// access wearing a timer.
class ImpersonationDialog extends StatefulWidget {
  const ImpersonationDialog({
    super.key,
    required this.staff,
    required this.organizationId,
    required this.organizationName,
  });

  final StaffSession staff;
  final String organizationId;
  final String organizationName;

  @override
  State<ImpersonationDialog> createState() => _ImpersonationDialogState();
}

class _ImpersonationDialogState extends State<ImpersonationDialog> {
  final _reason = TextEditingController();

  int _minutes = 30;
  bool _clientApproved = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.staff.api.post('/staff/impersonations', {
      'organization_id': widget.organizationId,
      'reason': _reason.text.trim(),
      'minutes': _minutes,
      'client_approved': _clientApproved,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.staff.me!;

    return AlertDialog(
      title: Text('Enter ${widget.organizationName}'),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'This is recorded against your name, whatever your tier, and the '
                'client can be shown it.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: 16),

              TextField(
                controller: _reason,
                decoration: const InputDecoration(
                  labelText: 'Reason',
                  helperText: 'What are you looking at, and why',
                ),
                minLines: 2,
                maxLines: 4,
                enabled: !_busy,
              ),
              const SizedBox(height: 16),

              Text('Expires after', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              SegmentedButton<int>(
                segments: const [
                  ButtonSegment(value: 15, label: Text('15 min')),
                  ButtonSegment(value: 30, label: Text('30 min')),
                  ButtonSegment(value: 60, label: Text('1 hour')),
                  ButtonSegment(value: 120, label: Text('2 hours')),
                ],
                selected: {_minutes},
                onSelectionChanged:
                    _busy ? null : (s) => setState(() => _minutes = s.first),
              ),
              const SizedBox(height: 8),

              CheckboxListTile(
                value: _clientApproved,
                onChanged: _busy ? null : (v) => setState(() => _clientApproved = v ?? false),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                title: const Text('The client approved this'),
                subtitle: Text(
                  me.mayEnterWithoutApproval
                      ? 'Your tier may enter without approval. Unticked is recorded '
                          'as unapproved, not as unrecorded.'
                      : 'Required for your tier.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),

              if (_error != null) ...[
                const SizedBox(height: 12),
                ErrorNote(_error!),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _busy ? null : _start,
          child: const Text('Start session'),
        ),
      ],
    );
  }
}
