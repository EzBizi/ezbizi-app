/// The connection behind a shared inbox, as the Settings sub-tab reads it.
///
/// ── The wire this end was built against ─────────────────────────
///
/// `GET /email/mailboxes` answers with ONE row shape that both sub-tabs read,
/// and they read different halves of it. The Inbox tab wants the address and
/// whether it may be replied from (mail_data.dart); this tab wants the
/// CONNECTION — where it hangs off the business, which service it lives on,
/// whether it is working and when it last did.
///
/// Two readings of one answer rather than one shared class, which is the same
/// call core/role_inboxes.dart makes beside core/permissions.dart and for the
/// same reason: neither reading should be able to change the other's shape.
///
///   GET  /email/mailboxes
///     data: [ { id, address, display_name,
///               provider, provider_label,
///               scope_type: organization|company|franchise, scope_id,
///               status: connected|unconfigured|broken, status_reason,
///               can_be_opened, unavailable_reason,
///               last_reached_at,                    ← see below
///               mail_server: { imap_host, imap_port, imap_encryption,
///                              smtp_host, smtp_port, smtp_encryption,
///                              imap_username, smtp_username },
///               may_manage } ]
///     meta: { providers: [ { value, label, needs_host, caution } ],
///             imap_ports: [...], smtp_ports: [...] }
///
///   POST   /email/mailboxes
///     { scope_type, scope_id, address, display_name, provider, mail_server }
///     → 201, data: the row above
///
///   POST   /email/mailboxes/{mailbox}/connect
///     { credential }  → data: the row above
///
///   DELETE /email/mailboxes/{mailbox}
///     → { message }   — the server's own sentence about what has just gone
///
/// Every key above is one MailboxesController::row already answers with. It was
/// written here as a stated assumption while `last_reached_at` was being added
/// at the other end in parallel, and then checked against the controller: the
/// two agree, including on the paragraph below, which both ends reached
/// separately.
///
/// ── `last_reached_at` may not be there, and absent is not "never" ──
///
/// The server sends the key ALWAYS and sends null for an address it has never
/// opened. Until that change landed the key was simply missing, and the two are
/// read apart deliberately — see [MailboxConnection.lastReachedKnown]. A
/// MISSING key is a server older than this screen, and the line is left off; a
/// key present and null is the server saying this address has never once
/// answered, which is a fact worth printing. Drawing "never" for both would be
/// this screen inventing an outage on an inbox somebody is reading mail in.
///
/// ── The scope's NAME is deliberately not on the wire ────────────
///
/// Only `scope_type` and `scope_id`. Every mailbox in the list is at a level
/// this person reaches, so `/me` already names it — and §8 lets an organization
/// rename the franchise level and lets four companies each run a "Connecticut",
/// so the label somebody should read is a path built from their own vocabulary
/// rather than a string a controller could compose. [scopeLabel] builds it.
///
/// ── The credential is not in the shape above, in either direction ──
///
/// It goes in at `connect` and it never comes back: `Mailbox::mayReveal`
/// answers false for everybody including the administrator who typed it, and
/// the controller's row is an allowlist that does not name it. So there is no
/// field for it here, and nothing on this screen can draw one by accident.
library;

import 'package:flutter/foundation.dart';

import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/franchise_field.dart';

/// The three states a connection can be in, as the server records them.
///
/// The strings are the server's own constants (`Mailbox::STATUS_*`). They are
/// matched and never shown — what a person reads is [MailboxState], which is a
/// word and a sentence.
const String statusConnected = 'connected';
const String statusUnconfigured = 'unconfigured';
const String statusBroken = 'broken';

/// One connected — or half-connected, or broken — shared address.
@immutable
class MailboxConnection {
  const MailboxConnection({
    required this.id,
    required this.address,
    required this.displayName,
    required this.provider,
    required this.providerLabel,
    required this.scopeType,
    required this.scopeId,
    required this.status,
    required this.statusReason,
    required this.canBeOpened,
    required this.unavailableReason,
    required this.lastReachedAt,
    required this.lastReachedKnown,
    this.sendCheckedAt,
    this.sendProblem,
    required this.mailServer,
    required this.mayManage,
    required this.markReadAtSource,
    required this.canMarkReadAtSource,
  });

