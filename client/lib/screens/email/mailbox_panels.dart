import 'dart:async';

import 'package:flutter/material.dart';

import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'consent_window.dart';
import 'mailbox_settings.dart';

/// The three acts that set a shared inbox up, each in a side panel.
///
/// ── Why panels and not a screen ─────────────────────────────────
///
/// The list behind is the context: which addresses already exist, at which
/// level, and which of them is broken. Adding a second `info@` under a company
/// that already has one is the mistake this module has hit, and it is a mistake
/// somebody makes with the list hidden behind a form. A panel keeps it beside
/// them. No pop-ups — this is the app's answer to a modal, and
/// widgets/side_panel.dart argues it out.
///
/// ── The credential goes one way, and this file is where that is kept ──
///
/// [ConnectMailboxPanel] holds the only credential field in the client. It is
/// an empty box for a NEW value and never a box showing a stored one: the
/// server has no path that hands the value back — `Mailbox::mayReveal` answers
/// false for everybody, including the administrator who typed it — so there is
/// nothing to show even if this screen wanted to. The field is `obscureText`,
/// carries no autofill hint, and its contents are posted and then dropped with
/// the panel's State.
///
/// ── Two ways to finish, and the server decides which ─────────────
///
/// A provider is finished either by SIGNING IN at the mail service, or by
/// handing over a password and the address of a server. [ConnectMailboxPanel]
/// draws one or the other, and it asks the server both questions rather than
/// deducing either:
///
///   - `usable` — may anything live be offered at all. This is the flag that
///     exists because this screen once inferred readiness from `needs_host` and
///     drew a live password form for a transport the server cannot speak;
///   - `needs_host` — WHICH of the two, which is the question that property has
///     always answered and the only one it is asked here.
///
/// Nothing in this file names Google. The button says what the server called the
/// service, so a second provider finished by signing in needs no change here.
///
/// ── Neither half of this emits an automation event ───────────────
///
/// House rule: every feature wires into automations or declares why it has none.
/// This is the CLIENT half of connecting an inbox — a form and a redirect — and
/// nothing happens here that an organization could hang a rule off. What is
/// worth reacting to is a mailbox becoming connected or stopping working, and
/// both of those are facts the SERVER establishes: it is the server that stores
/// the token, marks the status and would raise the event. A client-side event
/// would fire on the screen of whoever happened to be looking, which is not what
/// "when an inbox is connected" means.

/// Add an address. It arrives DISCONNECTED, which is the server's design:
/// naming the inbox and handing over its key are two acts, and separating them
/// is what lets the second one be a different shape per provider.
///
/// Returns true when something was added, so the caller can refetch.
class AddMailboxPanel extends StatefulWidget {
  const AddMailboxPanel({
    super.key,
    required this.api,
    required this.session,
    required this.providers,
    required this.imapPorts,
    required this.smtpPorts,
  });

  final ApiClient api;
  final Session session;

  /// The ways an address can be set up, exactly as the server published them.
  /// Nothing here knows there is a service called Google.
  final List<ProviderOption> providers;

  /// The ports the server's own guard will accept. Offered as a choice rather
  /// than as a box somebody types into, so a refusal from the far end is not
  /// the first time anybody hears which ports are allowed.
  final List<int> imapPorts;
  final List<int> smtpPorts;

  @override
  State<AddMailboxPanel> createState() => _AddMailboxPanelState();
}

class _AddMailboxPanelState extends State<AddMailboxPanel> {
  final _form = GlobalKey<FormState>();
  final _address = TextEditingController();
  final _displayName = TextEditingController();
  final _directorySearch = TextEditingController();

  /// Where the mail lives, drawn by [_MailServerFields] rather than by eight
  /// controllers here. The key is how this panel reads the block back out at
  /// submit time; the fields own their own state so the connect panel can draw
  /// exactly the same ones without a second copy of them existing.
  final _server = GlobalKey<_MailServerFieldsState>();

  ProviderOption? _provider;

  bool _saving = false;
  String? _error;

  List<_DirectoryEntry> _directory = const [];
  bool _loadingDirectory = false;

  /// The addresses ticked in the directory. Several at once since 2026-10-06
  /// (Jose: "add ability to add emails by bulk as well, when selecting
  /// workspace"); one is the old single add, unchanged.
  final Set<String> _picked = <String>{};

  /// The one picked address, when exactly one is -- the single-add path.
  String? get _pickedAddress => _picked.length == 1 ? _picked.first : null;

  /// Some of a batch went in and the panel stayed open to report the rest, so
  /// closing it must still tell the list to reload.
  bool _addedSome = false;

  @override
  void initState() {
    super.initState();

    _provider = widget.providers.isEmpty ? null : widget.providers.first;
    _loadDirectoryIfNeeded();
  }

  bool get _fromDirectory => _provider?.connectsBy == 'directory';

  Future<void> _loadDirectoryIfNeeded() async {
    if (!_fromDirectory) {
      _directory = const [];
      _picked.clear();
      return;
    }

    setState(() {
      _loadingDirectory = true;
      _error = null;
      _picked.clear();
      _directorySearch.clear();
    });

    final response = await widget.api.get('/email/workspace/directory');

    if (!mounted) return;

    setState(() {
      _loadingDirectory = false;

      if (!response.ok) {
        _error = response.reason;
        _directory = const [];
        return;
      }

      _directory =
          [
                for (final entry
                    in (response.data is List
                        ? response.data as List
                        : const []))
                  if (entry is Map<String, dynamic>)
                    _DirectoryEntry(
                      address: entry['address'] as String? ?? '',
                      name: entry['name'] as String? ?? '',
                    ),
              ]
              .where(
                (e) => e.address.isNotEmpty && !_isGoogleTestAlias(e.address),
              )
              .toList();
    });
  }

  @override
  void dispose() {
    _address.dispose();
    _displayName.dispose();
    _directorySearch.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final provider = _provider;

    if (provider == null || !(_form.currentState?.validate() ?? false)) return;

    if (_fromDirectory && _picked.length > 1) {
      await _saveBatch();
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final me = widget.session.me!;

    final response = await widget.api.post('/email/mailboxes', {
      'scope_type': me.scope.type,
      'scope_id': me.scope.id ?? me.organization.id,
      'address': _fromDirectory ? (_pickedAddress ?? '') : _address.text.trim(),
      'display_name': _displayName.text.trim(),
      'provider': provider.value,
      if (provider.needsHost && _server.currentState != null)
        'mail_server': _server.currentState!.value(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // The server's most specific sentence — a 422's field-level answer
        // where there is one, which is where "that address is already
        // connected" lives.
        _error = response.reason;
      });

      return;
    }

    Navigator.of(context).pop(true);
  }

  /// The name an address is given when it arrives in a batch: its directory
  /// name, or the part before the @ where the directory only has an address.
  String _nameFor(String address) {
    final entry = _directory.where((e) => e.address == address).firstOrNull;
    final name = entry?.name.trim() ?? '';

    return name.isEmpty || name.contains('@') ? address.split('@').first : name;
  }

  /// Several directory addresses in one request. What could not be added is
  /// reported in the server's words and LEFT TICKED, so it can be looked at
  /// rather than lost; what was added is unticked.
  Future<void> _saveBatch() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final me = widget.session.me!;

    final response = await widget.api.post('/email/mailboxes/workspace-batch', {
      'scope_type': me.scope.type,
      'scope_id': me.scope.id ?? me.organization.id,
      'addresses': [
        for (final address in _picked)
          {'address': address, 'display_name': _nameFor(address)},
      ],
    });

    if (!mounted) return;

    final data = response.data;
    final added = [
      for (final row
          in (data is Map ? data['added'] as List? : null) ?? const [])
        if (row is Map) '${row['address']}',
    ];
    final skipped = [
      for (final row
          in (data is Map ? data['skipped'] as List? : null) ?? const [])
        if (row is Map) '${row['address']} — ${row['reason']}',
    ];

    if (response.ok && skipped.isEmpty) {
      Navigator.of(context).pop(true);
      return;
    }

    setState(() {
      _saving = false;
      _addedSome = _addedSome || added.isNotEmpty;
      _picked.removeAll(added);
      _error = skipped.isEmpty
          ? response.reason
          : [
              if (added.isNotEmpty) 'Added ${added.length}.',
              'Not added: ${skipped.join('; ')}',
            ].join(' ');
    });
  }

