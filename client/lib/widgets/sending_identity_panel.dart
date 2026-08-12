import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'error_note.dart';
import 'refused.dart';
import 'side_panel.dart';

/// Who the outside world sees a tenant's own email coming from (§6 cascade).
///
/// ── What this is, and what it deliberately is not ────────────────
///
/// A customer writing a newsletter to their own list wants it to arrive from
/// `info@everdoors.com`, not from us. That address is a TENANT SETTING (§2.1),
/// set at the organization and overridden by a company or a place under it,
/// exactly like every other setting on the screen this sits on.
///
/// It is NOT the platform's own mail. Invitations, password resets and
/// everything Human Resources sends come from EzBizi and always will —
/// *"they are signing into our platform, it is the duty of the organizations to
/// explain how our software works to their employees"* — so there is no
/// per-tenant path for those, and the card says so out loud rather than leaving
/// somebody to assume this address covers them.
///
/// It is also NOT the platform's transport credentials, which are deployment
/// config and appear nowhere in this app. What a tenant proves here is that
/// they own a DOMAIN; we do the sending and sign it as theirs. Nobody types an
/// SMTP password into this screen, today or when a bring-your-own transport is
/// added — `transport` on the wire is the seam for that, and this screen only
/// ever prints its label.
///
/// ── Why the section lives here rather than in the settings list ──
///
/// Every row in that list is one value with one editor. This is an address, a
/// display name, a verification state that changes without anybody touching it,
/// and a set of DNS records somebody has to copy into another system — which is
/// a panel, not a 150px box in a table row.
///
/// What it does NOT get to reinvent is inheritance. [provenanceSentence] below
/// is the settings screen's own sentence, moved here and imported back, so the
/// card, the panel and every row in the list say "Inherited from A-1 Chimney"
/// in the same words or none of them do.

/// The four routes, which all answer with the same object under `data`.
///
/// PATCH rather than PUT, and the check's scope in its BODY rather than its
/// query, for one boring reason: ApiClient's `put` and `post` take no query
/// string and that file is not this change's to alter. The two that DO take one
/// — patch and delete — are also exactly what `/settings` uses for the same job
/// at the same scopes, so this ends up matching the endpoint it is a cousin of.
const String sendingIdentityPath = '/mail/sending-identity';
const String sendingIdentityCheckPath = '/mail/sending-identity/check';

/// Which part of the business is being configured, in the query the settings
/// endpoints already take.
Map<String, String> sendingIdentityScope(Session session) {
  final me = session.me!;

  return {
    'scope_type': me.scope.type,
    // A franchise or company carries its own id; the organization's scope has
    // none of its own and is named by the organization.
    'scope_id': me.scope.id ?? me.organization.id,
  };
}

/// Where a cascaded value came from — the one sentence, in one place.
///
/// This is the settings screen's own wording, lifted out of its row so that
/// this feature cannot grow a second dialect of it. The failure it guards
/// against is not cosmetic: somebody who cannot tell "this franchise's own
/// address" from "the company's address, showing through" will edit what they
/// take to be the franchise and change every place under that company.
///
/// Three states, and the third is not a rounding of the second. `source` null
/// means nothing is set anywhere in the cascade and what is on screen is the
/// platform's, which is a different fact from inheriting a value a person in
/// this organization chose.
String provenanceSentence(Map<String, dynamic>? source) {
  if (source == null) return 'EzBizi default';

  return source['inherited'] == true
      ? 'Inherited from ${source['name']}'
      : 'Set here';
}

/// True when the value on screen was chosen at the scope being looked at.
bool sourceIsOwn(Map<String, dynamic>? source) =>
    source != null && source['inherited'] != true;

/// A value that saves, audits and inherits like any other, and that nothing
/// reads yet.
///
/// Same sentence as the settings rows use, for the same reason: a setting that
/// silently does nothing is the complaint the whole area exists to answer, and
/// two different phrasings of the admission would read as two different states.
String notInEffectSentence(String reason) => 'Not in effect yet — $reason';