  final String id;

  /// `info@a-1chimney.com`. The identity, and the heading of the row — never
  /// [displayName], which is a nickname two inboxes can share.
  final String address;

  /// What the organization calls it. May be empty.
  final String displayName;

  /// The wire value — `imap`, `gmail_oauth`. Compared against the `value` of
  /// the entries in `meta.providers` and shown to nobody.
  final String provider;

  /// The server's words for it, which is what appears on screen. Empty when the
  /// server sent a provider this build's `meta` block does not describe.
  final String providerLabel;

  /// Which part of the business owns it: `organization`, `company`,
  /// `franchise`. Two inboxes called info@ under two different companies are
  /// the case this app has already hit, so this is not decoration.
  final String scopeType;
  final String scopeId;

  final String status;

  /// What went wrong, in the server's sentence — the difference between a
  /// password that was changed and consent that was withdrawn.
  ///
  /// This is the whole reason the owner asked for this tab: *"it should
  /// definitely display the message of revoked or expired"*. Written by
  /// `Mailbox::markBroken`, printed here verbatim, never interpreted.
  final String? statusReason;

  /// Whether the reader behind this provider could open it right now.
  ///
  /// Not the same question as [status], which is why both are on the wire. A
  /// mailbox can hold a perfectly good credential on an installation that has
  /// no Google registration at all, and calling that "connected" would send
  /// somebody hunting for a fault at their end.
  final bool canBeOpened;

  /// Why not, in the server's words. Null when it can.
  final String? unavailableReason;

  /// When "can send" was last checked without sending, and why it failed.
  /// Null [sendCheckedAt]: not checked yet (Jose 11-12).
  final String? sendCheckedAt;
  final String? sendProblem;

  /// ISO-8601 of the last time this address answered, or null for never.
  ///
  /// Only meaningful when [lastReachedKnown]; see the library note.
  final String? lastReachedAt;

  /// Whether the server answered the question at all.
  ///
  /// False means the key was absent from the row, which is a server older than
  /// this screen rather than an inbox that has never worked. The line is left
  /// off in that case, because "never reached" is an alarming thing to say
  /// about an inbox somebody is reading mail in.
  final bool lastReachedKnown;

  /// The host details, for the provider that has any. Never a credential — the
  /// server's allowlist narrows this to hosts, ports, encryption and usernames.
  final Map<String, dynamic> mailServer;

  /// Whether this person may connect, rename or disconnect THIS inbox — the
  /// server's own answer, from the check that will be made again on the write.
  final bool mayManage;

  /// Whether opening a message here also marks it read at the mail service.
  final bool markReadAtSource;

  /// Whether this connection CAN do that — the provider's answer, not a guess
  /// from [provider]. A Gmail mailbox connected without the modify scope
  /// cannot, and a screen holding its own table of which provider can do what
  /// is a second dictionary that will one day disagree with the one deciding.
  final bool canMarkReadAtSource;

  /// The line at the top of the row. The address always, and the nickname
  /// beside it only when it says something the address does not.
  bool get named =>
      displayName.trim().isNotEmpty && displayName.trim() != address;

  factory MailboxConnection.fromJson(Map<String, dynamic> json) =>
      MailboxConnection(
        id: '${json['id']}',
        address: json['address'] as String? ?? '',
        displayName: json['display_name'] as String? ?? '',
        provider: json['provider'] as String? ?? '',
        providerLabel: json['provider_label'] as String? ?? '',
        scopeType: json['scope_type'] as String? ?? '',
        scopeId: '${json['scope_id']}',
        // An absent status is read as unconfigured rather than as connected:
        // a row that says nothing about itself has certainly not been proven
        // to work, and the safe direction is the one that tells somebody to
        // go and finish the setup.
        status: json['status'] as String? ?? statusUnconfigured,
        statusReason: json['status_reason'] as String?,
        canBeOpened: json['can_be_opened'] == true,
        unavailableReason: json['unavailable_reason'] as String?,
        lastReachedAt: json['last_reached_at'] as String?,
        lastReachedKnown: json.containsKey('last_reached_at'),
        sendCheckedAt: json['send_checked_at'] as String?,
        sendProblem: json['send_problem'] as String?,
        mailServer: json['mail_server'] as Map<String, dynamic>? ?? const {},
        mayManage: json['may_manage'] == true,
        markReadAtSource: json['mark_read_at_source'] == true,
        canMarkReadAtSource: json['can_mark_read_at_source'] == true,
      );

