import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/cascade_words.dart';
import '../core/session.dart';
import '../widgets/search_picker.dart';
import 'disconnect_panel.dart';

/// Where a business connects the mail server its own mail goes out through.
///
/// Jose's ruling of 2026-09-14 is why this screen exists at all and why the one
/// it replaced was deleted. His ruling of 2026-09-16 — "Email ( the Ezbizi SES
/// one, not the company one )" — then moved reports and automations onto
/// EzBizi's own SES, and NOTHING has called
/// `MailDispatcher::throughTenantProvider` since. So this card stores a mail
/// server that nothing sends through. Its copy said otherwise until 2026-09-22;
/// see `purpose` below.
///
/// ── NOT the sending-identity panel ──────────────────────────────
///
/// That card asked a business to prove a DOMAIN so EzBizi's SES would sign as
/// them. It went on his "GET RID OF IT" and none of it is back. This asks for
/// their own server: their transport, our SES not in the path.
///
/// ── Four states, and the other three are deliberately absent ────
///
/// Drawn by UI/UX as desktop 2 / mobile 5, chosen by Jose ("1 - as you
/// recommend"). The gallery carries ten states; six can be rendered truthfully
/// today and the rest cannot, so they are not here:
///
///   NOT BUILT — refused (5xx) · unreachable · not ready (4xx). Nothing sends
///   from a screen, and until a send has been attempted there is no SMTP reply
///   code to sort. A state that can only appear against invented fixtures tests
///   green and tells nobody it has never once been rendered by real data.
///
///   NOT BUILT — copied · inherited-and-copied · copied-then-stale. The copy
///   line names a source mailbox, a date and an actor, and `connectServer`
///   records none of them. The stale line is worse than unstored: detecting it
///   means comparing this credential against the mailbox it came from, and with
///   no source recorded there is nothing to compare against. A missing
///   RELATIONSHIP, not a missing column. There is also nothing to copy from —
///   every production mailbox is Google Workspace, which carries no SMTP host.
///
/// The "These details" row goes with them. UI/UX retracted it themselves and
/// the reasoning is the good part: with copying parked it could only ever say
/// "Typed here", on every identity, forever — and nothing records that a value
/// was typed. It would say so because there is nothing else left to say, which
/// is a described sentence wearing a derived one's clothes. It returns when a
/// copy flow arrives carrying its own storage.
class SendingServerPanel extends StatefulWidget {
  const SendingServerPanel({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  /// The note that keeps this screen from overclaiming, in UI/UX's words.
  ///
  /// It says what the screen DOES report now that `last_attempted_at` exists —
  /// "when it was last tried" — rather than the weaker "does not report whether
  /// sending works", which undersold it once the column landed. And "here"
  /// rather than "anywhere", because nobody has checked every surface and
  /// "here" is the claim that can be stood behind.
  ///
  /// It comes OUT the day the three failure states go in. Same column, opposite
  /// direction: they arrive when there is an outcome recorded to render.
  static const String notReportedYet =
      'This screen shows what is set up and when it was last tried — not '
      'whether it worked. A failed send is not reported here yet.';

  static const String heading = 'Mail server for sending';

  /// MEASURED, 2026-09-22, and it used to say the opposite.
  ///
  /// This read "Saved reports, inspection reports and automations that email
  /// somebody go out through this", which was true the day it shipped and
  /// stopped being true on 2026-09-16 when Jose moved that traffic onto
  /// EzBizi's own SES. Nothing calls `MailDispatcher::throughTenantProvider`
  /// any more — grep it, the only hits are its own definition and comments
  /// about it — so a business could type its mail server password in here and
  /// be told its reports leave through it while every one of them left from
  /// ezbizi.com. The copy is what changed under the feature, not the feature.
  static const String purpose =
      'Nothing sends through this yet. Saved reports, inspection reports and '
      'automations all go out from EzBizi at the moment, and so do signing in, '
      'password resets and joining messages.';

  /// No consequence clause, because there is no consequence. Connecting a
  /// server here changes nothing that is sent today, so a line claiming mail
  /// is held back by its absence would be the same false claim as the one
  /// above, phrased as an empty state.
  static const String nothingConnected = 'Nothing is connected.';

  /// Does the search box on the settings screen match this card?
  static bool matchesSearch(String term) {
    final needle = term.trim().toLowerCase();

    if (needle.isEmpty) return true;

    return const [
      'mail server',
      'sending',
      'smtp',
      'reports',
      'email',
    ].any((word) => word.contains(needle) || needle.contains(word));
  }

  @override
  State<SendingServerPanel> createState() => _SendingServerPanelState();
}

class _SendingServerPanelState extends State<SendingServerPanel> {
  static const String _path = '/mail/sending-server';

  Map<String, dynamic>? _answer;
  bool _loading = true;
  bool _editing = false;
  bool _saving = false;
  String? _error;

  final _fromAddress = TextEditingController();
  final _fromName = TextEditingController();
  final _host = TextEditingController();
  final _username = TextEditingController();
  final _password = TextEditingController();
  int _port = 587;
  String _encryption = 'tls';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _fromAddress.dispose();
    _fromName.dispose();
    _host.dispose();
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  /// The scope this screen is looking at, as query parameters.
  ///
  /// The same pair `/settings` takes, because it is the same cascade — a card
  /// that asked a different question about "which level" would be the second
  /// dialect this screen exists to prevent.
  Map<String, String> get _scope {
    final me = widget.session.me;

    return {
      'scope_type': me?.scope.type ?? 'organization',
      'scope_id': me?.scope.id ?? me?.organization.id ?? '',
    };
  }

  Future<void> _load() async {
    final response = await widget.api.get(_path, _scope);

    if (!mounted) return;

    /*
     * Anything that is not the object this card understands draws NOTHING —
     * not a card full of blanks, and not a card built from somebody else's
     * payload. `is Map` was not enough: a settings response is also a map, and
     * in the settings screen's own tests this card was rendering an empty state
     * out of one, which moved four of their counts from 1 to 2.
     *
     * Keyed on `in_effect` because that is the field only this endpoint
     * produces and it is present in every one of its answers, including the
     * empty one. `connected_here` is null in the commonest state, so a check on
     * it would reject the very case the card exists to draw.
     */
    final body = response.data;

    setState(() {
      _loading = false;
      _answer =
          response.ok &&
              body is Map<String, dynamic> &&
              body['in_effect'] is Map<String, dynamic>
          ? body
          : null;
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    /*
     * The scope travels in the BODY here and in the query everywhere else,
     * because ApiClient.put carries no query string — patch and delete do, put
     * and post do not. The server reads either: `$request->validate()` merges
     * query and body, so the controller is unchanged and there is no second
     * route for the client's convenience.
     *
     * The deleted sending-identity routes chose PATCH for exactly this reason
     * and said so. PUT is still right here — the body is the whole connection,
     * five fields that only mean anything together, and a partial update of an
     * SMTP connection is one that cannot be dialled.
     */
    final response = await widget.api.put(_path, {
      ..._scope,
      'from_address': _fromAddress.text.trim(),
      'from_name': _fromName.text.trim(),
      'host': _host.text.trim(),
      'port': _port,
      'username': _username.text.trim(),
      'password': _password.text,
      'encryption': _encryption,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = _sentenceFrom(response);
      });

      return;
    }

    // The password is never read back, so it does not survive into the form. A
    // field still holding it after a save invites somebody to believe the
    // screen can show it, which it cannot and should not.
    _password.clear();

    setState(() {
      _saving = false;
      _editing = false;
    });

    await _load();
  }

  /// The server's own sentence, never a status code dressed as one.
  ///
  /// Every refusal this endpoint produces is written to be read by whoever
  /// typed the value — "the mail server saved for this business has no host",
  /// "is not a mail server EzBizi can send through". Replacing them with
  /// "Something went wrong" would throw away the only part that tells somebody
  /// what to do.
  String _sentenceFrom(ApiResponse response) {
    final body = response.body;

    for (final key in ['message', 'error']) {
      final value = body[key];
      if (value is String && value.trim().isNotEmpty) return value;
    }

    return 'That could not be saved. Check the details and try again.';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const SizedBox.shrink();

    final answer = _answer;

    if (answer == null) return const SizedBox.shrink();

    final connected = answer['connected_here'] as Map<String, dynamic>?;
    final mayEdit = answer['may_edit'] as bool? ?? false;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              SendingServerPanel.heading,
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            Text(
              SendingServerPanel.purpose,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 12),
            if (_editing)
              ..._form(context, mayEdit)
            else if (connected == null)
              ..._empty(context, answer, mayEdit)
            else
              ..._connected(context, answer, connected, mayEdit),
            /*
             * DERIVED, so the contradiction is unrepresentable rather than
             * merely discouraged. UI/UX's idea and the argument that decided it:
             * rendered unconditionally, the admission relies on whoever adds the
             * first failure state reading a note and deleting a sentence
             * elsewhere — the step that gets forgotten, because adding the state
             * is the work they set out to do. Derived, adding the outcome makes
             * the sentence disappear by itself.
             *
             * THE KEY IS A GUESS AND THAT IS SAID OUT LOUD. If the field is
             * called something else the sentence persists exactly as it would
             * have without this check — so a wrong guess degrades to the
             * previous behaviour rather than to something worse, which is why
             * the check is never a bad trade. What it must NOT do is read as a
             * guarantee: it is not one.
             *
             * `last_result` rather than a name picked from nothing, because it
             * pairs with `last_attempted_at`, which already exists and which the
             * failure states will be read alongside. A convention, not an
             * invention.
             *
             * And the reason this beats a `reportsOutcome` constant, which I
             * weighed: whoever adds outcomes writes code READING outcome data,
             * and will find another place reading outcome data six lines away.
             * A constant in the class header is only found by somebody looking
             * for it.
             */
            if (!_reportsOutcome(connected)) ...[
              const SizedBox(height: 12),
              Text(
                SendingServerPanel.notReportedYet,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Does the answer carry an outcome — did a send WORK, not merely happen?
  ///
  /// False for every payload this build can receive, which is the point: the
  /// admission that this screen does not report whether sending worked is true
  /// exactly while this is false, so the two cannot drift apart by one of them
  /// being edited.
  bool _reportsOutcome(Map<String, dynamic>? connected) =>
      connected != null && connected['last_result'] != null;

  // ── the four states ──────────────────────────────────────────

  List<Widget> _empty(
    BuildContext context,
    Map<String, dynamic> answer,
    bool mayEdit,
  ) {
    final theme = Theme.of(context);
    final effective = answer['in_effect'] as Map<String, dynamic>?;
    final inherited = effective?['inherited'] as bool? ?? false;

    // Inherited is NOT "nothing connected". A branch that holds nothing sends
    // through whatever its company set, and telling it that nothing is going
    // out would be false — it is the parent's server doing the sending.
    if (inherited) {
      return [
        _fact(context, 'Used here', _provenance(effective)),
        const SizedBox(height: 8),
        Text(
          'Change it there, or set this business’s own.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        if (mayEdit) ...[
          const SizedBox(height: 10),
          OutlinedButton(
            onPressed: () => setState(() => _editing = true),
            child: const Text('Set this business’s own'),
          ),
        ],
      ];
    }

    return [
      Text(
        SendingServerPanel.nothingConnected,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.error,
          fontWeight: FontWeight.w600,
        ),
      ),
      if (mayEdit) ...[
        const SizedBox(height: 10),
        FilledButton(
          onPressed: () => setState(() => _editing = true),
          child: const Text('Connect a server'),
        ),
      ],
    ];
  }

  List<Widget> _connected(
    BuildContext context,
    Map<String, dynamic> answer,
    Map<String, dynamic> connected,
    bool mayEdit,
  ) {
    final theme = Theme.of(context);
    final host = connected['host'] as String? ?? '';
    final port = connected['port'];
    final username = connected['username'] as String? ?? '';
    final neverTried = connected['never_tried'] as bool? ?? true;

    return [
      _fact(
        context,
        'Used here',
        _provenance(answer['in_effect'] as Map<String, dynamic>?),
      ),
      const SizedBox(height: 10),
      _fact(context, 'Server', '$host : $port', under: username),
      const SizedBox(height: 10),

      // Derived from last_attempted_at, not asserted. Saying "never tried" as a
      // fact about every row was the trap UI/UX found: it is true today and
      // goes false in another module, so nothing here would have to change for
      // the sentence to start lying.
      Text(
        neverTried
            ? 'Saved. Nothing has been sent through it yet.'
            : 'A send has been attempted through this server.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
      if (mayEdit) ...[
        const SizedBox(height: 12),
        Row(
          children: [
            OutlinedButton(
              onPressed: () => setState(() => _editing = true),
              child: const Text('Change'),
            ),
            const SizedBox(width: 8),
            TextButton(
              onPressed: _saving ? null : _disconnect,
              child: const Text('Disconnect'),
            ),
          ],
        ),
      ],
    ];
  }

  /// The cascade's own words, and not a second dialect of them.
  ///
  /// `Set here` / `Inherited from {name}` / `EzBizi default` are the settings
  /// screen's, and its docblock warns that the third is not a rounding of the
  /// second: nothing set anywhere is a different fact from inheriting a value
  /// somebody in this organization chose.
  String _provenance(Map<String, dynamic>? effective) => provenanceWords(
    effective?['source_name'] as String?,
    inherited: effective?['inherited'] as bool? ?? false,
  );

  Widget _fact(
    BuildContext context,
    String label,
    String value, {
    String? under,
  }) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 104,
          child: Text(
            label,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(value, style: theme.textTheme.bodyMedium),
              if (under != null && under.isNotEmpty)
                Text(
                  under,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  // ── connecting one ───────────────────────────────────────────

  List<Widget> _form(BuildContext context, bool mayEdit) {
    final theme = Theme.of(context);

    return [
      _field('Address mail comes from', _fromAddress),
      _field('Name shown to recipients', _fromName),
      _field('Mail server', _host, hint: 'smtp.example.com'),
      Row(
        children: [
          Expanded(
            child: SearchPickerField<int>(
              value: _port,
              label: 'Port',
              // 587 and 465 only, matching the server. 25 is relay rather than
              // submission and accepting it would let this form reach a class
              // of machine the submission ports do not.
              options: const [
                SearchOption(value: 587, label: '587 — STARTTLS'),
                SearchOption(value: 465, label: '465 — TLS'),
              ],
              onChanged: (value) => setState(() => _port = value ?? 587),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: SearchPickerField<String>(
              value: _encryption,
              label: 'Encryption',
              options: const [
                SearchOption(value: 'tls', label: 'TLS'),
                SearchOption(value: 'ssl', label: 'SSL'),
              ],
              onChanged: (value) =>
                  setState(() => _encryption = value ?? 'tls'),
            ),
          ),
        ],
      ),
      _field('User name', _username),
      _field('Password', _password, obscure: true),
      if (_error != null) ...[
        const SizedBox(height: 8),
        Text(
          _error!,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.error,
          ),
        ),
      ],
      const SizedBox(height: 12),
      Row(
        children: [
          FilledButton(
            onPressed: _saving || !mayEdit ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save'),
          ),
          const SizedBox(width: 8),
          TextButton(
            onPressed: _saving
                ? null
                : () => setState(() {
                    _editing = false;
                    _error = null;
                  }),
            child: const Text('Cancel'),
          ),
        ],
      ),
    ];
  }

  Widget _field(
    String label,
    TextEditingController controller, {
    String? hint,
    bool obscure = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextField(
        controller: controller,
        obscureText: obscure,
        decoration: InputDecoration(labelText: label, hintText: hint),
      ),
    );
  }

  Future<void> _disconnect() async {
    // The server requires an authenticator code for every disconnect. Mail
    // falls back to the level above, so there is no dependents list here.
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Remove this mail server?',
      explanation:
          'Mail from this level goes out through whatever the level above '
          'has set, or EzBizi’s own sender.',
      perform: (code) =>
          widget.api.deleteWithBody(_path, {..._scope, 'code': code}),
    );

    if (!mounted || done == null) return;

    setState(() => _error = null);

    await _load();
  }
}
