import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
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
  final _imapHost = TextEditingController();
  final _smtpHost = TextEditingController();
  final _imapUsername = TextEditingController();
  final _smtpUsername = TextEditingController();

  ProviderOption? _provider;
  int? _imapPort;
  int? _smtpPort;
  String _imapEncryption = _tls;
  String _smtpEncryption = _starttls;

  bool _saving = false;
  String? _error;

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

    _provider = widget.providers.isEmpty ? null : widget.providers.first;
    _imapPort = widget.imapPorts.isEmpty ? null : widget.imapPorts.last;
    _smtpPort = widget.smtpPorts.isEmpty ? null : widget.smtpPorts.first;
  }

  @override
  void dispose() {
    _address.dispose();
    _displayName.dispose();
    _imapHost.dispose();
    _smtpHost.dispose();
    _imapUsername.dispose();
    _smtpUsername.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final provider = _provider;

    if (provider == null || !(_form.currentState?.validate() ?? false)) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    final me = widget.session.me!;

    final response = await widget.api.post('/email/mailboxes', {
      // Where this person is standing, exactly as the sending-identity panel
      // decides the same question. The panel says so in words above, and the
      // scope switcher is how somebody changes it — a second scope picker
      // inside this form would be a second answer to "where am I".
      'scope_type': me.scope.type,
      'scope_id': me.scope.id ?? me.organization.id,
      'address': _address.text.trim(),
      'display_name': _displayName.text.trim(),
      'provider': provider.value,
      if (provider.needsHost)
        'mail_server': {
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
        },
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
          onPressed: _saving ? null : () => Navigator.of(context).maybePop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _saving || provider == null ? null : _save,
          child: Text(_saving ? 'Adding…' : 'Add the address'),
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
              DropdownButtonFormField<String>(
                initialValue: provider?.value,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Mail service'),
                items: [
                  for (final option in widget.providers)
                    DropdownMenuItem(
                      value: option.value,
                      child: Text(option.label, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: _saving
                    ? null
                    : (value) => setState(() {
                        _provider = widget.providers
                            .where((option) => option.value == value)
                            .firstOrNull;
                      }),
              ),

            // The server's warning about handing over a password, in the
            // server's words, published so every client says the same thing.
            if (provider?.caution != null) ...[
              const SizedBox(height: 12),
              _Note(provider!.caution!),
            ],

            // The half of this that is honestly not built. Adding the address
            // works; finishing it does not, and saying so here is cheaper than
            // letting somebody fill the form in and meet the wall afterwards.
            if (provider != null && !provider.needsHost) ...[
              const SizedBox(height: 12),
              const _NotBuiltNote(
                'Signing in to this service is not built in EzBizi yet, so the '
                'address will sit on the list waiting to be connected and no '
                'mail will arrive in it. Adding it now is still useful — it is '
                'the record everything else hangs off — but nothing can be read '
                'until the sign-in step exists.',
              ),
            ],

            if (provider != null && provider.needsHost) ..._serverFields(),
          ],
        ),
      ),
    );
  }

  /// Where to dial, for the provider that dials somewhere the tenant chose.
  List<Widget> _serverFields() => [
    const SizedBox(height: 24),
    const _SectionLabel('WHERE THE MAIL LIVES'),
    const SizedBox(height: 12),
    TextFormField(
      controller: _imapHost,
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
    Row(
      children: [
        Expanded(child: _portField(widget.imapPorts, _imapPort, (v) => setState(() => _imapPort = v))),
        const SizedBox(width: 12),
        Expanded(
          child: _encryptionField(_imapEncryption, (v) => setState(() => _imapEncryption = v)),
        ),
      ],
    ),
    const SizedBox(height: 12),
    TextFormField(
      controller: _imapUsername,
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
      decoration: const InputDecoration(
        labelText: 'Outgoing server',
        hintText: 'smtp.example.com',
        helperText:
            'Leave empty to add the address as read-only. Nobody will be able '
            'to answer from it until an outgoing server is set.',
        helperMaxLines: 3,
      ),
    ),
    const SizedBox(height: 12),
    Row(
      children: [
        Expanded(child: _portField(widget.smtpPorts, _smtpPort, (v) => setState(() => _smtpPort = v))),
        const SizedBox(width: 12),
        Expanded(
          child: _encryptionField(_smtpEncryption, (v) => setState(() => _smtpEncryption = v)),
        ),
      ],
    ),
    const SizedBox(height: 12),
    TextFormField(
      controller: _smtpUsername,
      decoration: const InputDecoration(
        labelText: 'Username for outgoing mail',
        helperText: 'Leave empty when it is the same as the incoming one.',
        helperMaxLines: 2,
      ),
    ),
  ];

  Widget _portField(List<int> ports, int? value, ValueChanged<int?> onChanged) {
    if (ports.isEmpty) {
      // The server did not publish its allowed ports, so this screen has no
      // list to offer and says nothing rather than inventing one. The mailbox
      // is added without a port and the server falls back to its own default.
      return const SizedBox.shrink();
    }

    return DropdownButtonFormField<int>(
      initialValue: value,
      isExpanded: true,
      decoration: const InputDecoration(labelText: 'Port'),
      items: [
        for (final port in ports)
          DropdownMenuItem(value: port, child: Text('$port')),
      ],
      onChanged: _saving ? null : onChanged,
    );
  }

  Widget _encryptionField(String value, ValueChanged<String> onChanged) =>
      DropdownButtonFormField<String>(
        initialValue: value,
        isExpanded: true,
        decoration: const InputDecoration(labelText: 'Encryption'),
        items: const [
          DropdownMenuItem(value: _tls, child: Text('TLS')),
          DropdownMenuItem(value: _starttls, child: Text('STARTTLS')),
        ],
        onChanged: _saving ? null : (v) => onChanged(v ?? value),
      );
}

/// Hand over the key. The one credential field in this client.
///
/// Returns true when the mailbox was connected.
class ConnectMailboxPanel extends StatefulWidget {
  const ConnectMailboxPanel({
    super.key,
    required this.api,
    required this.mailbox,
    required this.provider,
  });

  final ApiClient api;
  final MailboxConnection mailbox;

  /// How this address is set up, as the server described it — or null when the
  /// server sent a provider this build's `meta` block does not describe.
  final ProviderOption? provider;

  @override
  State<ConnectMailboxPanel> createState() => _ConnectMailboxPanelState();
}

class _ConnectMailboxPanelState extends State<ConnectMailboxPanel> {
  /// Never populated from anything. The server has no path that returns a
  /// stored credential, so there is nothing this could be filled with — and if
  /// there ever were, filling it would be the bug.
  final _credential = TextEditingController();

  bool _saving = false;
  String? _error;
  bool _empty = false;

  @override
  void dispose() {
    _credential.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final credential = _credential.text;

    if (credential.isEmpty) {
      setState(() => _empty = true);

      return;
    }

    setState(() {
      _saving = true;
      _empty = false;
      _error = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailbox.id}/connect',
      {'credential': credential},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
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

  @override
  Widget build(BuildContext context) {
    final provider = widget.provider;

    // Two cases with nothing to type, and neither gets a live control.
    //
    // A provider this build cannot describe, and one that is finished by
    // signing in — the browser flow for which does not exist in this app.
    // GmailMailboxReader sets out exactly what is missing and why it is an
    // account somebody has to create rather than code, and a button that
    // opens a sign-in that goes nowhere would be worse than none: somebody
    // would type into it first.
    // Whether this panel can take a credential at all.
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

    return SidePanel(
      title: 'Connect ${widget.mailbox.address}',
      subtitle: provider == null ? null : 'Set up as ${provider.label}.',
      actions: unusable
          ? [
              TextButton(
                onPressed: () => Navigator.of(context).maybePop(),
                child: const Text('Close'),
              ),
            ]
          : [
              TextButton(
                onPressed: _saving ? null : () => Navigator.of(context).maybePop(),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Connecting…' : 'Connect it'),
              ),
            ],
      child: unusable ? _cannotConnectYet(provider) : _password(),
    );
  }

  /// Why no credential can be taken here yet.
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
        _NotBuiltNote(
          'This address is connected by signing in to the mail service and '
          'allowing EzBizi to read it, rather than by handing over a password. '
          'That sign-in step is not built yet, so there is nothing to type '
          'here. Nothing is wrong with ${widget.mailbox.address} — the missing '
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

  Widget _password() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (_error != null) ...[
        ErrorNote(_error!),
        const SizedBox(height: 16),
      ],
      TextField(
        controller: _credential,
        autofocus: true,
        obscureText: true,
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
        onSubmitted: (_) => _saving ? null : _save(),
      ),
      const SizedBox(height: 16),
      const _Note(
        'This is stored encrypted and is never shown again — not on this screen '
        'and not to anybody, including whoever typed it. If it is ever lost or '
        'changed, type the new one here and it replaces the old one.',
      ),
    ],
  );
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

/// Something planned and absent, badged as such.
///
/// The same answer the HR dashboard's "Expiring certifications" panel gives,
/// an automation action the engine cannot run gets in the builder, and a module
/// the platform has switched off gets in the role editor. The rule underneath
/// all four: something merely missing invites the reader to conclude the app is
/// broken or that they have lost a permission, and both guesses are worse than
/// the truth.
class _NotBuiltNote extends StatelessWidget {
  const _NotBuiltNote(this.text);

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
            child: Tag('Not built yet', warn: true),
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