/// The card on the settings screen: what is in force, where it came from, and
/// whether it works.
///
/// Given its answer rather than fetching one, because the settings screen asks
/// once per scope and this card is filtered in and out of the list every time
/// somebody types in the search box.
class SendingIdentitySection extends StatelessWidget {
  const SendingIdentitySection({
    super.key,
    required this.api,
    required this.session,
    required this.identity,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;

  /// The object all four routes answer with — see [sendingIdentityPath].
  final Map<String, dynamic> identity;

  /// A write inside the panel changes what this card says, and the screen above
  /// it holds the copy, so the new state travels back up rather than being
  /// fetched again.
  final ValueChanged<Map<String, dynamic>> onChanged;

  /// The words above the card, written once because the settings screen's
  /// search box matches on them — somebody typing "email" is looking for
  /// exactly this and must not have to scroll past thirty rows to find it.
  static const String heading = 'Email from this business';

  static const String note =
      'Newsletters and anything else your own people write go out from this '
      'address. Signing in, password resets and joining messages always come '
      'from EzBizi, whatever is set here.';

  /// Does the settings search box's query touch this section?
  ///
  /// The last two are searched but are not on the card — "DNS records" is a
  /// heading inside the panel, and "sending identity" appears nowhere at all.
  /// Somebody handed "add these DNS records" by a support article is looking
  /// for this, and the words they were given are not the words they will read.
  static bool matchesSearch(String query) {
    final needle = query.trim().toLowerCase();

    if (needle.isEmpty) return true;

    return [
      heading,
      note,
      'DNS records',
      'sending identity',
    ].any((text) => text.toLowerCase().contains(needle));
  }

  Future<void> _open(BuildContext context) => showSidePanel<void>(
    context,
    builder: (_) => SendingIdentityPanel(
      api: api,
      session: session,
      identity: identity,
      onChanged: onChanged,
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final source = identity['source'] as Map<String, dynamic>?;
    final own = sourceIsOwn(source);
    final address = identity['from_address'] as String?;
    final pending = identity['not_yet_in_effect'] as String?;

    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          address == null || address.isEmpty ? 'Nothing set' : address,
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 2),
        // Same words and same size as every settings row above it, because it
        // is the same question about the same cascade.
        Text(
          provenanceSentence(source),
          style: theme.textTheme.bodyMedium?.copyWith(fontSize: 12),
        ),
        const SizedBox(height: 10),
        VerificationLine(
          verification: identity['verification'] as Map<String, dynamic>?,
        ),
        const SizedBox(height: 10),
        Text(note, style: theme.textTheme.bodySmall),
        if (pending != null && pending.trim().isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            notInEffectSentence(pending),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.tertiary,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ],
    );

    final control = OutlinedButton.icon(
      onPressed: () => _open(context),
      icon: const Icon(Icons.alternate_email, size: 18),
      // Never refused. Reading which address is in force, and why it is not
      // working, is not a privilege — the panel is where the refusal to CHANGE
      // it is stated, in the server's own sentence.
      label: Text(own ? 'Change' : 'Set one up'),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 22, 4, 8),
          child: Text(
            heading,
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: LayoutBuilder(
              // The control drops under the text on a narrow window rather than
              // squeezing it into a column two words wide — the rule PanelHeader
              // learned twice, and this has to survive a phone (R7).
              builder: (context, constraints) => constraints.maxWidth < 520
                  ? Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        text,
                        const SizedBox(height: 12),
                        Align(alignment: Alignment.centerLeft, child: control),
                      ],
                    )
                  : Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: text),
                        const SizedBox(width: 16),
                        control,
                      ],
                    ),
            ),
          ),
        ),
      ],
    );
  }
}

/// The verification state, as an icon and the server's own word for it.
///
/// ── Why the words are not written here ───────────────────────────
///
/// Because "Pending" and "Broken" are the same colour of nothing to somebody
/// who has just added a DNS record and is waiting for it to propagate, and only
/// the server knows which of those is true. So the client picks the ICON and
/// the colour — a visual decision it has to make — and prints whatever the
/// server called the state. A state this build has never heard of still renders
/// its label, under a neutral mark, rather than disappearing.
///
/// Never colour alone. The icon and the word carry it, so it survives a
/// screenshot in greyscale and a screen reader alike.
class VerificationLine extends StatelessWidget {
  const VerificationLine({super.key, required this.verification, this.detailed = false});

  final Map<String, dynamic>? verification;

  /// The panel shows the sentence under the state; the card shows only the
  /// state, because the card is a summary and the sentence is a paragraph.
  final bool detailed;