  void _toggle(_DirectoryEntry entry) {
    setState(() {
      if (!_picked.remove(entry.address)) _picked.add(entry.address);

      // One address picked: the single add, with its name offered as before.
      if (_picked.length == 1 && _displayName.text.trim().isEmpty) {
        _displayName.text = _nameFor(_picked.first);
      }
    });
  }

  Widget _directoryField() {
    if (_loadingDirectory) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 12),
        child: Text('Loading addresses from the connected Workspace…'),
      );
    }

    if (_directory.isEmpty) {
      return const _Note(
        'No user addresses came back from the connected Google Workspace. '
        'Connect the domain under Email settings if it is not connected yet, '
        'and pick a shared inbox like info@ rather than typing an address.',
      );
    }

    final query = _directorySearch.text.trim().toLowerCase();
    final matches = query.isEmpty
        ? const <_DirectoryEntry>[]
        : _directory
              .where(
                (e) =>
                    e.address.contains(query) ||
                    e.name.toLowerCase().contains(query),
              )
              .take(40)
              .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextFormField(
          key: const Key('email-directory-search'),
          controller: _directorySearch,
          autofocus: true,
          enabled: !_saving,
          decoration: InputDecoration(
            labelText: 'Search the directory',
            hintText: 'info@',
            helperText: _picked.isEmpty
                ? 'Type part of an address. Only addresses on the connected '
                      'Workspace can be opened this way. Pick shared inboxes — '
                      'do not pick a person\'s own mailbox. Tick as many as you need.'
                : _picked.length == 1
                ? '${_picked.first} is selected. Search again to add more.'
                : '${_picked.length} addresses selected. Search again to add more.',
            helperMaxLines: 3,
            suffixIcon: _directorySearch.text.isEmpty
                ? const Icon(Icons.search)
                : IconButton(
                    tooltip: 'Clear the search',
                    icon: const Icon(Icons.clear),
                    onPressed: _saving
                        ? null
                        : () => setState(_directorySearch.clear),
                  ),
          ),
          // Typing never clears what is ticked: searching for "office@",
          // ticking, then searching "info@" is the whole point.
          onChanged: (_) => setState(() {}),
          validator: (_) => _picked.isEmpty
              ? 'Pick at least one shared address from the directory.'
              : null,
        ),
        if (_picked.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final address in _picked)
                InputChip(
                  label: Text(address),
                  onDeleted: _saving
                      ? null
                      : () => setState(() => _picked.remove(address)),
                  deleteButtonTooltipMessage: 'Untick $address',
                ),
            ],
          ),
        ],
        if (query.isNotEmpty && matches.isEmpty) ...[
          const SizedBox(height: 8),
          const _Note('No address in the directory matches that.'),
        ],
        if (matches.length > 1) ...[
          const SizedBox(height: 4),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: _saving
                  ? null
                  : () => setState(
                      () => _picked.addAll(matches.map((e) => e.address)),
                    ),
              child: Text('Select all ${matches.length} shown'),
            ),
          ),
        ],
        if (matches.isNotEmpty) ...[
          const SizedBox(height: 4),
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 240),
            child: ListView.builder(
              shrinkWrap: true,
              itemCount: matches.length,
              itemBuilder: (context, index) {
                final entry = matches[index];
                final label = entry.name.isEmpty || entry.name == entry.address
                    ? entry.address
                    : '${entry.address} · ${entry.name}';

                return CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _picked.contains(entry.address),
                  title: Text(label, overflow: TextOverflow.ellipsis),
                  onChanged: _saving ? null : (_) => _toggle(entry),
                );
              },
            ),
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final provider = _provider;

    return SidePanel(
      title: 'Add a shared inbox',
      subtitle:
          'It will belong to ${me.scope.fullPath}. Everything under Email is '
          'kept to one part of the business, so switch where you are working '
          'before adding it if that is not right.',
      actions: [
        TextButton(
          onPressed: _saving
              ? null
              : () => Navigator.of(context).pop(_addedSome),
          child: Text(_addedSome ? 'Done' : 'Cancel'),
        ),
        FilledButton(
          onPressed:
              _saving ||
                  provider == null ||
                  _loadingDirectory ||
                  (_fromDirectory && _picked.isEmpty)
              ? null
              : _save,
          child: Text(
            _saving
                ? 'Adding…'
                : _fromDirectory && _picked.length > 1
                ? 'Add ${_picked.length} addresses'
                : 'Add the address',
          ),
        ),
      ],
      child: Form(
        key: _form,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null) ...[
              ErrorNote(_error!),
              const SizedBox(height: 16),
            ],
            if (_fromDirectory)
              _directoryField()
            else
              TextFormField(
                controller: _address,
                autofocus: true,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'Address',
                  hintText: 'info@example.com',
                  helperText:
                      'The address itself cannot be changed afterwards — every '
                      'grant and every read mark hangs off it.',
                  helperMaxLines: 3,
                ),
                validator: (value) => (value ?? '').trim().isEmpty
                    ? 'Type the address of the inbox you want to share.'
                    : null,
              ),
            const SizedBox(height: 16),
            if (_fromDirectory && _picked.length > 1)
              const _Note(
                'Each address is named after its directory entry. Any of them '
                'can be renamed afterwards.',
              )
            else
              TextFormField(
                controller: _displayName,
                decoration: const InputDecoration(
                  labelText: 'What to call it',
                  hintText: 'Info',
                  helperText: 'The name people see beside the address.',
                  helperMaxLines: 2,
                ),
                validator: (value) => (value ?? '').trim().isEmpty
                    ? 'Give it a name, so it can be told apart from the other '
                          'inboxes on the list.'
                    : null,
              ),
            const SizedBox(height: 16),
            if (widget.providers.isEmpty)
              const ErrorNote(
                'This copy of EzBizi did not say which mail services an address '
                'can be set up on, so there is nothing to choose between yet. '
                'Close this and open it again.',
              )
            else
              SearchPickerField<String>(
                value: provider?.value,
                label: 'Mail service',
                options: [
                  for (final option in widget.providers)
                    SearchOption(value: option.value, label: option.label),
                ],
                onChanged: _saving
                    ? null
                    : (value) {
                        setState(() {
                          _provider = widget.providers
                              .where((option) => option.value == value)
                              .firstOrNull;
                        });
                        _loadDirectoryIfNeeded();
                      },
              ),

            // The server's warning about handing over a password, in the
            // server's words, published so every client says the same thing.
            if (provider?.caution != null) ...[
              const SizedBox(height: 12),
              _Note(provider!.caution!),
            ],

            // The half of this that will not finish here, in the server's own
            // words. Adding the address works whatever the answer — it is the
            // record every grant hangs off — but somebody filling this in
            // deserves to know before they reach the connect panel that the
            // connect panel will have nothing live in it.
            //
            // ── Asked of `usable`, and it used to ask `needs_host` ──
            //
            // The old test was `!provider.needsHost`, meaning "a provider with
            // no server address is one finished by signing in, and signing in is
            // not built". That is the same wrong question the connect panel was
            // already corrected for, and it is wrong in both directions here: it
            // says "not built" about a sign-in the moment somebody registers
            // this installation with the service, and it says NOTHING about an
            // IMAP provider on a server whose PHP cannot open an IMAP socket.
            // The server answers readiness; this prints its answer.
            if (provider != null && !provider.usable) ...[
              const SizedBox(height: 12),
              _NotHereNote(
                provider.unusableBecause ??
                    'EzBizi cannot finish connecting an address on this service '
                        'here, so it will sit on the list waiting to be '
                        'connected and no mail will arrive in it. Adding it now '
                        'is still useful — it is the record everything else '
                        'hangs off.',
              ),
            ],

            if (provider != null && provider.needsHost) ...[
              const SizedBox(height: 24),
              _MailServerFields(
                key: _server,
                imapPorts: widget.imapPorts,
                smtpPorts: widget.smtpPorts,
                enabled: !_saving,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _DirectoryEntry {
  const _DirectoryEntry({required this.address, required this.name});

  final String address;
  final String name;
}

/// Google Workspace mints `user@domain.test-google-a.com` for every account.
/// They are not inboxes, and a picker that lists them looks like every
/// address has a broken duplicate.
bool _isGoogleTestAlias(String address) {
  final at = address.lastIndexOf('@');
  if (at < 0) return false;
  final host = address.substring(at + 1).toLowerCase();

  return host == 'test-google-a.com' || host.endsWith('.test-google-a.com');
}

/// Where the mail lives — asked the same way in both panels that ask it.
///
/// ── Why the connect panel needs these at all ─────────────────────
///
/// Because the connect panel is where the refusal happens. `RemoteHostGuard`
/// runs inside `MailboxesController::connect`, on the host stored when the
/// address was ADDED, and it answers 422 with a sentence about that host — "it
/// points at a private network rather than at a mail server on the internet".
/// Without these fields, the only screen that can show that sentence is the only
/// screen that cannot act on it: somebody would read what was wrong with the
/// host and have nowhere to correct it.
///
/// ── One definition, because there were nearly two ────────────────
///
/// The ports offered, the two encryptions offered, and the words beside them
/// are a contract with the server — `RemoteHostGuard::IMAP_PORTS` and the
/// `in_array(['tls','starttls'])` in `mailServerFor`. A second copy of these
/// fields in the connect panel is a second place for that contract to drift,
/// and the drift would show up as a refusal from the far end rather than as
/// anything visible here.
class _MailServerFields extends StatefulWidget {
  const _MailServerFields({
    super.key,
    this.initial = const {},
    required this.imapPorts,
    required this.smtpPorts,
    required this.enabled,
  });

  /// The row's stored `mail_server` block, when there is one. Empty when the
  /// address is being added and nothing has been typed yet.
  final Map<String, dynamic> initial;

  /// The ports the server's own guard will accept, published in `meta`. Offered
  /// as a choice rather than as a box somebody types into, so a refusal from the
  /// far end is not the first time anybody hears which ports are allowed.
  final List<int> imapPorts;
  final List<int> smtpPorts;

  /// False while a request is in flight, so nothing can be edited underneath a
  /// submission that is already carrying the old values.
  final bool enabled;

  @override
  State<_MailServerFields> createState() => _MailServerFieldsState();
}

class _MailServerFieldsState extends State<_MailServerFields> {
  late final TextEditingController _imapHost;
  late final TextEditingController _smtpHost;
  late final TextEditingController _imapUsername;
  late final TextEditingController _smtpUsername;

  int? _imapPort;
  int? _smtpPort;
  late String _imapEncryption;
  late String _smtpEncryption;

  /// The two spellings the server accepts, and there is deliberately no third.
  ///
  /// `none` is absent for the reason MailboxesController gives: an unencrypted
  /// mail connection sends the password across the network in the clear, and
  /// offering it as a choice is offering somebody the option of being wrong.
  static const String _tls = 'tls';
  static const String _starttls = 'starttls';

  @override
  void initState() {
    super.initState();

    _imapHost = TextEditingController(text: _text('imap_host'));
    _smtpHost = TextEditingController(text: _text('smtp_host'));
    _imapUsername = TextEditingController(text: _text('imap_username'));
    _smtpUsername = TextEditingController(text: _text('smtp_username'));

    // The stored value when the server will still accept it, and the sensible
    // default otherwise. 993 is IMAP over TLS and 587 is SMTP submission, which
    // is what the great majority of providers publish; a stored port that is no
    // longer on the server's list would otherwise sit in the box as a selection
    // the dropdown cannot even show.
    _imapPort = _port(
      'imap_port',
      widget.imapPorts,
      widget.imapPorts.lastOrNull,
    );
    _smtpPort = _port(
      'smtp_port',
      widget.smtpPorts,
      widget.smtpPorts.firstOrNull,
    );

    _imapEncryption = _encryption('imap_encryption', _tls);
    _smtpEncryption = _encryption('smtp_encryption', _starttls);
  }

  String _text(String key) {
    final value = widget.initial[key];

    return value is String ? value : '';
  }

  int? _port(String key, List<int> allowed, int? fallback) {
    final value = widget.initial[key];

    return value is int && allowed.contains(value) ? value : fallback;
  }

  String _encryption(String key, String fallback) {
    final value = widget.initial[key];

    return value == _tls || value == _starttls ? value as String : fallback;
  }

  @override
  void dispose() {
    _imapHost.dispose();
    _smtpHost.dispose();
    _imapUsername.dispose();
    _smtpUsername.dispose();
    super.dispose();
  }

  /// The `mail_server` block, in the shape `MailboxesController::mailServerFor`
  /// narrows on arrival. Read by whichever panel is holding the key.
  Map<String, dynamic> value() => {
    'imap_host': _imapHost.text.trim(),
    if (_imapPort != null) 'imap_port': _imapPort,
    'imap_encryption': _imapEncryption,
    if (_imapUsername.text.trim().isNotEmpty)
      'imap_username': _imapUsername.text.trim(),
    if (_smtpHost.text.trim().isNotEmpty) 'smtp_host': _smtpHost.text.trim(),
    if (_smtpPort != null) 'smtp_port': _smtpPort,
    'smtp_encryption': _smtpEncryption,
    if (_smtpUsername.text.trim().isNotEmpty)
      'smtp_username': _smtpUsername.text.trim(),
  };

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const _SectionLabel('WHERE THE MAIL LIVES'),
      const SizedBox(height: 12),
      TextFormField(
        controller: _imapHost,
        enabled: widget.enabled,
        decoration: const InputDecoration(
          labelText: 'Incoming server',
          hintText: 'imap.example.com',
        ),
        validator: (value) => (value ?? '').trim().isEmpty
            ? 'EzBizi needs the name of the incoming mail server to read this '
                  'address. Whoever provides the mailbox can tell you it.'
            : null,
      ),
      const SizedBox(height: 12),
      // A plain Row, and it was briefly a LayoutBuilder that stacked these
      // below 352 — on the reasoning that "STARTTLS" plus a floating label
      // could not fit half of a phone-width panel.
      //
      // That was a guess, and the mutation run said so: flattening the
      // breakpoint left every test green, because the claim was never true.
      // At 375 the panel IS the window, which leaves 327 for the pair; 157
      // apiece is enough for a dropdown showing STARTTLS with its label above
      // it. The R7 tests assert no overflow at 375 and a control mutation
      // confirms they would notice one.
      _twoUp(
        _portField(
          widget.imapPorts,
          _imapPort,
          (v) => setState(() => _imapPort = v),
        ),
        _encryptionField(
          _imapEncryption,
          (v) => setState(() => _imapEncryption = v),
        ),
      ),
      const SizedBox(height: 12),
      TextFormField(
        controller: _imapUsername,
        enabled: widget.enabled,
        decoration: const InputDecoration(
          labelText: 'Username for incoming mail',
          helperText: 'Leave empty when it is the address itself.',
          helperMaxLines: 2,
        ),
      ),
      const SizedBox(height: 20),
      const _SectionLabel('WHERE REPLIES GO OUT'),
      const SizedBox(height: 12),
      TextFormField(
        controller: _smtpHost,
        enabled: widget.enabled,
        decoration: const InputDecoration(
          labelText: 'Outgoing server',
          hintText: 'smtp.example.com',
          helperText:
              'Leave empty to keep the address read-only. Nobody will be able '
              'to answer from it until an outgoing server is set.',
          helperMaxLines: 3,
        ),
      ),
      const SizedBox(height: 12),
      // The same pair for the outgoing side, and deliberately the same widget:
      // an incoming row that wraps and an outgoing row that does not would be
      // two answers to one layout question.
      _twoUp(
        _portField(
          widget.smtpPorts,
          _smtpPort,
          (v) => setState(() => _smtpPort = v),
        ),
        _encryptionField(
          _smtpEncryption,
          (v) => setState(() => _smtpEncryption = v),
        ),
      ),
      const SizedBox(height: 12),
      TextFormField(
        controller: _smtpUsername,
        enabled: widget.enabled,
        decoration: const InputDecoration(
          labelText: 'Username for outgoing mail',
          helperText: 'Leave empty when it is the same as the incoming one.',
          helperMaxLines: 2,
        ),
      ),
    ],
  );

  /// A port and its encryption, side by side. They are one decision.
  Widget _twoUp(Widget left, Widget right) => Row(
    children: [
      Expanded(child: left),
      const SizedBox(width: 12),
      Expanded(child: right),
    ],
  );

  Widget _portField(List<int> ports, int? value, ValueChanged<int?> onChanged) {
    if (ports.isEmpty) {
      // The server did not publish its allowed ports, so this screen has no
      // list to offer and says nothing rather than inventing one. The mailbox
      // goes without a port and the server falls back to its own default.
      return const SizedBox.shrink();
    }

    return SearchPickerField<int>(
      value: value,
      label: 'Port',
      options: [
        for (final port in ports) SearchOption(value: port, label: '$port'),
      ],
      onChanged: widget.enabled ? onChanged : null,
    );
  }

  Widget _encryptionField(String value, ValueChanged<String> onChanged) =>
      SearchPickerField<String>(
        value: value,
        label: 'Encryption',
        options: const [
          SearchOption(value: _tls, label: 'TLS'),
          SearchOption(value: _starttls, label: 'STARTTLS'),
        ],
        onChanged: widget.enabled ? (v) => onChanged(v ?? value) : null,
      );
}

