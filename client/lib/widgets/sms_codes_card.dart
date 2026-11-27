import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'error_note.dart';

/// Asking EzBizi to text your verification codes — and the consent record.
///
/// ── This card is a compliance document as much as a form ─────────
///
/// A carrier will not carry a code to a US mobile unless the sender can show
/// where the recipient agreed to receive it. Twilio's toll-free verification
/// asks for a screenshot of "the exact point where recipients give consent",
/// and eight things have to be visible in that screenshot:
///
///   a number field, a tick box that is NOT pre-ticked, what the messages are,
///   how often they come, that rates may apply, how to get HELP and how to
///   STOP, links to the terms and the privacy policy, and a submit button that
///   says what it does.
///
/// Every one of them is below, on one screen, deliberately — not spread over a
/// wizard. A consent split across three steps cannot be photographed, and what
/// cannot be photographed cannot be filed.
///
/// So: resist tidying this into something shorter. The wall of small print IS
/// the feature. The version of this card that looks better is the version that
/// gets the number's registration refused.
///
/// ── Why it can draw nothing at all ───────────────────────────────
///
/// `available` is false until EzBizi has a number of its own to send from.
/// Offering the form before then would take a mobile number and a consent for
/// a message that cannot be sent — an agreement nobody can act on, and
/// somebody left waiting for a code. See PlatformSms::configured().
class SmsCodesCard extends StatefulWidget {
  const SmsCodesCard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SmsCodesCard> createState() => _SmsCodesCardState();
}

class _SmsCodesCardState extends State<SmsCodesCard> {
  static const _termsUrl = 'https://ezbizi.com/terms';
  static const _privacyUrl = 'https://ezbizi.com/privacy';

  final _phone = TextEditingController();
  final _code = TextEditingController();

  /// Never true to begin with, and there is no code path that makes it so.
  ///
  /// A pre-ticked consent box is not consent, and it is the single thing most
  /// likely to get a filing rejected on sight.
  bool _consent = false;

