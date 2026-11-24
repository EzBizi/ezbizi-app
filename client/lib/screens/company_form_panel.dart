import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../widgets/address_fields.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../core/soft_keyboard.dart';

/// Everything a company needs to exist (plan §3, §5.2).
///
/// ── A panel, not a dialog ────────────────────────────────────────
///
/// This was a modal with two fields, laid over the organization tree it was
/// adding to — so the one thing that would tell you whether you were putting it
/// in the right place was hidden behind the form asking where to put it. The
/// panel leaves the tree visible beside it.
///
/// ── Why it asks for this much ────────────────────────────────────
///
/// A company is the entity whose name and address end up on an invoice (§5.2).
/// Collecting that at creation costs one screen once; leaving it out means every
/// company is half-made until somebody goes back for it, and nobody goes back.
///
/// The first place is here for a harder reason: §3 says a company cannot exist
/// without a franchise. Asking for it in a second step would leave somebody able
/// to close the panel halfway and produce exactly the state the rule forbids.
///
/// ── One form for adding and for editing ──────────────────────────
///
/// The same questions, so the same form. A separate edit screen would be the
/// same eight fields maintained twice, and the second copy is the one that
/// falls behind when a ninth is added.
///
/// Editing asks less, not differently. §3 says an entity is never moved between
/// parents, so the kind and the parent are not offered — a control that only
/// ever produces a refusal is worse than no control. And a company being edited
/// already has its places, so "its first place" has nothing left to ask.
class CompanyFormPanel extends StatefulWidget {
  const CompanyFormPanel({
    super.key,
    required this.api,
    required this.parents,
    this.fixedParent,
  }) : existing = null;

  /// Edit what is already there. [existing] is a company from /companies.
  const CompanyFormPanel.edit({
    super.key,
    required this.api,
    required Map<String, dynamic> company,
  }) : existing = company,
       parents = const [],
       fixedParent = null;

  /// For the address lookup only; the caller still sends the draft.
  final ApiClient api;

  final Map<String, dynamic>? existing;

  /// Top-level companies only.
  ///
  /// §3 fixes the depth at Organization → Company → Sub Company → Franchise, so
  /// a sub company cannot hold another. Offering one as a parent would produce
  /// a refusal the form could have prevented.
  final List<({String id, String name})> parents;

  /// Set when this was opened from a company's own menu — the answer to "under
  /// which?" is already known and asking again would be theatre.
  final ({String id, String name})? fixedParent;

  @override
  State<CompanyFormPanel> createState() => _CompanyFormPanelState();
}

class CompanyDraft {
  const CompanyDraft({
    required this.name,
    required this.franchise,
    required this.parentId,
    required this.address1,
    required this.address2,
    required this.city,
    required this.state,
    required this.postalCode,
    required this.phone,
  });

  final String name;

  /// Null when editing — the company already has its places.
  final String? franchise;
  final String? parentId;
  final String address1;
  final String address2;
  final String city;
  final String state;
  final String postalCode;
  final String phone;

  Map<String, dynamic> toJson() => {
    'name': name,
    if (parentId != null) 'parent_company_id': parentId,
    'franchise': {'name': franchise},
    // Blank means "not given", not "set this to empty" — the same rule the
    // importer follows, so a half-filled form does not write empty strings
    // over columns that were meant to stay null.
    if (address1.isNotEmpty) 'address_line1': address1,
    if (address2.isNotEmpty) 'address_line2': address2,
    if (city.isNotEmpty) 'city': city,
    if (state.isNotEmpty) 'state': state,
    if (postalCode.isNotEmpty) 'postal_code': postalCode,
    if (phone.isNotEmpty) 'phone': phone,
  };

  /// Every field, every time — including the empty ones.
  ///
  /// The opposite rule to creating, and deliberately so. On the way in, blank
  /// means "I did not say"; on the way out it means "take it off", because
  /// clearing a field somebody typed by mistake is the whole point of an edit
  /// form. Omitting it would leave the old value there and look like the save
  /// had failed.
  ///
  /// `parent_company_id` is absent, not null: §3 says an entity is never moved
  /// between parents, and the endpoint refuses the key outright.
  Map<String, dynamic> toUpdateJson() => {
    'name': name,
    'address_line1': address1.isEmpty ? null : address1,
    'address_line2': address2.isEmpty ? null : address2,
    'city': city.isEmpty ? null : city,
    'state': state.isEmpty ? null : state,
    'postal_code': postalCode.isEmpty ? null : postalCode,
    'phone': phone.isEmpty ? null : phone,
  };
}