/// Finish connecting an address — the two ways there are of doing it.
///
/// ── One panel, two endings, and the server picks ─────────────────
///
/// `usable` decides whether anything live is offered at all. `needs_host` then
/// decides WHICH ending, which is the question that property is documented to
/// answer:
///
///   - needs a host → a password, and the server details the password is for.
///     The credential is stored by us and spent by us;
///   - does not → a sign-in at the mail service. Nothing is typed here at all,
///     and what comes back is a token the server stores.
///
/// Nothing below names Google. The sign-in button says whatever the server
/// called the service, so the second provider finished this way needs no edit
/// here.
///
/// Returns true when the mailbox was connected, by either road.
class ConnectMailboxPanel extends StatefulWidget {
  const ConnectMailboxPanel({
    super.key,
    required this.api,
    required this.mailbox,
    required this.provider,
    this.imapPorts = const [],
    this.smtpPorts = const [],
    this.openConsent = openConsentWindow,
    this.pollEvery = const Duration(seconds: 2),
    this.waitAtMost = const Duration(minutes: 5),
  });

  final ApiClient api;
  final MailboxConnection mailbox;

  /// How this address is set up, as the server described it — or null when the
  /// server sent a provider this build's `meta` block does not describe.
  final ProviderOption? provider;

  /// The ports the server's guard accepts, from `meta`. Handed down so the two
  /// panels that draw server details offer the same list.
  final List<int> imapPorts;
  final List<int> smtpPorts;