  static List<MailboxConnection> listFrom(Object? data) => [
    for (final entry in (data is List ? data : const []))
      if (entry is Map<String, dynamic>) MailboxConnection.fromJson(entry),
  ];
}

/// One of the ways an address can be set up, as the server publishes them.
@immutable
class ProviderOption {
  const ProviderOption({
    required this.value,
    required this.label,
    required this.needsHost,
    required this.connectsBy,
    required this.caution,
    required this.usable,
    required this.unusableBecause,
  });

  final String value;
  final String label;

  /// Whether this one is configured with a mail server and a password.
  ///
  /// It decides which FIELDS the IMAP form draws. It does not decide how a
  /// provider is finished — see [connectsBy]. A third provider (Google
  /// Workspace) has no host and is not finished by signing in as the inbox.
  final bool needsHost;

  /// How this provider is finished: `sign_in`, `password`, or `directory`.
  ///
  /// Published by the server so this client never infers the connect road
  /// from [needsHost]. An older server that does not send the key is read as
  /// the two-way world this screen used to assume: a host means a password,
  /// no host means a sign-in.
  final String connectsBy;

  /// What to say beside the choice, in the server's words. Null when there is
  /// no warning to give.
  final String? caution;

  /// Whether this provider can work on this installation AT ALL.
  ///
  /// ── The assumption this replaces, and how it broke ───────────
  ///
  /// This screen used to decide whether to offer a live connect form by asking
  /// [needsHost], reasoning that a provider wanting a host is one somebody
  /// finishes by typing a password. The old comment here even named the case
  /// that would break it — "a hosted service with no host but a password" —
  /// and the real break was the opposite, and was already live.
  ///
  /// `imap` was removed from PHP core in 8.4 and the server runs 8.4.24, so the
  /// IMAP reader cannot open a socket. But IMAP needs a host, so it was given
  /// the full password form: somebody would have typed the password to their
  /// real mailbox into a form for a connection that could never be attempted,
  /// and it would have been stored. Google, equally unbuilt, was marked "not
  /// built yet" correctly — but only because it happens not to need a host.
  /// The screen was right by accident about one and wrong about the other.
  ///
  /// So the server answers it now, and this is that answer. Nothing here
  /// deduces readiness from a property that means something else.
  final bool usable;

  /// What is missing, in the server's words, when [usable] is false. Shown as
  /// sent — never rewritten here, and never replaced with a guess.
  final String? unusableBecause;

  factory ProviderOption.fromJson(Map<String, dynamic> json) => ProviderOption(
    value: json['value'] as String? ?? '',
    label: json['label'] as String? ?? '',
    needsHost: json['needs_host'] == true,
    connectsBy: json['connects_by'] as String? ??
        (json['needs_host'] == true ? 'password' : 'sign_in'),
    caution: json['caution'] as String?,
    // Absent means an older server that cannot answer. Treated as USABLE,
    // because the alternative is a client that silently refuses to connect
    // anything the day it runs ahead of the API — and the server refuses a
    // provider it cannot open anyway, with its own sentence.
    usable: json['usable'] as bool? ?? true,
    unusableBecause: json['unusable_because'] as String?,
  );

  static List<ProviderOption> listFrom(Object? meta) => [
    for (final entry
        in ((meta is Map<String, dynamic> ? meta['providers'] : null) as List? ??
            const []))
      if (entry is Map<String, dynamic>) ProviderOption.fromJson(entry),
  ];
}