class _CompanyFormPanelState extends State<CompanyFormPanel> {
  late final _name = _controller('name');
  final _franchise = TextEditingController();
  late final _address1 = _controller('address_line1');
  late final _address2 = _controller('address_line2');
  late final _city = _controller('city');
  late final _state = _controller('state');
  late final _postal = _controller('postal_code');
  late final _phone = _controller('phone');

  bool get _editing => widget.existing != null;

  late bool _isSub =
      widget.fixedParent != null ||
      widget.existing?['parent_company_id'] != null;
  late String? _parentId = widget.fixedParent?.id;

  TextEditingController _controller(String field) =>
      TextEditingController(text: widget.existing?[field] as String? ?? '');

  @override
  void dispose() {
    for (final c in [
      _name,
      _franchise,
      _address1,
      _address2,
      _city,
      _state,
      _postal,
      _phone,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _valid =>
      _name.text.trim().isNotEmpty &&
      // Creating asks for the first place; editing does not, because there
      // already is one.
      (_editing || _franchise.text.trim().isNotEmpty) &&
      // A sub company with no parent is not a sub company. The button stays
      // disabled rather than letting the server explain it afterwards.
      (_editing || !_isSub || _parentId != null);

  String get _noun => _isSub ? 'sub company' : 'company';

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: _editing
          ? 'Edit ${widget.existing!['name']}'
          : _isSub
          ? 'Add a sub company'
          : 'Add a company',
      subtitle: _editing
          // §3 again: what cannot change is worth saying once, here, rather
          // than leaving somebody to look for the control that would do it.
          ? 'Its name, address and phone. Which $_noun it belongs to is fixed '
                'and cannot be changed.'
          : _isSub
          ? 'It inherits its parent company\'s settings before the '
                'organization\'s, and cannot be moved to a different parent later.'
          : 'A branch of the business with its own name and address — the ones '
                'that appear on its invoices.',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _valid ? _submit : null,
          child: Text(_editing ? 'Save changes' : 'Add $_noun'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // The kind first, because it changes what the rest of the form means.
          // Asking it last would make somebody re-read what they had already
          // filled in.
          if (!_editing && widget.fixedParent == null) ...[
            _label(context, 'What kind'),
            SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('Company')),
                ButtonSegment(value: true, label: Text('Sub company')),
              ],
              selected: {_isSub},
              onSelectionChanged: (v) => setState(() {
                _isSub = v.first;
                if (!_isSub) _parentId = null;
              }),
            ),
            if (_isSub) ...[
              const SizedBox(height: 18),
              _label(context, 'Under which company'),
              SearchPickerField<String>(
                value: _parentId,
                border: OutlineInputBorder(),
                options: [
                  for (final p in widget.parents)
                    SearchOption(value: p.id, label: p.name),
                ],
                onChanged: (v) => setState(() => _parentId = v),
              ),
            ],
            const SizedBox(height: 22),
          ],

          _label(context, 'Name'),
          _field(_name, autofocus: autofocusOnOpen),

          if (!_editing) ...[
            const SizedBox(height: 22),
            _label(context, 'Its first place'),
            _field(
              _franchise,
              helper:
                  'Where work happens — a state, a city, a region. '
                  'A company must have at least one; you can add more later.',
            ),
          ],

          const SizedBox(height: 22),
          _label(context, 'Address'),
          // Found with the lookup, kept in parts (Jose, 2026-09-30). ST is
          // still two letters, what the server accepts.
          AddressFields(
            api: widget.api,
            line1: _address1,
            line2: _address2,
            city: _city,
            state: _state,
            postal: _postal,
          ),

          const SizedBox(height: 22),
          _label(context, 'Phone'),
          _field(_phone, hint: 'Optional'),
        ],
      ),
    );
  }

  Widget _label(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Text(
      text,
      style: Theme.of(
        context,
      ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
    ),
  );

  Widget _field(
    TextEditingController controller, {
    String? hint,
    String? helper,
    bool autofocus = false,
  }) {
    return TextField(
      controller: controller,
      autofocus: autofocus,
      decoration: InputDecoration(
        hintText: hint,
        helperText: helper,
        helperMaxLines: 3,
        counterText: '',
        isDense: true,
        border: const OutlineInputBorder(),
      ),
      onChanged: (_) => setState(() {}),
    );
  }

  void _submit() {
    Navigator.of(context).pop(
      CompanyDraft(
        name: _name.text.trim(),
        franchise: _editing ? null : _franchise.text.trim(),
        parentId: _isSub && !_editing ? _parentId : null,
        address1: _address1.text.trim(),
        address2: _address2.text.trim(),
        city: _city.text.trim(),
        state: _state.text.trim().toUpperCase(),
        postalCode: _postal.text.trim(),
        phone: _phone.text.trim(),
      ),
    );
  }
}