  @override
  Widget build(BuildContext context) {
    final verification = this.verification;

    if (verification == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final look = _look(verification['state'] as String?, theme.colorScheme);
    final label = verification['label'] as String?;
    final detail = verification['detail'] as String?;

    if (label == null || label.trim().isEmpty) return const SizedBox.shrink();

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(look.icon, size: 16, color: look.colour),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: look.colour,
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (detailed && detail != null && detail.trim().isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(detail, style: theme.textTheme.bodySmall),
              ],
            ],
          ),
        ),
      ],
    );
  }

  ({IconData icon, Color colour}) _look(String? state, ColorScheme scheme) =>
      switch (state) {
        'verified' => (icon: Icons.verified_outlined, colour: scheme.primary),
        // The same tertiary the settings rows use for "not in effect": a state
        // that is working as intended and is not finished. Not the error
        // colour, which would say somebody has done something wrong.
        'pending' => (icon: Icons.schedule, colour: scheme.tertiary),
        'failed' => (icon: Icons.error_outline, colour: scheme.error),
        _ => (
          icon: Icons.info_outline,
          colour: scheme.onSurfaceVariant,
        ),
      };
}

/// The panel: read the state, copy the records, set an address for this scope.
class SendingIdentityPanel extends StatefulWidget {
  const SendingIdentityPanel({
    super.key,
    required this.api,
    required this.session,
    required this.identity,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;

  /// What the card already fetched. The panel opens on it rather than on a
  /// spinner, and replaces it with whatever a write answers.
  final Map<String, dynamic> identity;

  final ValueChanged<Map<String, dynamic>> onChanged;

  @override
  State<SendingIdentityPanel> createState() => _SendingIdentityPanelState();
}

class _SendingIdentityPanelState extends State<SendingIdentityPanel> {
  late Map<String, dynamic> _identity = widget.identity;

  late final TextEditingController _address = TextEditingController(
    text: _own ? _identity['from_address'] as String? ?? '' : '',
  );
  late final TextEditingController _name = TextEditingController(
    text: _own ? _identity['from_name'] as String? ?? '' : '',
  );

  /// Whatever the last write was refused with, in the server's words.
  String? _error;
  bool _busy = false;

  Map<String, dynamic>? get _source =>
      _identity['source'] as Map<String, dynamic>?;

  /// This scope holds its own address, rather than showing an ancestor's.
  bool get _own => sourceIsOwn(_source);

  Map<String, dynamic>? get _verification =>
      _identity['verification'] as Map<String, dynamic>?;

  bool get _mayEdit => _identity['may_edit'] as bool? ?? true;

  /// Why not — the server's sentence, never one composed here. It is the same
  /// one the write would be refused with, so a dead control and a rejected save
  /// cannot explain themselves differently (R8).
  String? get _refusal => _mayEdit ? null : _identity['may_edit_reason'] as String?;

  /// The place being configured — "Connecticut", not "Everdoors › Connecticut".
  String get _here => widget.session.me!.scope.label;

  @override
  void dispose() {
    _address.dispose();
    _name.dispose();
    super.dispose();
  }

  /// Adopt whatever a write answered with, or say why it was refused.
  ///
  /// The panel stays open either way. A save that produces the DNS records is
  /// the moment somebody most needs to be looking at them, and a refusal that
  /// closed the panel would take the half-typed address with it.
  void _adopt(ApiResponse response) {
    if (!mounted) return;

    setState(() {
      _busy = false;

      if (!response.ok) {
        // `reason` rather than `message`: a 422 carries the sentence somebody
        // can act on under `errors`, and the top-level one is "The given data
        // was invalid."
        _error = response.reason;
        return;
      }

      final data = response.data;

      if (data is! Map<String, dynamic>) {
        _error =
            'The server answered with something this screen could not read. '
            'Close this and open it again to see what was saved.';
        return;
      }

      _error = null;
      _identity = data;
      _address.text = _own ? data['from_address'] as String? ?? '' : '';
      _name.text = _own ? data['from_name'] as String? ?? '' : '';
    });

    if (response.ok && _error == null) widget.onChanged(_identity);
  }

  /// Set an address for THIS scope.
  ///
  /// The scope in the query is the one being looked at, always. That is what
  /// makes it structurally impossible for this panel to edit a parent's
  /// address: a franchise reading "Inherited from A-1 Chimney" and pressing
  /// Save creates the franchise's own, and A-1 Chimney keeps what it had.
  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    _adopt(
      await widget.api.patch(
        sendingIdentityPath,
        {
          'from_address': _address.text.trim(),
          'from_name': _name.text.trim(),
        },
        sendingIdentityScope(widget.session),
      ),
    );
  }

  /// Drop this scope's own address and fall back to whatever is above it.
  Future<void> _clear() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    _adopt(
      await widget.api.delete(
        sendingIdentityPath,
        sendingIdentityScope(widget.session),
      ),
    );
  }