/// One Google Workspace domain grant, as Email settings reads it.
@immutable
class WorkspaceLink {
  const WorkspaceLink({
    required this.domain,
    required this.adminEmail,
    required this.status,
    required this.statusReason,
    required this.scopeType,
    required this.scopeId,
    required this.scopeLabel,
    required this.isCurrentView,
    required this.clientIdForAdminConsole,
    required this.scopes,
    required this.setupSteps,
    this.health,
    this.marketplaceListingUrl,
  });

  final String domain;
  final String adminEmail;
  final String status;
  final String? statusReason;
  final String scopeType;
  final String scopeId;
  final String scopeLabel;
  final bool isCurrentView;
  final String? clientIdForAdminConsole;
  final List<String> scopes;
  final List<WorkspaceSetupStep> setupSteps;
  final WorkspaceHealth? health;
  final String? marketplaceListingUrl;

  bool get connected => status == 'connected';

  bool get pending => status == 'pending_delegation';

  factory WorkspaceLink.fromJson(Map<String, dynamic> json) => WorkspaceLink(
    domain: json['domain'] as String? ?? '',
    adminEmail: json['admin_email'] as String? ?? '',
    status: json['status'] as String? ?? '',
    statusReason: json['status_reason'] as String?,
    scopeType: json['scope_type'] as String? ?? 'organization',
    scopeId: json['scope_id'] as String? ?? '',
    scopeLabel: json['scope_label'] as String? ?? '',
    isCurrentView: json['is_current_view'] == true,
    clientIdForAdminConsole: json['client_id_for_admin_console'] as String?,
    scopes: [
      for (final scope in json['scopes'] as List? ?? const [])
        if (scope is String) scope,
    ],
    setupSteps: [
      for (final step in json['setup_steps'] as List? ?? const [])
        if (step is Map<String, dynamic>) WorkspaceSetupStep.fromJson(step),
    ],
    health: json['health'] is Map<String, dynamic>
        ? WorkspaceHealth.fromJson(json['health'] as Map<String, dynamic>)
        : null,
    marketplaceListingUrl: json['marketplace_listing_url'] as String?,
  );
}

@immutable
class WorkspaceHealth {
  const WorkspaceHealth({
    required this.status,
    required this.summary,
    required this.scopes,
  });

  /// `ok`, `needs_attention`, or `pending`.
  final String status;
  final String summary;
  final List<WorkspaceScopeCheck> scopes;

  bool get needsAttention => status == 'needs_attention';

  bool get ok => status == 'ok';

  factory WorkspaceHealth.fromJson(Map<String, dynamic> json) =>
      WorkspaceHealth(
        status: json['status'] as String? ?? '',
        summary: json['summary'] as String? ?? '',
        scopes: [
          for (final row in json['scopes'] as List? ?? const [])
            if (row is Map<String, dynamic>) WorkspaceScopeCheck.fromJson(row),
        ],
      );
}

@immutable
class WorkspaceScopeCheck {
  const WorkspaceScopeCheck({
    required this.id,
    required this.label,
    required this.detail,
    required this.ok,
  });

  final String id;
  final String label;
  final String detail;
  final bool ok;

  factory WorkspaceScopeCheck.fromJson(Map<String, dynamic> json) =>
      WorkspaceScopeCheck(
        id: json['id'] as String? ?? '',
        label: json['label'] as String? ?? '',
        detail: json['detail'] as String? ?? '',
        ok: json['ok'] == true,
      );
}

@immutable
class WorkspaceSetupStep {
  const WorkspaceSetupStep({
    required this.title,
    required this.detail,
    this.url,
    this.copy,
  });

  final String title;
  final String detail;
  final String? url;
  final String? copy;

  factory WorkspaceSetupStep.fromJson(Map<String, dynamic> json) =>
      WorkspaceSetupStep(
        title: json['title'] as String? ?? '',
        detail: json['detail'] as String? ?? '',
        url: json['url'] as String?,
        copy: json['copy'] as String?,
      );
}

@immutable
class WorkspaceSettings {
  const WorkspaceSettings({
    required this.canConnect,
    required this.viewLabel,
    required this.connections,
    this.marketplaceListingUrl,
  });

  final bool canConnect;
  final String viewLabel;
  final List<WorkspaceLink> connections;
  final String? marketplaceListingUrl;