  /// How a window is opened at the mail service. Injected so the sign-in road
  /// can be driven in a test, which has no browser: the real one is
  /// consent_window.dart, and off the web it answers null on purpose.
  final OpenConsentWindow openConsent;

  /// How often the server is asked whether the sign-in finished, and how long
  /// this goes on asking.
  ///
  /// A wait that never ends is a request every two seconds forever on a tab
  /// somebody left open at lunchtime. Five minutes is long enough to find a
  /// password, sign in and pick an account; past that this stops and says so,
  /// rather than sitting there looking like it is still working.
  final Duration pollEvery;
  final Duration waitAtMost;

  @override
  State<ConnectMailboxPanel> createState() => _ConnectMailboxPanelState();
}

/// What this panel is doing at the moment, which is also what it is saying.
enum _Step {
  /// Nothing in flight. The controls are live.
  idle,

  /// Asking our own server for the address of the mail service's sign-in page.
  opening,

  /// The sign-in window is open and the server is being asked, over and over,
  /// whether anything came back.
  waiting,

  /// The password and the server details are on their way.
  sending,
}

class _ConnectMailboxPanelState extends State<ConnectMailboxPanel> {
  /// Never populated from anything. The server has no path that returns a
  /// stored credential, so there is nothing this could be filled with — and if
  /// there ever were, filling it would be the bug.
  final _credential = TextEditingController();

  final _form = GlobalKey<FormState>();
  final _server = GlobalKey<_MailServerFieldsState>();

  _Step _step = _Step.idle;

  /// The server's own sentence about a refusal, printed verbatim.
  ///
  /// The one this exists for is deliberate rather than exceptional: the server
  /// refuses when the account somebody signed in with is not the address being
  /// connected. Somebody who picked the wrong account from Google's chooser
  /// needs to be told WHICH account they picked, and "something went wrong"
  /// tells them to try the same thing again.
  String? _error;

  /// Something that happened which is not a refusal — the window was closed,
  /// or the wait was given up on. Neither is a fault and neither should be
  /// dressed as one.
  String? _notice;

  bool _empty = false;

  Timer? _poll;
  ConsentWindow? _window;

  /// The address the SERVER says has to be signed in with, and the permissions
  /// it says it is asking for. Both arrive with the sign-in address; neither is
  /// assumed here, so a client can never describe a permission that is not the
  /// one being requested.
  String? _signInAs;
  List<String> _scopes = const [];

  /// Which sign-in attempt this panel is waiting on, as the server named it.
  String? _flow;

  /// How many more times the server will be asked before this gives up.
  ///
  /// Counted rather than clocked, because a wall-clock deadline is a thing a
  /// test cannot move: `DateTime.now()` is not on the fake clock a widget test
  /// runs, so a five-minute cap would be five real minutes or nothing at all.
  int _asksLeft = 0;

  bool get _needsHost => _connectsBy == 'password';

  /// How this inbox is finished, published rather than guessed from [needsHost].
  String get _connectsBy {
    final provider = widget.provider;
    if (provider == null) return 'sign_in';
    return provider.connectsBy;
  }

  bool get _fromDirectory => _connectsBy == 'directory';

  /// True while anything is in flight, whichever road this is on.
  bool get _busy => _step != _Step.idle;

  @override
  void dispose() {
    _poll?.cancel();
    _credential.dispose();
    super.dispose();
  }

