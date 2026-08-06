import 'dart:typed_data';

import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import 'error_note.dart';

/// Who you are (plan §2.1 category 6).
///
/// Names are held in parts and the display name is composed from them, so the
/// audit trail and every list screen keep agreeing with what somebody typed
/// here. Email is shown and not editable — it is the address a password reset
/// goes to, and changing it without proving control of the new mailbox turns a
/// borrowed session into a permanent takeover.
class IdentityCard extends StatefulWidget {
  const IdentityCard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<IdentityCard> createState() => _IdentityCardState();
}

class _IdentityCardState extends State<IdentityCard> {
  final _first = TextEditingController();
  final _middle = TextEditingController();
  final _last = TextEditingController();
  final _phone = TextEditingController();
  final _gender = TextEditingController();

  DateTime? _dob;
  String _email = '';
  bool _hasAvatar = false;
  Uint8List? _avatarPreview;

  bool _loading = true;
  bool _saving = false;
  String? _error;

  /// Suggestions, not a closed list. The field accepts anything — a fixed set
  /// is wrong for somebody, and being told your own answer is not available is
  /// a poor first impression of an app you have to use every day.
  static const _genders = ['Female', 'Male', 'Non-binary', 'Prefer not to say'];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_first, _middle, _last, _phone, _gender]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/me/profile');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final b = response.body;
      _first.text = b['first_name'] as String? ?? '';
      _middle.text = b['middle_name'] as String? ?? '';
      _last.text = b['last_name'] as String? ?? '';
      _phone.text = b['phone'] as String? ?? '';
      _gender.text = b['gender'] as String? ?? '';
      _email = b['email'] as String? ?? '';
      _hasAvatar = b['has_avatar'] == true;
      _dob = b['date_of_birth'] == null
          ? null
          : DateTime.tryParse(b['date_of_birth'] as String);
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.patch('/me', {
      'first_name': _first.text.trim(),
      'last_name': _last.text.trim(),
      // Sent as null rather than omitted, so clearing a field actually clears
      // it. An omitted key means "leave it alone", which would make these
      // impossible to empty once filled.
      'middle_name': _middle.text.trim().isEmpty ? null : _middle.text.trim(),
      'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
      'gender': _gender.text.trim().isEmpty ? null : _gender.text.trim(),
      'date_of_birth': _dob == null
          ? null
          : '${_dob!.year.toString().padLeft(4, '0')}-'
              '${_dob!.month.toString().padLeft(2, '0')}-'
              '${_dob!.day.toString().padLeft(2, '0')}',
    });

    if (!mounted) return;

    setState(() {
      _saving = false;
      _error = response.ok ? null : _firstError(response) ?? response.message;
    });

    if (!response.ok) return;

    // The app bar, the avatar initial and every list read the display name.
    await widget.session.refresh();

    if (mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Profile saved.')));
    }
  }

  String? _firstError(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;

    if (errors == null || errors.isEmpty) return null;

    final first = errors.values.first;

    return first is List && first.isNotEmpty ? first.first as String : null;
  }

  Future<void> _pickPhoto() async {
    final picked = await Files.pickImage();

    if (picked == null || !mounted) return;

    // Shown immediately from the bytes in hand. Waiting for a round trip and a
    // re-fetch makes choosing a photo feel like it did not work.
    setState(() => _avatarPreview = Uint8List.fromList(picked.bytes));

    final response =
        await widget.api.postFile('/me/avatar', 'photo', picked.name, picked.bytes);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _avatarPreview = null;
        _error = _firstError(response) ?? response.message;
      });
      return;
    }

    setState(() => _hasAvatar = true);
  }

  Future<void> _removePhoto() async {
    final response = await widget.api.delete('/me/avatar');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    setState(() {
      _hasAvatar = false;
      _avatarPreview = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Card(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }

    final me = widget.session.me!;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Who you are', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 16),

            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _photo(context, me),
                const SizedBox(width: 20),
                Expanded(
                  child: Column(
                    children: [
                      Row(children: [
                        Expanded(child: _field(_first, 'First name')),
                        const SizedBox(width: 12),
                        Expanded(child: _field(_middle, 'Middle name (optional)')),
                      ]),
                      const SizedBox(height: 12),
                      Row(children: [
                        Expanded(child: _field(_last, 'Last name')),
                        const SizedBox(width: 12),
                        Expanded(child: _field(_phone, 'Phone number')),
                      ]),
                    ],
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _genderField(context)),
                const SizedBox(width: 12),
                Expanded(child: _dobField(context)),
              ],
            ),

            const SizedBox(height: 16),
            _readOnly(context, 'Email', _email,
                'Your sign-in address and where a password reset goes. Changing '
                'it needs a confirmation email, which is not wired up yet.'),
            if (me.roleName != null) _readOnly(context, 'Role', me.roleName!, null),
            _readOnly(context, 'Organization', me.organization.name, null),
            if (me.franchises.isNotEmpty)
              _readOnly(
                context,
                'You can act on',
                me.franchises.map((f) => f.name).join(', '),
                'Permissions decide WHAT you may do; this decides WHERE.',
              ),

            if (_error != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_error!),
            ],

            const SizedBox(height: 16),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _saving ||
                        _first.text.trim().isEmpty ||
                        _last.text.trim().isEmpty
                    ? null
                    : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _photo(BuildContext context, Me me) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      children: [
        CircleAvatar(
          radius: 44,
          backgroundColor: scheme.primaryContainer,
          backgroundImage: _avatarPreview != null
              ? MemoryImage(_avatarPreview!)
              : _hasAvatar
                  // Cache-busted on every rebuild, or a replaced photo keeps
                  // showing the old one until the browser feels like asking.
                  ? NetworkImage(
                      '${ApiClient.baseUrl}/api/v1/users/${me.id}/avatar'
                      '?v=${DateTime.now().millisecondsSinceEpoch ~/ 60000}')
                  : null,
          child: (_avatarPreview == null && !_hasAvatar)
              ? Text(
                  me.name.isNotEmpty ? me.name[0].toUpperCase() : '?',
                  style: TextStyle(fontSize: 30, color: scheme.onPrimaryContainer),
                )
              : null,
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _pickPhoto,
          child: Text(_hasAvatar ? 'Change' : 'Add photo'),
        ),
        if (_hasAvatar)
          TextButton(
            onPressed: _removePhoto,
            child: const Text('Remove'),
          ),
      ],
    );
  }

  Widget _field(TextEditingController c, String label) => TextField(
        controller: c,
        decoration: InputDecoration(
          labelText: label,
          isDense: true,
          border: const OutlineInputBorder(),
        ),
        onChanged: (_) => setState(() {}),
      );

  /// A text field with suggestions, not a dropdown.
  ///
  /// A closed list forces somebody to pick the nearest wrong answer about
  /// themselves. The common answers are one tap away and anything else is
  /// typed.
  Widget _genderField(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _field(_gender, 'Gender (optional)'),
        const SizedBox(height: 6),
        Wrap(
          spacing: 6,
          children: [
            for (final g in _genders)
              ActionChip(
                label: Text(g, style: const TextStyle(fontSize: 11)),
                onPressed: () => setState(() => _gender.text = g),
              ),
          ],
        ),
      ],
    );
  }

  Widget _dobField(BuildContext context) {
    return InkWell(
      onTap: () async {
        final now = DateTime.now();

        final picked = await showDatePicker(
          context: context,
          initialDate: _dob ?? DateTime(now.year - 30),
          firstDate: DateTime(now.year - 100),
          lastDate: now,
        );

        if (picked != null) setState(() => _dob = picked);
      },
      child: InputDecorator(
        decoration: const InputDecoration(
          labelText: 'Date of birth (optional)',
          isDense: true,
          border: OutlineInputBorder(),
        ),
        child: Row(
          children: [
            Expanded(
              child: Text(_dob == null
                  ? 'Not set'
                  : '${_dob!.year}-${_dob!.month.toString().padLeft(2, '0')}'
                      '-${_dob!.day.toString().padLeft(2, '0')}'),
            ),
            if (_dob != null)
              IconButton(
                icon: const Icon(Icons.clear, size: 16),
                onPressed: () => setState(() => _dob = null),
              ),
            const Icon(Icons.calendar_today_outlined, size: 16),
          ],
        ),
      ),
    );
  }

  Widget _readOnly(BuildContext context, String label, String value, String? note) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 130,
                child: Text(label, style: Theme.of(context).textTheme.bodySmall),
              ),
              Expanded(child: Text(value)),
            ],
          ),
          if (note != null)
            Padding(
              padding: const EdgeInsets.only(left: 130, top: 2),
              child: Text(note, style: Theme.of(context).textTheme.bodySmall),
            ),
        ],
      ),
    );
  }
}
