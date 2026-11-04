import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/hr.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// Every invitation and where it got to (HR plan, "Invitations").
///
/// ── The invitation carries the role AND the assignment ───────────
///
/// The owner's decision, and it is what makes this the first piece of HR rather
/// than a mailing list: nothing on this platform wrote `users.role_id`, so an
/// organization was permanently one person however many roles it composed. The
/// inviter chooses the role and the place when inviting, the link is complete,
/// and whoever follows it lands somewhere real instead of in limbo.
///
/// ── Why the link is shown even when the mail goes ────────────────
///
/// The panel shows the link with a copy button and a strip saying what became
/// of the message. The strip is load-bearing: without it somebody invites a
/// person, closes the panel, and waits for an acceptance that was never going
/// to come.
///
/// This paragraph used to say "nothing here can send mail — AWS blocks port 25
/// outbound and SES has no credential". Mail has sent since SES production
/// access on 2026-09-14, so the reason changed rather than expired: the link is
/// here because a send can still go NOWHERE — a box on `log` or `array`, a
/// blank credential, a refused transport — and the inviter is the only person
/// who can hand it over when that happens.
///
/// It is the third sandbox-era sentence found in HR, and it survived a sweep I
/// had already run over this very file. Not because the pattern missed it —
/// "send.*mail" matches it — but because I piped the results through a filter
/// requiring a quote character, which kept the string literals and dropped
/// every comment. The stale sentences live in the comments. A filter that
/// narrows to "user-facing copy" is exactly the wrong instinct here.
///
/// The raw token exists for exactly one response — creating and resending both
/// answer with it and it is never readable again — so the panel showing it
/// opens from that answer, not from a later fetch.
class InvitationsTab extends StatefulWidget {
  const InvitationsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<InvitationsTab> createState() => _InvitationsTabState();
}

class _InvitationsTabState extends State<InvitationsTab> {
  bool _loading = true;
  String? _error;

  /// Which load owns the screen — the argument customers_screen.dart sets out.
  /// Every write here ends in a refresh and the control that triggered it is
  /// not hidden behind a spinner, so two can be in flight together and the
  /// answer that lands last need not be the one asked for last.
  int _request = 0;

  /// One request at a time where it APPENDS. A page-one load is never refused,
  /// it supersedes — refusing it loses the newer intent permanently.
  bool _fetching = false;

  List<Map<String, dynamic>> _invitations = const [];
  int _total = 0;

  /// The tile picked: sent, opened, completed, abandoned or revoked; null is
  /// every invitation. The server filters, so the count and the rows agree.
  String? _status;

  String _search = '';
  Timer? _searchDelay;

  @override
  void dispose() {
    _searchDelay?.cancel();
    super.dispose();
  }

  /// Each tile's count, across every page — the server's, never this page's.
  Map<String, int>? _tiles;
  int _page = 1;
  int _lastPage = 1;

  /// A later page failing leaves page one intact, so it reports beside the rows
  /// rather than over them.
  String? _pageError;

  /// What the invite form may offer. Entirely the server's answer — see
  /// [AssignmentOptions] for why none of it is worked out here.
  AssignmentOptions _options = AssignmentOptions.none;