  factory WorkspaceSettings.fromJson(Map<String, dynamic> json) =>
      WorkspaceSettings(
        canConnect: json['can_connect'] == true,
        viewLabel: (json['view'] as Map<String, dynamic>?)?['label'] as String? ??
            '',
        marketplaceListingUrl: json['marketplace_listing_url'] as String?,
        connections: [
          for (final row in json['connections'] as List? ?? const [])
            if (row is Map<String, dynamic>) WorkspaceLink.fromJson(row),
        ],
      );
}

/// A state a person can act on: a word, a sentence, and whether it is trouble.
///
/// ── Why this is not a coloured dot ──────────────────────────────
///
/// Because a colour is not information to somebody who cannot see it, and
/// because "amber" does not say whether to go and change a password or wait for
/// somebody to sign in. Every state below carries the WORD — which is what a
/// screen reader reads and what a test can assert — and, wherever the server
/// has one, the sentence that says what happened and what to do about it.
@immutable
class MailboxState {
  const MailboxState({
    required this.word,
    required this.sentence,
    required this.trouble,
  });

  /// "Connected", "Waiting to be connected", "Not working".
  final String word;

  /// The server's own explanation, or null when the state needs none.
  final String? sentence;

  /// Something the reader has to notice rather than merely know.
  final bool trouble;
}

/// What state that connection is in, in words.
///
/// The order of the branches is the order of severity, and it matters: a broken
/// mailbox that ALSO has no reader behind it should be reported as broken,
/// because that is the fact somebody has to act on first.
MailboxState stateOf(MailboxConnection mailbox) {
  if (mailbox.status == statusBroken) {
    return MailboxState(
      word: 'Not working',
      // The server's sentence is the point of this whole row. The fallback
      // exists only for a server that marked a mailbox broken without saying
      // why, and it says exactly that rather than guessing at a cause.
      sentence:
          mailbox.statusReason ??
          'This address stopped answering and EzBizi was not told why. '
              'Connecting it again is the quickest way to find out.',
      trouble: true,
    );
  }

  if (mailbox.status == statusUnconfigured) {
    return MailboxState(
      word: 'Waiting to be connected',
      sentence:
          mailbox.unavailableReason ??
          'This address has been added but nobody has handed over the key to '
              'it yet, so EzBizi cannot read anything in it.',
      trouble: false,
    );
  }

  // Recorded as connected, and the reader behind it says otherwise. Not an
  // error and not a working inbox either: the commonest cause is a service
  // this installation has nothing set up for, which is at our end.
  if (!mailbox.canBeOpened) {
    return MailboxState(
      word: 'Connected, but cannot be opened',
      sentence:
          mailbox.unavailableReason ??
          'EzBizi holds a key for this address but cannot use it on this '
              'installation.',
      trouble: true,
    );
  }

  return const MailboxState(
    word: 'Connected',
    sentence: null,
    trouble: false,
  );
}

/// The card's pass/fail line (Jose 11-12): Receive and Send, each ✓, ✗ with
/// the reason, or still being checked. Null for an inbox not connected yet,
/// whose state word already says so.
({String receive, String send})? healthOf(
  MailboxConnection mailbox,
) {
  if (mailbox.status == statusUnconfigured) return null;

  final receive = switch (mailbox) {
    // The reason is the card's own sentence, printed once, beside it.
    _ when mailbox.status == statusBroken || !mailbox.canBeOpened =>
      'Receive ✗',
    _ when mailbox.lastReachedKnown && mailbox.lastReachedAt == null =>
      'Receive: checking…',
    _ => 'Receive ✓',
  };
  final send = switch (mailbox) {
    _ when mailbox.sendCheckedAt == null => 'Send: checking…',
    _ when mailbox.sendProblem != null => 'Send ✗ ${mailbox.sendProblem}',
    _ => 'Send ✓',
  };

  return (receive: receive, send: send);
}

