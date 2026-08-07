import 'package:flutter/material.dart';
import '../widgets/side_panel.dart';

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
class CompanyFormPanel extends StatefulWidget {
  const CompanyFormPanel({super.key, required this.parents, this.fixedParent});

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
  final String franchise;
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
}

class _CompanyFormPanelState extends State<CompanyFormPanel> {
  final _name = TextEditingController();
  final _franchise = TextEditingController();
  final _address1 = TextEditingController();
  final _address2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();
  final _phone = TextEditingController();

  late bool _isSub = widget.fixedParent != null;
  late String? _parentId = widget.fixedParent?.id;

  @override
  void dispose() {
    for (final c in [_name, _franchise, _address1, _address2, _city, _state, _postal, _phone]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _valid =>
      _name.text.trim().isNotEmpty &&
      _franchise.text.trim().isNotEmpty &&
      // A sub company with no parent is not a sub company. The button stays
      // disabled rather than letting the server explain it afterwards.
      (!_isSub || _parentId != null);

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: _isSub ? 'Add a sub company' : 'Add a company',
      subtitle: _isSub
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
          child: Text(_isSub ? 'Add sub company' : 'Add company'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // The kind first, because it changes what the rest of the form means.
          // Asking it last would make somebody re-read what they had already
          // filled in.
          if (widget.fixedParent == null) ...[
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
              DropdownButtonFormField<String>(
                initialValue: _parentId,
                decoration: const InputDecoration(border: OutlineInputBorder()),
                hint: const Text('Choose one'),
                items: [
                  for (final p in widget.parents)
                    DropdownMenuItem(value: p.id, child: Text(p.name)),
                ],
                onChanged: (v) => setState(() => _parentId = v),
              ),
            ],
            const SizedBox(height: 22),
          ],

          _label(context, 'Name'),
          _field(_name, autofocus: true),

          const SizedBox(height: 22),
          _label(context, 'Its first place'),
          _field(
            _franchise,
            helper: 'Where work happens — a state, a city, a region. '
                'A company must have at least one; you can add more later.',
          ),

          const SizedBox(height: 22),
          _label(context, 'Address'),
          _field(_address1, hint: 'Street'),
          const SizedBox(height: 10),
          _field(_address2, hint: 'Suite, unit, floor (optional)'),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(flex: 3, child: _field(_city, hint: 'City')),
            const SizedBox(width: 10),
            // Two characters, because that is what the server accepts — a full
            // state name would be refused after the fact for a reason the form
            // could have made obvious.
            Expanded(child: _field(_state, hint: 'ST', maxLength: 2, caps: true)),
            const SizedBox(width: 10),
            Expanded(flex: 2, child: _field(_postal, hint: 'ZIP')),
          ]),

          const SizedBox(height: 22),
          _label(context, 'Phone'),
          _field(_phone, hint: 'Optional'),
        ],
      ),
    );
  }

  Widget _label(BuildContext context, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text,
            style: Theme.of(context)
                .textTheme
                .bodyMedium
                ?.copyWith(fontWeight: FontWeight.w600)),
      );

  Widget _field(
    TextEditingController controller, {
    String? hint,
    String? helper,
    bool autofocus = false,
    int? maxLength,
    bool caps = false,
  }) {
    return TextField(
      controller: controller,
      autofocus: autofocus,
      maxLength: maxLength,
      textCapitalization:
          caps ? TextCapitalization.characters : TextCapitalization.none,
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
    Navigator.of(context).pop(CompanyDraft(
      name: _name.text.trim(),
      franchise: _franchise.text.trim(),
      parentId: _isSub ? _parentId : null,
      address1: _address1.text.trim(),
      address2: _address2.text.trim(),
      city: _city.text.trim(),
      state: _state.text.trim().toUpperCase(),
      postalCode: _postal.text.trim(),
      phone: _phone.text.trim(),
    ));
  }
}