  // ── the road with a password on it ───────────────────────────

  Future<void> _save() async {
    final credential = _credential.text;

    if (credential.isEmpty) {
      setState(() => _empty = true);

      return;
    }

    // The host is validated HERE as well as at the far end, and neither is
    // redundant. This catches an empty box before a password crosses the
    // network; RemoteHostGuard catches everything this cannot possibly know —
    // what the name resolves to.
    if (_needsHost && !(_form.currentState?.validate() ?? false)) return;

    setState(() {
      _step = _Step.sending;
      _empty = false;
      _error = null;
      _notice = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailbox.id}/connect',
      {
        'credential': credential,

        // Sent WITH the credential rather than saved separately first, because
        // the server judges the two together: `connect` runs the SSRF guard over
        // this block and stores the credential only if the guard passes. Two
        // requests would leave a window in which the host had been changed and
        // the password had not, which is the state that endpoint's own docblock
        // says must never exist.
        if (_needsHost && _server.currentState != null)
          'mail_server': _server.currentState!.value(),
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _step = _Step.idle;
        // `reason` and not `message`: a 422 from the guard carries its sentence
        // at the top level, and a 422 from validation carries the useful half
        // under `errors`. This picks whichever is the more specific.
        _error = response.reason;
      });

      return;
    }

    // Out of memory as soon as it has been spent. Not security on its own —
    // the string has been through the framework's text layer — but a panel
    // that closes holding somebody's mail password is a habit worth not having.
    _credential.clear();