  bool _loading = true;
  bool _busy = false;
  String? _error;
  String? _notice;
  Map<String, dynamic> _state = const {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/me/sms-codes');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        // The error is left alone: this reload follows every send and
        // confirm, and clearing it here wiped their refusal off the screen
        // the moment it was drawn.
        _state = (response.data as Map<String, dynamic>?) ?? const {};
      } else {
        _error = response.message;
      }
    });
  }

  bool get _available => _state['available'] == true;
  bool get _confirmed => _state['confirmed'] == true;
  bool get _pending => _state['pending'] == true;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });

    final response = await widget.api.post('/me/sms-codes', {
      'phone': _phone.text,
      // The word, not a boolean. A false that arrives because the client
      // forgot the field must never be able to read as agreement, and the
      // server refuses anything that is not this exact string.
      'consent': 'accepted',
    });

    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) {
        _notice = 'Code sent. Enter it below to finish.';
      } else {
        _error = response.message;
      }
    });

    await _load();
  }

  Future<void> _confirm() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/me/sms-codes/confirm', {
      'code': _code.text,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _notice = 'Your number is confirmed.';
        _code.clear();
        _consent = false;
      }
    });

    await _load();
  }

  Future<void> _turnOff() async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });

    final response = await widget.api.delete('/me/sms-codes');

    if (!mounted) return;

    // Only said when it is true: a confirmed opt-out that did not happen is
    // somebody who will go on being texted after being told they would not.
    setState(() {
      _busy = false;
      if (response.ok) {
        _notice = 'Turned off. EzBizi will not text this account.';
        _phone.clear();
        _consent = false;
      } else {
        _error = response.message;
      }
    });

    await _load();
  }

  Future<void> _open(String url) async {
    final uri = Uri.parse(url);
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Card(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }

    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Text me my codes', style: theme.textTheme.titleMedium),
            const SizedBox(height: 6),

            if (!_available)
              // Said plainly rather than hidden. Somebody who was told this
              // exists and finds nothing goes looking for a screen that is not
              // missing — it is not switched on yet.
              Text(
                'EzBizi cannot send text messages yet. This turns on once the '
                'sending number is set up.',
                style: theme.textTheme.bodyMedium,
              )
            else ...[
              if (_error != null) ...[
                ErrorNote(_error!),
                const SizedBox(height: 12),
              ],
              if (_notice != null) ...[
                Text(_notice!, style: theme.textTheme.bodyMedium),
                const SizedBox(height: 12),
              ],
              if (_confirmed)
                _on(theme)
              else if (_pending)
                _codeEntry(theme)
              else
                _form(theme),
            ],
          ],
        ),
      ),
    );
  }

  /// Already on. The number, and the way out.
  Widget _on(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Codes go to ${_state['number'] ?? 'your mobile'}.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 4),
        Text(
          'You can also stop them at any time by replying STOP to any message.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        OutlinedButton(
          onPressed: _busy ? null : _turnOff,
          child: const Text('Turn off text codes'),
        ),
      ],
    );
  }

  /// The number was taken and a code sent; it is not consented-and-done until
  /// somebody proves the phone is theirs.
  Widget _codeEntry(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'We sent a six-digit code to ${_state['number'] ?? 'your mobile'}. '
          'Enter it to finish.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Code from the message'),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            FilledButton(
              onPressed: _busy ? null : _confirm,
              child: const Text('Confirm my number'),
            ),
            const SizedBox(width: 8),
            TextButton(
              onPressed: _busy ? null : _turnOff,
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  /// The form the carrier filing is a photograph of. Every element below is
  /// required — see the note at the top of this file before removing any.
  Widget _form(ThemeData theme) {
    final small = theme.textTheme.bodySmall;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // 3 — what the messages are.
        Text(
          'EzBizi can text you a one-time code to verify it is you when you '
          'sign in, confirm a new device, or reset your password.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 16),

        // 1 — the number field.
        TextField(
          key: const Key('sms-codes-number'),
          controller: _phone,
          keyboardType: TextInputType.phone,
          // The submit button reads this field, so typing has to rebuild it.
          // Without this the button stays greyed out while a perfectly good
          // number sits in the box, which reads as the form being broken.
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(
            labelText: 'Mobile phone number',
            hintText: '(305) 555-0199',
          ),
        ),
        const SizedBox(height: 12),

        // 2 — the tick box, off, and it stays off until somebody taps it.
        CheckboxListTile(
          key: const Key('sms-codes-consent'),
          value: _consent,
          onChanged: _busy
              ? null
              : (v) => setState(() => _consent = v ?? false),
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: EdgeInsets.zero,
          title: Text(
            'Yes, text one-time verification codes to this number. I understand '
            'these are automated messages from EzBizi.',
            style: theme.textTheme.bodyMedium,
          ),
        ),
        const SizedBox(height: 12),

        // 4, 5, 6 — frequency, rates, HELP and STOP. Small print, and none of
        // it optional.
        Text(
          'Message frequency: only when you ask for a code — typically '
          'fewer than 5 messages per month.',
          style: small,
        ),
        const SizedBox(height: 4),
        Text(
          'Message and data rates may apply, depending on your mobile plan.',
          style: small,
        ),
        const SizedBox(height: 4),
        Text(
          'Reply HELP for help. Reply STOP to stop receiving messages at '
          'any time.',
          style: small,
        ),
        const SizedBox(height: 8),

        // 7 — the two links.
        Wrap(
          children: [
            TextButton(
              onPressed: () => _open(_termsUrl),
              child: const Text('Terms of Service'),
            ),
            TextButton(
              onPressed: () => _open(_privacyUrl),
              child: const Text('Privacy Policy'),
            ),
          ],
        ),
        const SizedBox(height: 8),

        // 8 — a button that says what pressing it does. Disabled until the box
        // is ticked AND a number is typed, so the consent cannot be skipped by
        // pressing past it.
        FilledButton(
          key: const Key('sms-codes-submit'),
          onPressed: (_busy || !_consent || _phone.text.trim().isEmpty)
              ? null
              : _submit,
          child: const Text('Yes, text me my codes'),
        ),
      ],
    );
  }
}