  /// Why the form cannot be opened, when it cannot. Kept apart from [_error]:
  /// not being able to load the choices does not make the list of invitations
  /// unreadable, and blanking the page over it hides the rows somebody came
  /// here to read.
  String? _cannotInvite;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// The list's search box (Jose, 2026-10-14: "we lack searchbars on the 3
  /// last human resources tabs"), as Records has one.
  Widget _searchField() => Align(
    alignment: Alignment.centerLeft,
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 320),
      child: ListSearch(
        key: const ValueKey('tour-hr-invitations-search'),
        hint: 'Search by name or email',
        onChanged: (value) {
            _searchDelay?.cancel();
            _searchDelay = Timer(const Duration(milliseconds: 300), () {
              _search = value.trim();
              _load();
            });
          },
      ),
    ),
  );

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep (see AsyncBody). The
      // refresh after inviting, resending or revoking leaves the rows up.
      _loading = page == 1 && _invitations.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final answers = await Future.wait([
      widget.api.get('/hr/invitations', {
        'page': '$page',
        'status': ?_status,
        if (_search.isNotEmpty) 'search': _search,
      }),
      // Asked again on every load rather than once: a role can be created, and
      // an administrator can widen or narrow somebody's reach, while this
      // screen is open. It is the same request the form depends on being right.
      widget.api.get('/hr/people/scope-options'),
    ]);

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    final invitations = answers[0];
    final options = answers[1];

    setState(() {
      _loading = false;
      _fetching = false;

      if (!invitations.ok) {
        if (page == 1) {
          _error = invitations.reason;
        } else {
          _pageError = invitations.reason;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(invitations.data as List);
      _invitations = page == 1 ? rows : [..._invitations, ...rows];

      final meta = invitations.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _invitations.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
      final tiles = meta?['tiles'];
      _tiles = tiles is Map
          ? {
              for (final e in tiles.entries)
                '${e.key}': (e.value as num?)?.toInt() ?? 0,
            }
          : null;

      _options = options.ok && options.data is Map
          ? AssignmentOptions.fromJson(
              Map<String, dynamic>.from(options.data as Map),
            )
          : AssignmentOptions.none;

      _cannotInvite = _whyNot(options);
    });
  }

  /// Why the invite button is dead, or null when it is not.
  ///
  /// An invitation carries a role and a place, so either one missing means
  /// there is no complete invitation to send. Saying WHICH is missing is the
  /// difference between a button that does nothing and an errand somebody can
  /// go and run.
  String? _whyNot(ApiResponse options) {
    // 403 here is the ordinary answer for somebody whose role can read this tab
    // and not add to it. It is not a failure and must not be dressed as one.
    if (options.forbidden) {
      return 'You can see who has been invited, but not invite anybody.';
    }

    if (!options.ok) {
      return 'Who you may invite, and where, could not be loaded: '
          '${options.reason}';
    }

    if (!_options.roles.any((role) => role.assignable)) {
      return 'There is no role you may hand out, and an invitation carries '
          'one. A role can only be given by somebody who holds everything in '
          'it themselves.';
    }

    if (_options.scopes.isEmpty) {
      return 'There is nowhere you may assign somebody. An invitation carries '
          'a place, and this list holds only what you can reach yourself.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          note:
              'One person at a time. A link works once and expires after a '
              'week; “received” means they opened it.',
          action: FilledButton.icon(
            key: const ValueKey('tour-hr-invitations-invite'),
            onPressed: _cannotInvite == null ? _invite : null,
            icon: const Icon(Icons.person_add_alt_outlined, size: 18),
            label: const Text('Invite somebody'),
          ),
        ),
        if (_cannotInvite != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_cannotInvite!),
          ),
        Expanded(
          child: AsyncBody(
            key: const ValueKey('tour-hr-invitations-list'),
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _invitations.isEmpty && _status == null && _search.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: Text(
                        'Nobody has been invited yet.\n\n'
                        'An invitation carries the role and the place, so the '
                        'person who follows it lands somewhere real rather '
                        'than waiting to be set up.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    children: [
                      FilterTiles(
                        key: const ValueKey('tour-hr-invitations-tiles'),
                        prefix: 'invitations',
                        picked: _status,
                        onPick: (key) {
                          setState(() => _status = key);
                          _load();
                        },
                        tiles: [
                          FilterTile(
                            key: null,
                            label: 'All',
                            count: _tiles?.values.fold<int>(0, (a, b) => a + b),
                            note: 'invitations',
                          ),
                          FilterTile(
                            key: 'sent',
                            label: 'Sent',
                            count: _tiles?['sent'],
                            note: 'not opened yet',
                          ),
                          FilterTile(
                            key: 'opened',
                            label: 'Opened',
                            count: _tiles?['opened'],
                            note: 'not finished yet',
                          ),
                          FilterTile(
                            key: 'completed',
                            label: 'Completed',
                            count: _tiles?['completed'],
                            note: 'have an account',
                          ),
                          FilterTile(
                            key: 'abandoned',
                            label: 'Expired',
                            count: _tiles?['abandoned'],
                            note: 'worth a resend',
                            tone: Tone.caution,
                          ),
                          FilterTile(
                            key: 'revoked',
                            label: 'Revoked',
                            count: _tiles?['revoked'],
                            note: 'taken back',
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      _searchField(),
                      const SizedBox(height: 12),
                      if (_invitations.isEmpty)
                        Padding(
                          padding: const EdgeInsets.all(24),
                          child: Text(
                            _search.isEmpty
                                ? 'No invitations in this state.'
                                : 'No invitations match that search.',
                            textAlign: TextAlign.center,
                          ),
                        )
                      else
                        ListTable(
                          columns: const [
                            ListColumn('Person', flex: 4),
                            ListColumn('Role', flex: 2),
                            ListColumn('Works at', flex: 2),
                            ListColumn('Status', flex: 2),
                            ListColumn('Invited by', flex: 2),
                            ListColumn('When', flex: 3),
                            ListColumn('', flex: 2, end: true),
                          ],
                          rowKey: (i) =>
                              ValueKey('invitation-${_invitations[i]['id']}'),
                          rows: [
                            for (final invitation in _invitations)
                              _cells(context, invitation),
                          ],
                          footer: _page < _lastPage
                              ? LoadMore(
                                  shown: _invitations.length,
                                  total: _total,
                                  busy: _fetching,
                                  error: _pageError,
                                  onMore: () => _load(page: _page + 1),
                                )
                              : null,
                        ),
                    ],
                  ),
          ),
        ),
      ],
    );
  }

  /// The cells of one table row (layout A, Mia 2026-10-02): the person, the
  /// role, where they will work, where it got to, who sent it, when, and what
  /// can still be done to it.
  List<Widget> _cells(BuildContext context, Map<String, dynamic> invitation) {
    final theme = Theme.of(context);
    final status = invitation['status'] as String? ?? '';
    final role = invitation['role'] as Map<String, dynamic>?;
    final scope = invitation['scope'] as Map<String, dynamic>?;
    final invitedBy = invitation['invited_by'] as Map<String, dynamic>?;
    // An erased person's stand-in address ("removed-…@invalid") is not a
    // name anybody can read (deep dive 2026-11-02 #4).
    final rawEmail = invitation['email'] as String? ?? '';
    final email = rawEmail.endsWith('@invalid') ? 'Removed person' : rawEmail;

    final name = [
      invitation['first_name'],
      invitation['last_name'],
    ].whereType<String>().where((part) => part.trim().isNotEmpty).join(' ');

    // A revoked one is a decision somebody made, and the server refuses to
    // resend it. An accepted one has nothing to resend but can still be
    // revoked (Jose, 2026-10-05: "regardless of anything"). Expired is NOT
    // settled: chasing one that lapsed is the most useful thing Resend does.
    final settled = status == 'revoked';
    // Accepted: nothing to resend. Held: it goes out when the paperwork is
    // signed, not before.
    final accepted = status == 'completed' || invitation['held'] == true;
    final mayAct = widget.session.me?.can('hr.create') == true;
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return [
      PersonCell(
        name: name.isEmpty ? email : name,
        sub: name.isEmpty ? null : email,
      ),
      Text(role?['name'] as String? ?? '—', overflow: TextOverflow.ellipsis),
      Text(scope?['name'] as String? ?? '—', overflow: TextOverflow.ellipsis),
      Tag(
        invitationStatusLabel(status),
        warn: invitationStatusNeedsAttention(status),
        good: status == 'completed',
      ),
      Text(
        invitedBy?['name'] as String? ?? '—',
        overflow: TextOverflow.ellipsis,
        style: small,
      ),
      Text(_outcome(invitation, status), style: small),
      settled
          ? const SizedBox.shrink()
          : Wrap(
              alignment: WrapAlignment.end,
              children: [
                if (!accepted)
                  Refused(
                    reason: widget.session.me?.refusal(
                      'hr.create',
                      'resend invitations',
                    ),
                    child: TextButton(
                      onPressed: mayAct ? () => _resend(invitation) : null,
                      child: const Text('Resend'),
                    ),
                  ),
                Refused(
                  reason: widget.session.me?.refusal(
                    'hr.create',
                    'withdraw invitations',
                  ),
                  child: TextButton(
                    // Red: it withdraws the link (phone audit 2026-11-04).
                    style: TextButton.styleFrom(
                      foregroundColor: Theme.of(context).colorScheme.error,
                    ),
                    onPressed: mayAct ? () => _revoke(invitation) : null,
                    child: const Text('Revoke'),
                  ),
                ),
              ],
            ),
    ];
  }

  /// What became of it, in a phrase.
  ///
  /// The status already says which of the two ends this was — "abandoned" and
  /// "revoked" are separate words since 2026-08-11, so this no longer has to
  /// tell them apart by looking for `revoked_at`. It adds the WHEN, which the
  /// status cannot carry.
  String _outcome(Map<String, dynamic> invitation, String status) {
    String at(Object? iso) =>
        formatStamp(iso, 'at no stated time', formats: _formats);

    if (invitation['held'] == true) {
      return 'sent once the paperwork is signed';
    }

    return switch (status) {
      'completed' => 'accepted ${at(invitation['accepted_at'])}',
      'revoked' => 'withdrawn ${at(invitation['revoked_at'])}',
      'abandoned' => 'expired ${at(invitation['expires_at'])}',
      _ => 'expires ${at(invitation['expires_at'])}',
    };
  }

  // ── inviting, resending, revoking ────────────────────────────────

  Future<void> _invite() async {
    final created = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => _InvitePanel(api: widget.api, options: _options),
    );

    if (created == null || !mounted) return;

    // With paperwork it is held until the employer signs (Jose, 2026-10-06).
    if (created['held'] == true) {
      _snack(
        'Saved. The invitation is emailed once you sign the paperwork, '
        'under Onboarding.',
      );
    } else {
      await _showLink(created, resent: false);
    }

    if (mounted) await _load();
  }

  /// Resend, and — when [email] is given — move it to a different address.
  ///
  /// One call, because on the server it is one act: the address rides the
  /// reissue so the old token dies with it. A PATCH that repointed the row
  /// would leave the already-sent link alive, and if the mistyped address
  /// belongs to a real person, that stranger keeps a working invitation into
  /// this organization while this screen says it is fixed.
  Future<void> _resend(Map<String, dynamic> invitation, {String? email}) async {
    final response = await widget.api.post(
      '/hr/invitations/${invitation['id']}/resend',
      email == null ? const {} : {'email': email},
    );

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    // The answer carries a NEW token — the old one stopped working the moment
    // this succeeded — so the link is shown again rather than leaving somebody
    // with the one they copied last time.
    await _showLink(
      Map<String, dynamic>.from(response.data as Map),
      resent: true,
    );

    if (mounted) await _load();
  }

  Future<void> _revoke(Map<String, dynamic> invitation) async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Revoke this invitation?',
        subtitle: invitation['email'] as String?,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Revoke'),
          ),
        ],
        child: Text(
          invitation['status'] == 'completed'
              ? 'They already accepted. Revoking switches their access off and '
                    'cancels their paperwork, signed or not. Records can switch '
                    'them back on.'
              : 'The link stops working. Anybody already holding it sees an '
                    'invitation that no longer exists, and inviting them again '
                    'is a new link rather than the same one coming back. The '
                    'row stays on this list — "we invited them and changed our '
                    'mind" is worth being able to answer later.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.api.delete(
      '/hr/invitations/${invitation['id']}',
    );

    if (!mounted) return;

    // A refusal that reloaded the list would look exactly like a success, with
    // the row simply reappearing and nothing said about why.
    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    await _load();
  }

  /// The one moment the raw token exists, shown with what became of the send.
  Future<void> _showLink(
    Map<String, dynamic> invitation, {
    required bool resent,
  }) {
    final url = invitation['accept_url'] as String?;
    final expires = formatStamp(
      invitation['expires_at'],
      'in a week',
      formats: _formats,
    );

    return showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: resent ? 'A new link' : 'Invitation ready',
        subtitle: invitation['email'] as String?,
        width: 520,
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Done'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            // The load-bearing sentence. Everything else on this panel is
            // convenience; this is the part that stops somebody waiting for an
            // acceptance to an invitation that was never delivered — or
            // chasing somebody who has had it in their inbox all along.
            //
            // It used to assert that nothing had been emailed and that the
            // platform could not send at all. That was true when it was
            // written and stopped being true the day SES was wired up, with
            // nothing to make it fail loudly — the server now answers the
            // question instead of the client assuming it.
            //
            // What became of the send, in the server's own answer rather than
            // this end's inference. See _strip for the states and for why an
            // unreadable answer is not the same as an absent one.
            _strip(context, invitation),
            const SizedBox(height: 16),
            if (url == null || url.isEmpty)
              const ErrorNote(
                'The server did not return a link, so there is nothing to send '
                'them. Revoke this one and invite them again.',
              )
            else ...[
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Theme.of(
                    context,
                  ).colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: SelectableText(
                  url,
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: url));

                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Invitation link copied')),
                    );
                  }
                },
                icon: const Icon(Icons.copy_outlined, size: 18),
                label: const Text('Copy link'),
              ),
            ],
            const SizedBox(height: 16),
            Text(
              resent
                  ? 'The previous link has stopped working. This one works '
                        'once and expires $expires.'
                  : 'It works once and expires $expires.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  /// The status strip: one state, its reason, and one action.
  ///
  /// Layout is Jose's pick of 2026-09-14 — desktop 2, the strip above the
  /// sentence; mobile 5, the same strip with the action at the bottom where a
  /// thumb is. It carries the three states the SERVER can prove and reserves
  /// room for `bounced`, which arrives when SES's events land as typed data.
  /// Nothing here names a cause that was guessed: there is no "the address was
  /// refused", because on this send path a rejected recipient and a dead
  /// connection are the same exception, and the cost of guessing wrong is
  /// telling somebody to correct an address that was right.
  Widget _strip(BuildContext context, Map<String, dynamic> invitation) {
    final theme = Theme.of(context);

    // Absent means a server older than this release. It claims neither
    // outcome, and it is deliberately not drawn as a failure: "we do not know"
    // is not bad news.
    final state = invitation['sent_state'] as String?;

    final (String sentence, bool bad) = switch (state) {
      'sent' => ('Sent to them.', false),
      'not_sent_no_transport' => (
        'Not sent — this installation is not sending mail at all.',
        true,
      ),
      'not_sent_refused' => (
        'Not sent — the message was refused when we tried to send it.',
        true,
      ),

      // ABSENT and UNREADABLE are two different facts, and collapsing them
      // into one fallback is how a bad outcome gets a reassuring sentence.
      //
      // `null` is a server OLDER than this field. It said nothing, so this
      // claims nothing, and it is not drawn as a failure because not knowing
      // is not bad news.
      null => ('May or may not have been sent.', false),

      // Any other string is a server NEWER than this build — it answered, and
      // this version cannot read the answer. That must not borrow the
      // sentence above: an unrecognised state rendering as "may or may not
      // have been sent" is a plausible, reassuring answer to a question the
      // server may well have answered badly, and somebody would stop chasing
      // on the strength of it. Found by UI/UX reading the switch rather than
      // my description of it, after I told them a state was handled here that
      // was not.
      // UI/UX's wording, taken over mine: it names the CAUSE — an unknown
      // state means this app is behind the server, which is a fact about the
      // reader's app rather than about their invitation — and then gives the
      // remedy for that cause rather than only the fallback. "Build" was our
      // word; nobody thinks in builds.
      _ => (
        'This app is older than the server and cannot read what it said about '
            'this message. Update the app, or hand the link over if you need '
            'to be sure it arrived.',
        true,
      ),
    };

    final scheme = theme.colorScheme;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: (bad ? scheme.errorContainer : scheme.surfaceContainerHighest)
            .withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                bad ? Icons.error_outline : Icons.outgoing_mail,
                size: 18,
                color: bad ? scheme.error : scheme.outline,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  sentence,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: bad ? scheme.error : null,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          // Offered in EVERY state, success included, and that is the point
          // rather than an oversight: the invitation keeps the address on
          // record whatever became of the send, so noticing a typo right after
          // a successful one is exactly when somebody wants this. It does not
          // depend on knowing why anything failed.
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => _readdress(invitation),
              icon: const Icon(Icons.alternate_email, size: 18),
              label: const Text('Re-invite at a different address'),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(left: 12),
            child: Text(
              // On the control rather than behind a confirmation. The dead
              // link is most of what this does, and a dialog confirming an
              // address somebody just typed is the kind people learn to
              // dismiss.
              'The current link stops working.',
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
            ),
          ),
        ],
      ),
    );
  }

  /// Ask for the corrected address, then reissue to it.
  Future<void> _readdress(Map<String, dynamic> invitation) async {
    final controller = TextEditingController(
      text: invitation['email'] as String? ?? '',
    );

    final email = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: 'Re-invite at a different address',
        subtitle: invitation['email'] as String?,
        width: 480,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: const Text('Re-invite'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'A new link goes to this address and the current one stops '
              'working. If the address on record was wrong, whoever received '
              'the old message can no longer use it.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            TextField(
              controller: controller,
              autofocus: true,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(
                labelText: 'Email address',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
      ),
    );

    if (email == null || email.isEmpty || !mounted) return;

    // Closes the link panel this was opened from, so the new one is not
    // stacked behind a panel showing a link that has just been killed.
    if (Navigator.of(context).canPop()) Navigator.of(context).pop();

    await _resend(invitation, email: email);
  }

  void _snack(String message, {bool isError = false}) =>
      showFloatingSnack(context, message, isError: isError);
}