    Navigator.of(context).pop(true);
  }

  // ── the road with a sign-in on it ────────────────────────────

  /// Open the mail service's own window, then wait for the server to say what
  /// came back.
  ///
  /// ── The window is opened FIRST, before the request ───────────
  ///
  /// A browser permits `window.open` while it is handling a click and refuses it
  /// afterwards. Asking our server for the sign-in address is an `await`, and a
  /// window opened after that await is one the pop-up blocker eats — so the
  /// empty window is claimed inside the gesture and pointed at the address when
  /// it arrives. If the request then fails, the empty window is closed again.
  Future<void> _signIn() async {
    final label = _serviceLabel;

    final window = widget.openConsent();

    if (window == null) {
      setState(() {
        _error =
            'EzBizi could not open the $label sign-in window. On the web that '
            'is usually the browser blocking new windows for this site — allow '
            'them and press the button again. On the desktop and phone apps '
            'this is not built yet; connect the address from a browser and it '
            'stays connected everywhere.';
      });

      return;
    }

    setState(() {
      _step = _Step.opening;
      _error = null;
      _notice = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailbox.id}/oauth/start',
      const {},
    );

    if (!mounted) {
      window.close();

      return;
    }

    if (!response.ok) {
      window.close();

      setState(() {
        _step = _Step.idle;
        // Everything this endpoint refuses, it refuses in a sentence: no
        // permission (403), an inbox that is not set up on this service at all
        // (422), and this installation having no registration with the service
        // (501). All three are printed as written.
        _error = response.reason;
      });

      return;
    }

    final data = response.data;
    final url = data is Map<String, dynamic>
        ? data['authorization_url'] as String?
        : null;

    if (url == null || url.isEmpty) {
      window.close();

      setState(() {
        _step = _Step.idle;
        _error =
            'EzBizi asked for the $label sign-in address and did not get one '
            'back, so there is nowhere to send you. Nothing has changed. Try '
            'again, and if it keeps happening this is at EzBizi\'s end rather '
            'than at yours.';
      });

      return;
    }

    window.goTo(url);

    setState(() {
      _step = _Step.waiting;
      _window = window;
      // What the server says to tell somebody BEFORE they get to the account
      // chooser — the address this inbox actually is. It is sent rather than
      // assumed from the row because the endpoint that will judge the match is
      // the one sending it, so the two cannot disagree.
      _signInAs = data is Map<String, dynamic>
          ? data['sign_in_as'] as String?
          : null;
      _scopes = [
        for (final scope
            in (data is Map<String, dynamic> ? data['scopes'] : null)
                    as List? ??
                const [])
          if (scope is String) scope,
      ];
      // WHICH attempt this is. Without it the server can only answer "does this
      // inbox have a credential", which is also true of the attempt somebody
      // abandoned ten minutes ago — so a second press would be told about the
      // first one's success and close on it.
      _flow = data is Map<String, dynamic> ? data['flow'] as String? : null;
      _asksLeft =
          widget.waitAtMost.inMilliseconds ~/
          (widget.pollEvery.inMilliseconds == 0
              ? 1
              : widget.pollEvery.inMilliseconds);
    });

    _askAgainLater();
  }

  void _askAgainLater() {
    _poll?.cancel();
    _poll = Timer(widget.pollEvery, _hasItConnectedYet);
  }

  void _stopWaiting() {
    _poll?.cancel();
    _poll = null;
    _window = null;
    _flow = null;
  }

  /// Ask the SERVER whether the inbox has become connected.
  ///
  /// ── Why the server is asked, rather than the window watched ──
  ///
  /// Because the window stops being ours the moment it navigates. It becomes the
  /// mail service's page and then our own callback route, and neither is
  /// scripted from here. The only thing an opener may read across origins is
  /// whether the window is still open, and that answers a different question: a
  /// closed window means somebody gave up OR that it finished.
  ///
  /// ── Why it is a poll and not a callback into this app ────────
  ///
  /// Because nothing can call back into it. The callback route is a page in the
  /// consent window, and a page on another origin — or on ours with no script —
  /// has no way to reach this panel. So the panel asks, on a timer, and stops
  /// on one of four answers: connected, refused, the window going away, or
  /// having asked long enough.
  ///
  /// The cost is stated rather than hidden: one request every
  /// [ConnectMailboxPanel.pollEvery] for as long as somebody is standing in
  /// front of the panel, capped by [ConnectMailboxPanel.waitAtMost]. The server
  /// answers it from one cache entry and writes nothing.
  Future<void> _hasItConnectedYet() async {
    if (!mounted || _step != _Step.waiting) return;

    final response = await widget.api.get(
      '/email/mailboxes/${widget.mailbox.id}/oauth/status',
      // Left out entirely when the server did not hand one back, rather than
      // sent empty: `?flow=` is a value, and a server reading it would be asked
      // about a sign-in called "".
      {'flow': ?_flow},
    );

    if (!mounted || _step != _Step.waiting) return;

    if (!response.ok) {
      // The window is left OPEN on purpose. This branch is our end failing —
      // the request did not come back — and the person may be halfway through
      // signing in over there. Closing somebody's half-finished sign-in on top
      // of an error message would be the second unhelpful thing to happen to
      // them in a row.
      _stopWaiting();

      setState(() {
        _step = _Step.idle;
        _error = response.reason;
      });

      return;
    }

    final data = response.data;
    final state = data is Map<String, dynamic>
        ? data['state'] as String?
        : null;
    final message = data is Map<String, dynamic>
        ? data['message'] as String?
        : null;

    if (state == _connected) {
      // Tidying up, not part of the outcome. The window is on our own callback
      // page by now, which says so itself and invites closing.
      _window?.close();
      _stopWaiting();

      if (!mounted) return;

      Navigator.of(context).pop(true);

      return;
    }

    if (state == _refused) {
      _window?.close();
      _stopWaiting();

      setState(() {
        _step = _Step.idle;
        // The server's sentence, verbatim, and this is the one that matters
        // most in the whole flow. The likeliest refusal is deliberate: the
        // account somebody signed in with is not the address being connected,
        // and connecting them would hand everybody with a grant on this shared
        // inbox the mail of somebody's personal account. That sentence names
        // both addresses and says what to do about it, so rewriting it here
        // would throw away the only part anybody can act on.
        _error = message ?? _didNotSay;
      });

      return;
    }

    // Nothing has landed yet. Two ways that stops being worth waiting for.
    if (_window?.closed ?? true) {
      _stopWaiting();

      setState(() {
        _step = _Step.idle;
        _notice =
            'The $_serviceLabel window was closed before the sign-in finished, '
            'so nothing has changed. ${widget.mailbox.address} is still waiting '
            'to be connected.';
      });

      return;
    }

    _asksLeft -= 1;

    if (_asksLeft <= 0) {
      _stopWaiting();

      setState(() {
        _step = _Step.idle;
        _notice =
            'EzBizi has stopped waiting for $_serviceLabel — nothing came back '
            'for ${_minutes(widget.waitAtMost)}. Nothing has changed. If you '
            'finished signing in after that, press the button again and it will '
            'pick the answer straight up.';
      });

      return;
    }

    _askAgainLater();
  }

  /// A refusal the server declined to explain.
  ///
  /// It has one for every refusal it makes, so this is the branch for a server
  /// newer or older than this build rather than for anything that happens today.
  /// It says the flow did not finish and does not guess at why — a made-up cause
  /// sends somebody to fix the wrong thing.
  String get _didNotSay =>
      'The $_serviceLabel sign-in did not finish and EzBizi was not told why. '
      'Nothing has changed. The window that opened says what happened; starting '
      'again is the quickest way to find out.';

  /// Stop waiting because the person asked to.
  ///
  /// The window is deliberately left open: they may be halfway through typing a
  /// password into it, and closing somebody's sign-in from behind their back is
  /// not this panel's business.
  void _giveUpWaiting() {
    _stopWaiting();

    setState(() {
      _step = _Step.idle;
      _notice =
          'EzBizi is no longer waiting. If you finish signing in in the '
          '$_serviceLabel window, press the button again and it will pick the '
          'answer straight up — nothing you have already done is lost.';
    });
  }

  /// The service's name as the SERVER wrote it, or a plain noun when there is
  /// no provider to ask. Nothing in this file spells "Google".
  String get _serviceLabel => widget.provider?.label ?? 'the mail service';

  static String _minutes(Duration d) =>
      d.inMinutes == 1 ? 'a minute' : '${d.inMinutes} minutes';

  /// The two answers this panel acts on. Anything else the server sends — its
  /// own "still going", or a word a newer server has invented — is read as not
  /// finished, which the window closing and the cap both end.
  static const String _connected = 'connected';
  static const String _refused = 'refused';

  /// What EzBizi is about to ask the mail service for, in words.
  ///
  /// ── Why this is not simply a sentence written here ───────────
  ///
  /// Because the server publishes the scopes it actually requests — "published
  /// rather than described, so a client never has to hard-code a list of
  /// permissions that could drift from what is actually asked". A paragraph in
  /// this file saying "read and reply" would go on saying it the day somebody
  /// adds a third scope, and a screen that understates what it is about to be
  /// granted is worse than one that says nothing.
  ///
  /// So the words come from the list. A scope this build has no words for is
  /// printed AS ITSELF rather than dropped: an unfamiliar permission showing up
  /// as an unfamiliar string is somebody's cue to ask, where silence is not.
  List<String> get _permissionWords => [
    for (final scope in _scopes)
      switch (scope) {
        'https://www.googleapis.com/auth/gmail.readonly' =>
          'read the mail in this mailbox',
        'https://www.googleapis.com/auth/gmail.send' =>
          'send replies from this address',
        _ => scope,
      },
  ];

  @override
  Widget build(BuildContext context) {
    final provider = widget.provider;

    // Whether this panel may offer anything live at all.
    //
    // It asked `!provider.needsHost` — meaning "Google is finished by signing
    // in, so show the explanation instead of a form". That was right about
    // Google and wrong about IMAP, which needs a host and therefore got a live
    // password form on a server whose PHP has no imap extension. Somebody would
    // have typed the password to their real mailbox into it.
    //
    // `usable` is the server's own answer to that question and the only one
    // asked here now. `needsHost` still decides which FIELDS a usable provider
    // draws, which is what it actually knows about.
    final unusable = provider == null || !provider.usable;
    final fromDirectory = !unusable && _fromDirectory;

    return SidePanel(
      title: 'Connect ${widget.mailbox.address}',
      subtitle: provider == null ? null : 'Set up as ${provider.label}.',
      actions: unusable || fromDirectory
          ? [
              TextButton(
                onPressed: () => Navigator.of(context).maybePop(),
                child: const Text('Close'),
              ),
            ]
          : [
              TextButton(
                onPressed: _busy
                    ? null
                    : () => Navigator.of(context).maybePop(),
                child: const Text('Cancel'),
              ),

              // Waiting has its own way out, and it is not "Cancel". Cancel
              // closes the panel, which would abandon a sign-in that is still
              // running in another window with nothing left watching for it.
              if (_step == _Step.waiting)
                TextButton(
                  onPressed: _giveUpWaiting,
                  child: const Text('Stop waiting'),
                ),

              FilledButton(
                // Disabled from the first press until the attempt resolves, and
                // that is not decoration. A button that appears to do nothing
                // gets pressed again; a second press here starts a SECOND
                // sign-in, and two flows in flight against one mailbox is how
                // the wrong one wins.
                onPressed: _busy ? null : (_needsHost ? _save : _signIn),
                child: Text(_actionLabel),
              ),
            ],
      child: unusable
          ? _cannotConnectYet(provider)
          : fromDirectory
          ? _directoryInstead()
          : _needsHost
          ? _password()
          : _signInInstead(),
    );
  }

  String get _actionLabel => switch (_step) {
    _Step.sending => 'Connecting…',
    _Step.opening => 'Opening $_serviceLabel…',
    _Step.waiting => 'Waiting for $_serviceLabel…',
    _Step.idle => _needsHost ? 'Connect it' : 'Continue with $_serviceLabel',
  };

  Widget _directoryInstead() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _Note(
        '${widget.mailbox.address} is opened through the connected Google '
        'Workspace, by picking it from the directory — not by signing in as '
        'the inbox and not by handing over a password. If it is not '
        'connected yet, disconnect it and add the address again from the '
        'directory under Add an inbox.',
      ),
      if (widget.mailbox.unavailableReason != null) ...[
        const SizedBox(height: 16),
        _Note(widget.mailbox.unavailableReason!),
      ],
    ],
  );

  /// Why nothing live can be offered here.
  ///
  /// Named for what it means rather than for one of its causes: it was
  /// `_signInIsMissing`, which described Google's case and was the only case
  /// this branch could reach. IMAP reaches it now too, for an entirely
  /// different reason, and the server says which.
  Widget _cannotConnectYet(ProviderOption? provider) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (provider == null)
        const ErrorNote(
          'This address is set up on a mail service this version of the app '
          'does not know how to connect. Updating EzBizi is what adds it.',
        )
      else
        // Two different absences, and they read completely differently to
        // somebody holding a password. One says "do not type it here"; the
        // other says "there is nothing to press".
        _NotHereNote(
          provider.connectsBy == 'password'
              ? 'This address is connected by handing over a password and the '
                    'name of a mail server, and EzBizi cannot open that kind of '
                    'connection on this installation. Nothing typed here would '
                    'reach anything, so nothing is asked for. Nothing is wrong '
                    'with ${widget.mailbox.address} — the missing piece is at '
                    'EzBizi\'s end.'
              : provider.connectsBy == 'directory'
              ? 'This address is opened through the connected Google Workspace. '
                    'EzBizi cannot talk to Workspace on this installation yet, '
                    'so there is nothing to pick here. Nothing is wrong with '
                    '${widget.mailbox.address} — the missing piece is at '
                    'EzBizi\'s end.'
              : 'This address is connected by signing in to the mail service '
                    'and allowing EzBizi to read it, rather than by handing '
                    'over a password. That sign-in cannot be completed on this '
                    'installation, so there is nothing to press here. Nothing '
                    'is wrong with ${widget.mailbox.address} — the missing '
                    'piece is at EzBizi\'s end.',
        ),

      // What the server says about it, where it has anything to add. Its
      // sentence is more specific than the one above — it knows whether this
      // installation is registered with the service at all.
      if (widget.mailbox.unavailableReason != null) ...[
        const SizedBox(height: 16),
        _Note(widget.mailbox.unavailableReason!),
      ],
    ],
  );

  /// The road with a password on it: the key, and where to spend it.
  Widget _password() => Form(
    key: _form,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 16)],
        if (_notice != null) ...[_Note(_notice!), const SizedBox(height: 16)],
        TextField(
          controller: _credential,
          autofocus: true,
          obscureText: true,
          enabled: !_busy,
          // No autofill hint, and no password manager is invited in. This is not
          // the reader's own password: it is a shared mailbox's, typed on behalf
          // of the organization, and offering to save it into somebody's personal
          // vault is the wrong place for it to end up.
          autofillHints: const [],
          enableSuggestions: false,
          autocorrect: false,
          inputFormatters: [FilteringTextInputFormatter.deny(RegExp(r'\n'))],
          decoration: InputDecoration(
            labelText: 'Password for ${widget.mailbox.address}',
            errorText: _empty
                ? 'Type the password for this mailbox. Nothing is sent until you do.'
                : null,
          ),
          onSubmitted: (_) => _busy ? null : _save(),
        ),
        const SizedBox(height: 16),
        const _Note(
          'This is stored encrypted and is never shown again — not on this '
          'screen and not to anybody, including whoever typed it. If it is ever '
          'lost or changed, type the new one here and it replaces the old one.',
        ),
        const SizedBox(height: 24),

        // The server details, HERE and not only on the add form.
        //
        // This is the screen the SSRF guard's refusal lands on: `connect` runs
        // RemoteHostGuard over the stored host before it stores anything, and
        // answers 422 with a sentence about that host. Without these fields
        // somebody would read exactly what was wrong with their mail server name
        // on the one screen that gave them no way to change it.
        _MailServerFields(
          key: _server,
          initial: widget.mailbox.mailServer,
          imapPorts: widget.imapPorts,
          smtpPorts: widget.smtpPorts,
          enabled: !_busy,
        ),

        if (_step == _Step.sending) ...[
          const SizedBox(height: 16),
          _Working(
            'Signing in to ${_hostOrService()} as ${widget.mailbox.address}. A '
            'mail server that is slow to answer can take a few seconds.',
          ),
        ],
      ],
    ),
  );

  /// The road with a sign-in on it: nothing to type, one thing to press.
  Widget _signInInstead() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 16)],
      if (_notice != null) ...[_Note(_notice!), const SizedBox(height: 16)],
      Text(
        'EzBizi will open $_serviceLabel in a window of its own, where you sign '
        'in and allow it to read this address. Your password is typed on their '
        'page and never reaches EzBizi — what comes back is permission for this '
        'one mailbox, and it can be taken away again from the same account at '
        'any time.',
        style: Theme.of(context).textTheme.bodyMedium,
      ),

      // What is being asked for, once the server has said. Before that there is
      // nothing to print — and a list guessed at in advance would be the thing
      // this deliberately does not do.
      if (_permissionWords.isNotEmpty) ...[
        const SizedBox(height: 12),
        for (final permission in _permissionWords)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              '• EzBizi will be able to $permission.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],

      const SizedBox(height: 16),
      _Note(
        'Sign in as ${_signInAs ?? widget.mailbox.address} itself. If you are '
        'already signed in to $_serviceLabel with a different account, that is '
        'the one it will offer you — and connecting an inbox to somebody\'s own '
        'account is refused rather than quietly accepted, because it would hand '
        'their private mail to everybody who has been given this inbox.',
      ),
      if (_step == _Step.waiting) ...[
        const SizedBox(height: 16),
        _Working(
          'Waiting for $_serviceLabel. Finish signing in in the window that '
          'opened, and this panel will say so as soon as '
          '${widget.mailbox.address} is connected. That window says what '
          'happened either way; closing it stops the wait.',
        ),
      ],
    ],
  );

  /// The incoming server if there is one, and the service's name otherwise.
  /// A connection is being made to a place, and naming it is what makes "it is
  /// taking a while" mean something.
  String _hostOrService() {
    final host = _server.currentState?.value()['imap_host'];

    return host is String && host.trim().isNotEmpty
        ? host.trim()
        : _serviceLabel;
  }
}