/// "Last reached 20 minutes ago · 2026-08-13 14:02", or null to draw no line.
///
/// ── Both halves, and neither on its own ─────────────────────────
///
/// The relative half is the one that answers the question somebody opens this
/// tab with: "did it break just now, or has it been dead since Tuesday?" The
/// exact half is there because core/timestamps.dart is right that a wrong
/// "2 hours ago" is worse than a stamp — a clock that is out, or a page left
/// open since lunchtime, makes the relative phrase quietly false, and the stamp
/// beside it is what lets a reader catch that.
///
/// The stamp goes through [formatStamp] so it is written the way this
/// organization writes dates, not the way this file would have written them.
///
/// Null when the server did not answer the question at all — see the library
/// note on why absent must not be drawn as "never".
String? lastReachedSentence(
  MailboxConnection mailbox, {
  required DisplayFormats formats,
  DateTime? now,
}) {
  if (!mailbox.lastReachedKnown) return null;

  final iso = mailbox.lastReachedAt;

  if (iso == null) {
    return 'This address has not answered EzBizi yet.';
  }

  final at = DateTime.tryParse(iso)?.toLocal();

  if (at == null) return null;

  final stamp = formatStamp(iso, '', formats: formats);
  final ago = (now ?? DateTime.now()).difference(at);

  return 'Last reached ${_ago(ago)} · $stamp';
}

/// "just now", "20 minutes ago", "3 hours ago", "5 days ago".
///
/// A future stamp — a server clock ahead of this browser's — reads as "just
/// now" rather than as a negative count, because [Duration.inMinutes] truncates
/// towards zero and the sentence beside it carries the exact time anyway.
String _ago(Duration ago) {
  if (ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return _count(ago.inMinutes, 'minute');
  if (ago.inHours < 24) return _count(ago.inHours, 'hour');

  return _count(ago.inDays, 'day');
}

String _count(int n, String unit) => '$n $unit${n == 1 ? '' : 's'} ago';

/// Which part of the business owns this inbox, named.
///
/// ── Why the name is worked out here rather than sent ────────────
///
/// The row carries `scope_type` and `scope_id` and no name. Every scope a
/// listed mailbox can hang off is one this person reaches — the list endpoint
/// is the union of what they may read and what they may manage, and both are
/// filtered by reach — so `/me` already holds the name, and asking for it again
/// would be a second request for something in memory.
///
/// [franchisePath] is used rather than the bare name because four companies in
/// this business operate a "Connecticut", and "Connecticut" alone names one of
/// four different inboxes.
///
/// ── What it says when it cannot name one ────────────────────────
///
/// The level, and nothing else. A blank would read as a mailbox belonging to
/// nowhere, and a guess would be worse than either — this is the exact fact the
/// two-companies-called-info@ case turns on, so the honest answer is the level
/// on its own.
String scopeLabel(Me me, MailboxConnection mailbox) {
  switch (mailbox.scopeType) {
    case 'organization':
      return mailbox.scopeId == me.organization.id
          ? me.organization.name
          : 'This organization';

    case 'company':
      final company = me.companies
          .where((c) => c.id == mailbox.scopeId)
          .firstOrNull;

      return company == null ? 'A company' : company.name;

    case 'franchise':
      final franchise = me.franchises
          .where((f) => f.id == mailbox.scopeId)
          .firstOrNull;

      // §8: an organization renames this level — "Territory", "Branch" — so the
      // fallback asks the organization rather than saying the word this file
      // happens to be written with.
      return franchise == null
          ? 'A ${me.organization.franchiseLabel.toLowerCase()}'
          : franchisePath(me, franchise);

    default:
      // A level this build has never heard of. Named as such rather than
      // guessed at: an unknown scope is not the organization.
      return 'Somewhere this version of the app does not recognise';
  }
}

/// The word for the LEVEL — "Organization", "Company", "Branch".
///
/// Shown beside [scopeLabel] so that "Everdoors" is not mistaken for a company
/// when it is the whole organization, which is the confusion two inboxes at two
/// levels of the same business produce.
String scopeLevelLabel(Me me, String scopeType) => switch (scopeType) {
  'organization' => 'Organization',
  'company' => 'Company',
  'franchise' => me.organization.franchiseLabel,
  _ => 'Level',
};