/// Inviting one person: who they are, what they may do, and where.
class _InvitePanel extends StatefulWidget {
  const _InvitePanel({required this.api, required this.options});

  final ApiClient api;
  final AssignmentOptions options;

  @override
  State<_InvitePanel> createState() => _InvitePanelState();
}

class _InvitePanelState extends State<_InvitePanel> {
  final _email = TextEditingController();
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();

  String? _roleId;
  String? _scope;

  // The paperwork, started with the invite when switched on (Jose,
  // 2026-09-30: "yes but toggable, some people dont need contracts"). The
  // contracts load the first time it is switched on, not for every invite.
  bool _withContract = false;
  List<Map<String, dynamic>>? _contracts;
  String? _contractsError;
  String? _contractId;

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();

    // One choice is not a choice. Somebody who can only assign to one place
    // should not have to tell the form what it already knows.
    final places = widget.options.scopes.where((s) => s.isActive).toList();

    if (places.length == 1) _scope = places.first.value;
  }

  @override
  void dispose() {
    _email.dispose();
    _firstName.dispose();
    _lastName.dispose();
    super.dispose();
  }

  /// Everything except the names, which the person fills in when they accept —
  /// the HR plan's common core is deliberately half-optional at this end,
  /// because an employer guessing a date of birth and getting it wrong is worse
  /// than leaving it blank.
  bool get _complete =>
      _email.text.trim().isNotEmpty &&
      _roleId != null &&
      _scope != null &&
      (!_withContract || _contractId != null);

  Future<void> _switchContract(bool on) async {
    setState(() => _withContract = on);
    if (!on || _contracts != null) return;

    final response = await widget.api.get('/hr/contracts');
    if (!mounted) return;

    final rows = response.ok ? response.data : null;
    setState(() {
      if (rows is List) {
        _contracts = [
          for (final row in rows)
            if (row is Map) Map<String, dynamic>.from(row),
        ];
        _contractsError = null;
      } else {
        _contractsError =
            'The contracts could not be loaded: ${response.reason}';
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    // Split back into the pair the API takes. The dropdown holds one string
    // because a dropdown can only hold one value; the wire has always been two
    // fields, and §4's XOR is the reason — one target level, never two.
    final parts = _scope!.split(':');

    final response = await widget.api.post('/hr/invitations', {
      'email': _email.text.trim(),
      'first_name': _firstName.text.trim(),
      'last_name': _lastName.text.trim(),
      'role_id': _roleId,
      'scope_type': parts.first,
      'scope_id': parts.sublist(1).join(':'),
      if (_withContract) 'contract_id': _contractId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    // The whole answer, because the link is in it and this is the only response
    // that will ever carry the raw token.
    Navigator.of(context).pop(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Invite somebody',
      subtitle:
          'They get a login, the role you choose, and a place to work in. '
          'Their paperwork can start now or later, and plenty of people need '
          'an account and nothing else.',
      width: 520,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || !_complete) ? null : _submit,
          child: Text(_busy ? 'Inviting…' : 'Create the invitation'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _email,
            autofocus: true,
            enabled: !_busy,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Email',
              border: OutlineInputBorder(),
              helperText: 'Where the link goes, and how they sign in.',
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _firstName,
                  enabled: !_busy,
                  decoration: const InputDecoration(
                    labelText: 'First name',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  controller: _lastName,
                  enabled: !_busy,
                  decoration: const InputDecoration(
                    labelText: 'Last name',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Optional. Whatever you leave blank they fill in themselves, and '
            'they can correct whatever you get wrong.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 18),
          SearchPickerField<String>(
            value: _roleId,
            label: 'Role',
            helperText: 'Which tabs they see, and what they may do inside.',
            border: OutlineInputBorder(),
            options: [
              for (final role in widget.options.roles)
                SearchOption(
                  value: role.id,
                  label: role.assignable
                      ? role.name
                      : '${role.name} — not yours to give',
                  enabled: role.assignable,
                ),
            ],
            onChanged: _busy ? null : (v) => setState(() => _roleId = v),
          ),
          const SizedBox(height: 18),
          SearchPickerField<String>(
            value: _scope,
            label: 'Where they are assigned',
            helperText:
                'Everything beneath it comes with it. This list holds only '
                'places you can reach yourself.',
            helperMaxLines: 3,
            border: OutlineInputBorder(),
            options: [
              for (final scope in widget.options.scopes)
                SearchOption(
                  value: scope.value,
                  label: scope.isActive
                      ? scope.pathLabel
                      : '${scope.pathLabel} — deactivated',
                  enabled: scope.isActive,
                ),
            ],
            onChanged: _busy ? null : (v) => setState(() => _scope = v),
          ),
          const SizedBox(height: 8),
          SwitchListTile(
            key: const ValueKey('invite-with-contract'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Start their paperwork now'),
            subtitle: const Text(
              'They find the contract waiting when they accept, and you can '
              'fill in your half before they do.',
            ),
            value: _withContract,
            onChanged: _busy ? null : _switchContract,
          ),
          if (_withContract && _contractsError != null)
            ErrorNote(_contractsError!)
          else if (_withContract && _contracts == null)
            const LinearProgressIndicator(minHeight: 2)
          else if (_withContract && _contracts!.isEmpty)
            const ErrorNote(
              'There are no contracts yet. The Contracts tab is where they are '
              'made.',
            )
          else if (_withContract)
            SearchPickerField<String>(
              value: _contractId,
              label: 'Contract',
              helperText: 'What they will be asked, and what they will sign.',
              border: const OutlineInputBorder(),
              options: [
                for (final contract in _contracts!)
                  if (contract['id'] is String)
                    SearchOption(
                      value: contract['id'] as String,
                      label: contract['name'] as String? ?? '',
                    ),
              ],
              onChanged: _busy ? null : (v) => setState(() => _contractId = v),
            ),
          if (_error != null) ...[
            const SizedBox(height: 18),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