/// Something is happening at the far end, said in words.
///
/// ── Why there is no spinner in it ────────────────────────────────
///
/// Two reasons, and the first is the one that matters to a person. A spinner
/// says "wait" and nothing else; the thing somebody waiting on an IMAP login or
/// an OAuth round trip actually wants to know is WHAT is being waited for and
/// what they can do about it, which is a sentence. The panels in this file
/// already say it that way — "Adding…", "Disconnecting…" — so this matches them.
///
/// The second is that an indeterminate indicator animates forever, and a
/// forever-animating widget makes `pumpAndSettle` time out — so a spinner here
/// would quietly make the in-progress state the one thing in this panel a test
/// could not sit still long enough to look at.
///
/// [Semantics.liveRegion] is what carries it to somebody who is not looking at
/// the panel: the sentence is announced when it appears, rather than waiting to
/// be found by a reader who has no reason to go back and re-read the page.
class _Working extends StatelessWidget {
  const _Working(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest.withValues(
            alpha: 0.5,
          ),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              Icons.hourglass_top,
              size: 17,
              color: theme.textTheme.bodySmall?.color,
            ),
            const SizedBox(width: 10),
            Expanded(child: Text(text, style: theme.textTheme.bodySmall)),
          ],
        ),
      ),
    );
  }
}

/// Connect the company's Google Workspace domain, once, then pick inboxes.
class ConnectWorkspacePanel extends StatefulWidget {
  const ConnectWorkspacePanel({
    super.key,
    required this.api,
    this.openConsent = openConsentWindow,
    this.pollEvery = const Duration(seconds: 2),
    this.waitAtMost = const Duration(minutes: 5),
  });

  final ApiClient api;
  final OpenConsentWindow openConsent;
  final Duration pollEvery;
  final Duration waitAtMost;

  @override
  State<ConnectWorkspacePanel> createState() => _ConnectWorkspacePanelState();
}

class _ConnectWorkspacePanelState extends State<ConnectWorkspacePanel> {
  final _domain = TextEditingController();
  _Step _step = _Step.idle;
  String? _error;
  String? _flow;
  int _asksLeft = 0;
  ConsentWindow? _window;
  Timer? _poll;

  bool get _busy => _step != _Step.idle;

  @override
  void dispose() {
    _poll?.cancel();
    _domain.dispose();
    super.dispose();
  }