  /// Ask the server to look at the DNS again now.
  ///
  /// The scope travels in the body because ApiClient's `post` takes no query
  /// string, and adding one is a change to a file this work does not own.
  Future<void> _check() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    _adopt(
      await widget.api.post(
        sendingIdentityCheckPath,
        sendingIdentityScope(widget.session),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final refusal = _refusal;
    final records = (_verification?['records'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return SidePanel(
      title: SendingIdentitySection.heading,
      subtitle: widget.session.me!.scope.fullPath,
      width: 520,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
        if (_own)
          Refused(
            reason: refusal,
            child: OutlinedButton(
              onPressed: _busy || refusal != null ? null : _clear,
              child: Text(
                // At the organization there is nothing above to fall back to,
                // so clearing lands on the platform's address rather than on a
                // parent's, and the button should say which.
                widget.session.me!.scope.type == 'organization'
                    ? 'Use the EzBizi default'
                    : 'Use the inherited address',
              ),
            ),
          ),
        Refused(
          reason: refusal,
          child: FilledButton(
            onPressed: _busy || refusal != null ? null : _save,
            child: Text(_busy ? 'Saving…' : 'Save'),
          ),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _inForce(theme),
          const SizedBox(height: 20),
          const Divider(),
          const SizedBox(height: 16),
          _verificationBlock(theme, records),
          const SizedBox(height: 20),
          const Divider(),
          const SizedBox(height: 16),
          _form(theme, refusal),
          _footnotes(theme),
        ],
      ),
    );
  }

  // ── what is in force, and whose it is ────────────────────────────

  Widget _inForce(ThemeData theme) {
    final address = _identity['from_address'] as String?;
    final name = _identity['from_name'] as String?;
    final source = _source;
    final inherited = source != null && source['inherited'] == true;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Mail goes out as', style: theme.textTheme.bodySmall),
        const SizedBox(height: 4),
        SelectableText(
          address == null || address.isEmpty ? 'Nothing set' : address,
          style: theme.textTheme.titleMedium,
        ),
        if (name != null && name.trim().isNotEmpty) ...[
          const SizedBox(height: 2),
          Text('Shown as “$name”', style: theme.textTheme.bodySmall),
        ],
        const SizedBox(height: 6),
        Text(
          provenanceSentence(source),
          style: theme.textTheme.bodyMedium?.copyWith(fontSize: 12),
        ),
        if (inherited) ...[
          const SizedBox(height: 8),
          // The sentence this whole panel exists for. Somebody who reads
          // "info@a-1chimney.com" on a franchise and assumes it is the
          // franchise's will change it here expecting to fix one place.
          Text(
            'That address belongs to ${source['name']}, not to $_here. Setting '
            'one below gives $_here its own; ${source['name']} keeps the one it '
            'has, and so does everywhere else under it.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  // ── does it work ─────────────────────────────────────────────────

  Widget _verificationBlock(
    ThemeData theme,
    List<Map<String, dynamic>> records,
  ) {
    final verification = _verification;

    if (verification == null) {
      return Text(
        'There is nothing to verify while mail goes out as EzBizi.',
        style: theme.textTheme.bodySmall,
      );
    }

    final nextStep = verification['next_step'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        VerificationLine(verification: verification, detailed: true),
        if (records.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('DNS records', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          for (final record in records) _record(theme, record),
        ],
        if (nextStep != null && nextStep.trim().isNotEmpty) ...[
          const SizedBox(height: 12),
          Text(nextStep, style: theme.textTheme.bodySmall),
        ],
        if (records.isNotEmpty) ...[
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              onPressed: _busy ? null : _check,
              icon: const Icon(Icons.refresh, size: 18),
              label: Text(_busy ? 'Checking…' : 'Check now'),
            ),
          ),
        ],
      ],
    );
  }

  /// One DNS record, stacked.
  ///
  /// Every value gets the full width of the panel and wraps inside it. A row of
  /// monospace values — type, host, and a DKIM key that is three hundred
  /// characters with no spaces in it — is the exact shape that overflowed
  /// PanelHeader twice this week, and at 375px there is no arrangement of it
  /// that fits side by side (R7).
  Widget _record(ThemeData theme, Map<String, dynamic> record) {
    final type = record['type'] as String?;
    final found = record['found'];

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  type == null || type.isEmpty ? 'Record' : '$type record',
                  style: theme.textTheme.labelSmall,
                ),
              ),
              // Said in words, not only by a colour or a tick, and only when
              // the server actually said it.
              if (found is bool)
                Text(
                  found ? 'Found' : 'Not there yet',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: found
                        ? theme.colorScheme.primary
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          _copyable(theme, 'Name', record['name'] as String?),
          _copyable(theme, 'Value', record['value'] as String?),
        ],
      ),
    );
  }

  Widget _copyable(ThemeData theme, String label, String? value) {
    if (value == null || value.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
              IconButton(
                // Reachable by keyboard and by a screen reader, not only by a
                // mouse that can find a 20px target (R7). The text below is
                // selectable as well, for anybody who would rather drag.
                tooltip: 'Copy the $label',
                iconSize: 18,
                visualDensity: VisualDensity.compact,
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: value));

                  if (!mounted) return;

                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('$label copied'),
                      behavior: SnackBarBehavior.floating,
                      width: 320,
                    ),
                  );
                },
                icon: const Icon(Icons.copy_outlined),
              ),
            ],
          ),
          // Full width, so a long key wraps down the panel instead of pushing
          // the panel sideways.
          SelectableText(
            value,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
          ),
        ],
      ),
    );
  }

  // ── setting one here ─────────────────────────────────────────────

  Widget _form(ThemeData theme, String? refusal) {
    final inheritedAddress = _own ? null : _identity['from_address'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          _own ? 'The address for $_here' : 'Set an address for $_here',
          style: theme.textTheme.titleSmall,
        ),
        if (refusal != null) ...[
          const SizedBox(height: 8),
          // On screen, not only in a tooltip. A panel has the room, and the
          // sentence is the whole of what somebody stuck here needs.
          Text(
            refusal,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
        const SizedBox(height: 12),
        TextField(
          controller: _address,
          enabled: refusal == null && !_busy,
          keyboardType: TextInputType.emailAddress,
          decoration: InputDecoration(
            labelText: 'The address mail comes from',
            // What it would keep on doing if this is left empty, rather than a
            // greyed copy of the parent's address sitting in the box looking
            // like something this place has already chosen.
            hintText: inheritedAddress,
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _name,
          enabled: refusal == null && !_busy,
          decoration: const InputDecoration(
            labelText: 'The name people see it from',
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_error!),
        ],
      ],
    );
  }

  // ── the two things the server, not this end, gets to say ─────────

  Widget _footnotes(ThemeData theme) {
    final transport = _identity['transport'] as Map<String, dynamic>?;
    final pending = _identity['not_yet_in_effect'] as String?;
    final automation = _identity['automation'] as Map<String, dynamic>?;
    final emits = (automation?['emits'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map((e) => e['label'] as String?)
        .whereType<String>()
        .toList();
    final automationNote = automation?['note'] as String?;

    final lines = <Widget>[
      if (transport?['label'] is String)
        Text(transport!['label'] as String, style: theme.textTheme.bodySmall),
      if (pending != null && pending.trim().isNotEmpty)
        Text(
          notInEffectSentence(pending),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.tertiary,
            fontWeight: FontWeight.w500,
          ),
        ),
      // Every feature wires into automations, and what this one offers is the
      // server's answer rather than a list compiled in here — including when
      // the answer is a sentence saying it offers nothing yet and why.
      if (emits.isNotEmpty)
        Text(
          'Automations can run on: ${emits.join(', ')}.',
          style: theme.textTheme.bodySmall,
        ),
      if (emits.isEmpty && automationNote != null && automationNote.isNotEmpty)
        Text(automationNote, style: theme.textTheme.bodySmall),
    ];

    if (lines.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Divider(),
          const SizedBox(height: 12),
          for (final line in lines)
            Padding(padding: const EdgeInsets.only(bottom: 6), child: line),
        ],
      ),
    );
  }
}
