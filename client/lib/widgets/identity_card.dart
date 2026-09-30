import '../core/phone_numbers.dart';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import 'calendar_day_field.dart';
import 'error_note.dart';
import 'logo_cropper.dart';
import 'side_panel.dart';
import 'address_fields.dart';
import 'not_filled_in.dart';
import 'profile_aura.dart';
import 'user_avatar.dart';

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
  // The home address in its parts, as the lookup fills them (Jose,
  // 2026-09-30). The server composes the one line contracts print.
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();
  final _country = TextEditingController();
  final _gender = TextEditingController();
  final _dob = TextEditingController();

  bool _hasAvatar = false;
  Uint8List? _avatarPreview;

  /// Bumped when the photo changes, so the fetched copy is not the cached one.
  String _avatarVersion = '';

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
    for (final c in [
      _first,
      _middle,
      _last,
      _phone,
      _line1,
      _line2,
      _city,
      _state,
      _postal,
      _country,
      _gender,
      _dob,
    ]) {
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
      // Formatted even when stored as bare digits, as HR's record shows it.
      _phone.text = formatPhoneNumber(
        toE164(b['phone'] as String?) ?? b['phone'] as String?,
      );
      // A server from before the parts sends only the one line: it stays
      // the street rather than the address appearing to be gone.
      _line1.text =
          b['address_line1'] as String? ??
          (b.containsKey('address_line1') ? '' : b['address'] as String? ?? '');
      _line2.text = b['address_line2'] as String? ?? '';
      _city.text = b['city'] as String? ?? '';
      _state.text = b['state'] as String? ?? '';
      _postal.text = b['postal_code'] as String? ?? '';
      _country.text = b['country'] as String? ?? '';
      _gender.text = b['gender'] as String? ?? '';
      _hasAvatar = b['has_avatar'] == true;
      _dob.text = b['date_of_birth'] as String? ?? '';
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
      // Stored as E.164, never as the pretty form the field is showing.
      'phone': toE164(_phone.text),
      // The parts; the server composes `address` from them.
      for (final (key, c) in [
        ('address_line1', _line1),
        ('address_line2', _line2),
        ('city', _city),
        ('state', _state),
        ('postal_code', _postal),
        ('country', _country),
      ])
        key: c.text.trim().isEmpty ? null : c.text.trim(),
      'gender': _gender.text.trim().isEmpty ? null : _gender.text.trim(),
      'date_of_birth': _dob.text.trim().isEmpty ? null : _dob.text.trim(),
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
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Profile saved.')));
    }
  }

  String? _firstError(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;

    if (errors == null || errors.isEmpty) return null;

    final first = errors.values.first;

    return first is List && first.isNotEmpty ? first.first as String : null;
  }

  /// Pick, crop, upload.
  ///
  /// The crop is not a nicety. This picture is drawn in a circle everywhere it
  /// appears, and the server accepts anything up to 6000x6000 of any shape — so
  /// a landscape photo was stored whole and then centre-cropped by the circle,
  /// which is how somebody's head ends up outside the frame with nothing they
  /// can do about it. Choosing what is inside the circle is the whole job.
  ///
  /// The same cropper the logos use, restricted to one shape: a logo can be
  /// square or wide because it sits in different places, and a face in a circle
  /// can only be square.
  Future<void> _pickPhoto() async {
    final picked = await Files.pickImage();

    if (picked == null || !mounted) return;

    final cropped = await showSidePanel<Uint8List>(
      context,
      builder: (_) => LogoCropper(
        bytes: Uint8List.fromList(picked.bytes),
        shapes: const {
          'square': {
            'width': 512,
            'height': 512,
            'label': 'Photo',
            'help': 'Drag to choose what sits inside the circle.',
          },
        },
      ),
    );

    if (cropped == null || !mounted) return;

    // Shown immediately from the bytes in hand. Waiting for a round trip and a
    // re-fetch makes choosing a photo feel like it did not work.
    setState(() => _avatarPreview = cropped);

    // The cropper always emits PNG, so the name says so — the server validates
    // on the file's real contents, but a name that disagrees with them is the
    // kind of thing that trips a proxy or a virus scanner later.
    final response = await widget.api.postFile(
      '/me/avatar',
      'photo',
      'avatar.png',
      cropped,
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _avatarPreview = null;
        _error = _firstError(response) ?? response.message;
      });
      return;
    }

    setState(() {
      _hasAvatar = true;
      // A new photo at the same URL. Without this the fetched copy is the one
      // the browser already has.
      _avatarVersion = DateTime.now().microsecondsSinceEpoch.toString();
    });

    // The top bar draws the same picture, and it reads the session rather than
    // this card's state.
    widget.session.refresh();
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
      _avatarVersion = DateTime.now().microsecondsSinceEpoch.toString();
    });

    widget.session.refresh();
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

            // Beside the photo on a desk; under it on a phone, where the pairs
            // stack too: beside a photo at 390, "Phone number" read "Phone …"
            // and the ZIP lost its last digit.
            LayoutBuilder(
              builder: (context, constraints) {
                final narrow = constraints.maxWidth < 560;

                Widget pair(Widget a, Widget b) => narrow
                    ? Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [a, const SizedBox(height: 12), b],
                      )
                    : Row(
                        children: [
                          Expanded(child: a),
                          const SizedBox(width: 12),
                          Expanded(child: b),
                        ],
                      );

                final fields = Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    pair(
                      _field(_first, 'First name', flag: true),
                      _field(_middle, 'Middle name (optional)'),
                    ),
                    const SizedBox(height: 12),
                    pair(
                      _field(_last, 'Last name', flag: true),
                      _field(
                        _phone,
                        'Phone number',
                        phone: true,
                        flag: true,
                      ),
                    ),
                    const SizedBox(height: 12),
                    AddressFields(
                      api: widget.api,
                      line1: _line1,
                      line2: _line2,
                      city: _city,
                      state: _state,
                      postal: _postal,
                      country: _country,
                      flagEmpty: true,
                    ),
                  ],
                );

                if (narrow) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Align(
                        alignment: Alignment.centerLeft,
                        child: _photo(context, me),
                      ),
                      const SizedBox(height: 12),
                      fields,
                    ],
                  );
                }

                return Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _photo(context, me),
                    const SizedBox(width: 20),
                    Expanded(child: fields),
                  ],
                );
              },
            ),

            const SizedBox(height: 12),
            LayoutBuilder(
              builder: (context, constraints) {
                final stacked = constraints.maxWidth < 560;
                final gender = _genderField(context);
                final dob = _dobField(context);
                if (stacked) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      gender,
                      const SizedBox(height: 12),
                      dob,
                    ],
                  );
                }
                return Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: gender),
                    const SizedBox(width: 12),
                    Expanded(child: dob),
                  ],
                );
              },
            ),

            // Email, role, organization and the places this person can act on
            // used to sit here, immediately above Save -- which said Save
            // applied to them. It never did. They are AccountFactsCard now,
            // which has no footer at all.

            if (_error != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_error!),
            ],

            const SizedBox(height: 16),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed:
                    _saving ||
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
    return Column(
      children: [
        // Was a NetworkImage, which is the browser fetching the URL by itself —
        // no Authorization header, and this endpoint is authenticated on
        // purpose (§9: a guessable avatar URL says who works where). So it 401'd
        // every time: the upload succeeded, the button changed to "Change", and
        // the circle stayed empty.
        // Marked, still, while there is none: the photo is one of the things
        // the top bar's picture pulses about.
        ProfileAura(
          active: !_hasAvatar && _avatarPreview == null,
          pulse: false,
          child: UserAvatar(
            api: widget.api,
            userId: me.id,
            name: me.name,
            radius: 44,
            hasAvatar: _hasAvatar,
            version: _avatarVersion,
            bytes: _avatarPreview,
          ),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _pickPhoto,
          child: Text(_hasAvatar ? 'Change' : 'Add photo'),
        ),
        if (_hasAvatar)
          TextButton(onPressed: _removePhoto, child: const Text('Remove')),
      ],
    );
  }

  /// A box of the profile. [flag] is one of User::PROFILE_FIELDS, which turns
  /// amber while it is empty (Jose, 2026-09-30): what the top bar's picture
  /// pulses about.
  Widget _field(
    TextEditingController c,
    String label, {
    int maxLines = 1,
    bool phone = false,
    bool flag = false,
  }) => TextField(
        controller: c,
        maxLines: maxLines,
        keyboardType: phone ? TextInputType.phone : null,
        inputFormatters: phone ? const [PhoneNumberInputFormatter()] : null,
        decoration: notFilledIn(
          context,
          InputDecoration(
            labelText: label,
            isDense: true,
            border: const OutlineInputBorder(),
          ),
          empty: flag && c.text.trim().isEmpty,
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
        _field(_gender, 'Gender', flag: true),
        const SizedBox(height: 6),
        Wrap(
          spacing: 6,
          children: [
            // Selected-looking when it matches, because otherwise a filled
            // box reading "Male" above four identical chips looks like two
            // controls for one value. It is one: the chips write the field.
            // They stay chips rather than becoming a closed list -- a closed
            // list makes somebody pick the nearest wrong answer about
            // themselves, and anything not offered is still typed.
            for (final g in _genders)
              ChoiceChip(
                label: Text(g, style: const TextStyle(fontSize: 11)),
                selected: _gender.text.trim().toLowerCase() == g.toLowerCase(),
                onSelected: (_) => setState(() => _gender.text = g),
              ),
          ],
        ),
      ],
    );
  }

  Widget _dobField(BuildContext context) {
    final now = DateTime.now();
    return CalendarDayField(
      controller: _dob,
      label: 'Date of birth',
      formats: widget.session.me!.display,
      flagEmpty: true,
      onChanged: (_) => setState(() {}),
      firstDate: DateTime(now.year - 120),
      lastDate: DateTime(now.year, now.month, now.day),
    );
  }

}