  Future<void> _signIn() async {
    final domain = _domain.text.trim().replaceFirst(RegExp(r'^@'), '');
    if (domain.isEmpty) {
      setState(
        () => _error = 'Type the company domain, for example a-1chimney.com.',
      );
      return;
    }

    final window = widget.openConsent();
    if (window == null) {
      setState(() {
        _error =
            'EzBizi could not open the Google sign-in window. On the web that '
            'is usually the browser blocking new windows for this site — allow '
            'them and press the button again.';
      });
      return;
    }

    setState(() {
      _step = _Step.opening;
      _error = null;
    });

    final response = await widget.api.post('/email/workspace/oauth/start', {
      'domain': domain,
    });

    if (!mounted) {
      window.close();
      return;
    }

    if (!response.ok) {
      window.close();
      setState(() {
        _step = _Step.idle;
        _error = response.reason;
      });
      return;
    }

    final data = response.data;
    final url = data is Map<String, dynamic>
        ? data['authorization_url'] as String?
        : null;

    if (url == null || url.isEmpty) {
      window.close();
      setState(() {
        _step = _Step.idle;
        _error =
            'EzBizi asked for the Google sign-in address and did not get one '
            'back, so there is nowhere to send you. Nothing has changed.';
      });
      return;
    }

    window.goTo(url);

    setState(() {
      _step = _Step.waiting;
      _window = window;
      _flow = data is Map<String, dynamic> ? data['flow'] as String? : null;
      _asksLeft =
          widget.waitAtMost.inMilliseconds ~/
          (widget.pollEvery.inMilliseconds == 0
              ? 1
              : widget.pollEvery.inMilliseconds);
    });

    _askAgainLater();
  }

  void _askAgainLater() {
    _poll?.cancel();
    _poll = Timer(widget.pollEvery, _ask);
  }

  Future<void> _ask() async {
    if (!mounted || _step != _Step.waiting) return;

    if (_asksLeft <= 0) {
      _window?.close();
      setState(() {
        _step = _Step.idle;
        _error =
            'EzBizi stopped waiting for the Google sign-in. Nothing was '
            'connected. Press the button again if you still want to connect '
            'the domain.';
      });
      return;
    }

    _asksLeft -= 1;

    final response = await widget.api.get(
      '/email/workspace/oauth/status',
      _flow == null ? null : {'flow': _flow!},
    );

    if (!mounted || _step != _Step.waiting) return;

    final data = response.data;
    final state = data is Map<String, dynamic>
        ? data['state'] as String?
        : null;

    if (state == 'connected') {
      _window?.close();
      if (mounted) Navigator.of(context).pop(true);
      return;
    }

    if (state == 'refused') {
      _window?.close();
      setState(() {
        _step = _Step.idle;
        _error = data is Map<String, dynamic>
            ? data['message'] as String?
            : 'That sign-in did not connect the domain.';
      });
      return;
    }

    _askAgainLater();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Connect Google Workspace',
      subtitle:
          'A Workspace administrator signs in once to prove they run the '
          'domain. Google then asks them to Allow EzBizi for the company — '
          'one click, not a list of scopes to paste. After that, pick shared '
          'inboxes like info@ from the directory. Do not import anyone\'s '
          'personal mailbox.',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).maybePop(),
          child: const Text('Cancel'),
        ),
        if (_step == _Step.waiting)
          TextButton(
            onPressed: () {
              _poll?.cancel();
              _window?.close();
              setState(() => _step = _Step.idle);
            },
            child: const Text('Stop waiting'),
          ),
        FilledButton(
          onPressed: _busy ? null : _signIn,
          child: Text(switch (_step) {
            _Step.opening => 'Opening Google…',
            _Step.waiting => 'Waiting for Google…',
            _ => 'Continue with Google',
          }),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 16),
          ],
          TextField(
            controller: _domain,
            enabled: !_busy,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'Company domain',
              hintText: 'a-1chimney.com',
              helperText:
                  'The Google Workspace domain, not a person\'s address.',
              helperMaxLines: 2,
            ),
          ),
        ],
      ),
    );
  }
}

/// Take an address away, and say what goes with it before it happens.
///
/// Returns the server's own sentence about what has just happened, so the list
/// behind can repeat it rather than writing a cheerful one of its own.
class DisconnectMailboxPanel extends StatefulWidget {
  const DisconnectMailboxPanel({
    super.key,
    required this.api,
    required this.mailbox,
    required this.scope,
  });

  final ApiClient api;
  final MailboxConnection mailbox;

  /// Which part of the business it belongs to, already in words. Repeated here
  /// because two inboxes can share an address across two companies, and the
  /// panel that removes one must say which one.
  final String scope;

  @override
  State<DisconnectMailboxPanel> createState() => _DisconnectMailboxPanelState();
}

class _DisconnectMailboxPanelState extends State<DisconnectMailboxPanel> {
  bool _working = false;
  String? _error;

  Future<void> _disconnect() async {
    setState(() {
      _working = true;
      _error = null;
    });

    final response = await widget.api.delete(
      '/email/mailboxes/${widget.mailbox.id}',
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _working = false;
        _error = response.reason;
      });

      return;
    }

    Navigator.of(context).pop(response.message);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Disconnect ${widget.mailbox.address}',
      subtitle: 'The one belonging to ${widget.scope}.',
      actions: [
        TextButton(
          onPressed: _working ? null : () => Navigator.of(context).maybePop(),
          child: const Text('Keep it connected'),
        ),
        FilledButton(
          onPressed: _working ? null : _disconnect,
          child: Text(_working ? 'Disconnecting…' : 'Disconnect it'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 16),
          ],

          // The consequence, in the sentence and not in a colour. Two facts,
          // and the first is the one that surprises people: this is not one
          // person losing an inbox, it is every role that was given it.
          Text(
            'Everybody who could read ${widget.mailbox.address} through EzBizi '
            'will lose it. Access is given out to roles one inbox at a time, '
            'and disconnecting takes away every one of those grants — '
            'reconnecting the address later does not bring them back, so each '
            'role would have to be given it again.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 14),
          Text(
            'Nothing happens to the mail itself. The address goes on receiving '
            'everything it does now and can still be read wherever it was read '
            'before — EzBizi simply stops being able to look at it.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ],
      ),
    );
  }
}

/// A quiet block of explanation inside a panel.
class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(text, style: Theme.of(context).textTheme.bodySmall),
    );
  }
}

/// Something absent from THIS installation, badged as such.
///
/// The same answer the HR dashboard's "Expiring certifications" panel gives,
/// an automation action the engine cannot run gets in the builder, and a module
/// the platform has switched off gets in the role editor. The rule underneath
/// all four: something merely missing invites the reader to conclude the app is
/// broken or that they have lost a permission, and both guesses are worse than
/// the truth.
///
/// ── The badge used to say "Not built yet", and stopped being true ──
///
/// It was accurate while neither transport worked and the client had no sign-in
/// flow at all. It is not accurate now: the sign-in IS built, and what a Google
/// mailbox is waiting on is this installation being registered with Google —
/// which is an account somebody creates, not code somebody writes. The IMAP case
/// is a PHP extension on the box. Neither is "we have not got round to it", and
/// a badge saying so sends an administrator to ask EzBizi for a feature that
/// already exists. "Not available here" is true of both, and the sentence
/// underneath — the server's own — says which.
class _NotHereNote extends StatelessWidget {
  const _NotHereNote(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: double.infinity,
      // Four on the left rather than twelve, because Tag carries eight of its
      // own — it is built to sit after a title. The paragraph under it gets
      // that eight back as padding of its own, so the two line up.
      padding: const EdgeInsets.fromLTRB(4, 12, 12, 14),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Align(
            alignment: Alignment.centerLeft,
            child: Tag('Not available here', warn: true),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.only(left: 8),
            child: Text(text, style: Theme.of(context).textTheme.bodySmall),
          ),
        ],
      ),
    );
  }
}

/// A small heading inside a panel, for a run of fields that belong together.
class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.centerLeft,
    child: Text(text, style: Theme.of(context).textTheme.labelSmall),
  );
}
