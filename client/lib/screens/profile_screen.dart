import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../core/ui_strings.dart';
import '../widgets/api_keys_card.dart';
import '../widgets/delete_account_card.dart';
import '../widgets/appearance_card.dart';
import '../widgets/async_body.dart';
import '../widgets/identity_card.dart';
import '../widgets/tag.dart';
import '../widgets/password_card.dart';
import '../widgets/side_panel.dart';
import '../widgets/sms_codes_card.dart';
import '../widgets/two_factor_card.dart';
import '../widgets/account_facts_card.dart';
import '../core/phone_numbers.dart';
import '../widgets/user_avatar.dart';
import '../widgets/work_schedule_card.dart';
import '../widgets/work_schedule_panel.dart';

/// Your own account (plan §2.1 category 6).
///
/// Everything here belongs to the PERSON. Settings, next door, belongs to the
/// organization and is set by an administrator. They were one screen, which
/// put somebody's dark-mode choice directly above the organization's job
/// statuses and implied that whoever could change one could change the other.
/// §2.1 says the categories must not be merged; the shape of the app should say
/// so before anybody reads a word.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sessions = [];

  /// How many API keys there are, for the phone's row; null when the count
  /// could not be read, which shows no value rather than a wrong one.
  int? _keyCount;

  /// The same body IdentityCard loads, held here as well.
  ///
  /// Only the phone layout needs it: its whole argument is that each row shows
  /// its CURRENT VALUE, so most visits end without opening anything -- you came
  /// to check your number was right and it is right there. A row that cannot
  /// show its value is just a link, and the layout stops being worth its
  /// height. IdentityCard keeps its own fetch: it owns the editing and the
  /// save, and threading that through here would put a form's state in the
  /// screen that merely lists it.
  Map<String, dynamic> _profile = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. This
      // fetch is just the sessions list, but AsyncBody wraps the whole account
      // page, so blanking on refresh took the identity card, two-factor and the
      // password form down with it — and signing out other devices is exactly
      // when somebody is looking at the rest of the page. There is always at
      // least one session, the one reading this, so empty means the first load.
      _loading = _sessions.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/me/sessions');
    final profile = await widget.api.get('/me/profile');
    final keys = await widget.api.get('/me/api-keys');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _sessions = response.ok
          ? (response.body['sessions'] as List<dynamic>)
                .cast<Map<String, dynamic>>()
          : [];
      // Not an error if this half fails. The rows fall back to naming the
      // field without its value, which is the layout one notch worse rather
      // than a screen that will not open.
      _profile = profile.ok ? profile.body : _profile;
      _keyCount = keys.ok ? (keys.body['api_keys'] as List?)?.length : null;
    });
  }

  Future<void> _revokeOthers() async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (c) => SidePanel(
        title: 'Sign out everywhere else?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(c).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(c).pop(true),
            child: const Text('Sign them out'),
          ),
        ],
        child: const Text(
          'Every other browser and device is signed out immediately. This one '
          'stays signed in.',
        ),
      ),
    );

    if (confirmed != true) return;

    final response = await widget.api.delete('/me/sessions');

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          response.ok
              ? 'Signed out of ${response.body['revoked']} other session(s).'
              : response.message,
        ),
      ),
    );

    if (response.ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          // A phone is not this screen narrowed.
          //
          // On a desktop the question is "show me everything about my account",
          // and a form answers it. On a phone it is "what do you want to
          // change?", and a form makes you scroll past nine fields you did not
          // come for. So under 600 this is a different design rather than the
          // same one at a smaller width: rows that each open one screen and
          // each show what they currently say.
          if (constraints.maxWidth < 600) return _rows(context);
          return _form(context, wide: constraints.maxWidth >= 1000);
        },
      ),
    );
  }

  // --------------------------------------------------------------- desktop

  /// The form, with the facts beside it when there is width for them.
  ///
  /// Below 1000 those two columns leave the form about 600px and the facts
  /// about 300, and the form is the half doing the work — so it takes the
  /// width back and the facts fall in underneath it.
  Widget _form(BuildContext context, {required bool wide}) {
    final identity = IdentityCard(api: widget.api, session: widget.session);
    final facts = AccountFactsCard(session: widget.session, dense: wide);

    final rest = <Widget>[
      const SizedBox(height: 12),
      AppearanceCard(session: widget.session),
      const SizedBox(height: 12),
      TwoFactorCard(api: widget.api, session: widget.session),
      const SizedBox(height: 12),

      // Beside the authenticator in BOTH layouts.
      //
      // It went into _rows() alone when it was written, so it existed on a
      // phone and did not exist on a desk — which is where the people who
      // would switch it on actually sit. Nothing failed: the narrow tests
      // passed, the card rendered perfectly in the layout it was in, and the
      // desktop simply had no such control. Found by going to look at it on a
      // real screen, not by a test.
      SmsCodesCard(api: widget.api, session: widget.session),
      const SizedBox(height: 12),
      PasswordCard(api: widget.api, session: widget.session),
      const SizedBox(height: 12),
      _scheduleCard(),
      const SizedBox(height: 12),
      _sessionsCard(),
      const SizedBox(height: 12),
      // Under the sign-ins (UI queue 62): a key is another way in as you.
      _apiKeysCard(),
      const SizedBox(height: 16),
      DeleteAccountCard(api: widget.api, session: widget.session),
    ];

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        // The title and its line are the page header now (UI queue 99).
        if (wide)
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [identity, ...rest],
                ),
              ),
              const SizedBox(width: 16),
              SizedBox(width: 300, child: facts),
            ],
          )
        else ...[
          identity,
          const SizedBox(height: 12),
          facts,
          ...rest,
        ],
      ],
    );
  }

  // ----------------------------------------------------------------- phone

  Widget _rows(BuildContext context) {
    final me = widget.session.me;

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        if (me != null) _hero(context, me),

        _group(context, 'You', [
          _row(context, Icons.person_outline, 'Name', me?.name ?? '', () {
            _open(context, 'Who you are', _identity());
          }),
          _row(
            context,
            Icons.phone_outlined,
            'Phone number',
            // Formatted here, raw in the field this opens. The row is being
            // READ — checking a number you already know is the commonest
            // reason to come here at all — and +13057242199 is not something
            // anybody reads back.
            formatPhoneNumber(_profile['phone'] as String? ?? ''),
            () => _open(context, 'Who you are', _identity()),
          ),
          _row(
            context,
            Icons.home_outlined,
            'Home address',
            _profile['address'] as String? ?? '',
            () => _open(context, 'Who you are', _identity()),
          ),
          _row(
            context,
            Icons.cake_outlined,
            'Gender and date of birth',
            [
              _profile['gender'] as String? ?? '',
              _profile['date_of_birth'] as String? ?? '',
            ].where((v) => v.isNotEmpty).join(' · '),
            () => _open(context, 'Who you are', _identity()),
          ),
        ]),

        _group(context, 'Signing in', [
          _row(context, Icons.password_outlined, 'Password', null, () {
            _open(
              context,
              'Password',
              PasswordCard(api: widget.api, session: widget.session),
            );
          }),
          _row(context, Icons.shield_outlined, 'Two-factor', null, () {
            _open(
              context,
              'Two-factor',
              TwoFactorCard(api: widget.api, session: widget.session),
            );
          }),

          // Beside the authenticator because it answers the same question -
          // how do we know it is you - and not under Communication, which is
          // the tenant's phone system. This is EzBizi texting the person.
          _row(context, Icons.sms_outlined, 'Text me my codes', null, () {
            _open(
              context,
              'Text me my codes',
              SmsCodesCard(api: widget.api, session: widget.session),
            );
          }),
        ]),

        _group(context, 'Appearance', [
          _row(context, Icons.palette_outlined, 'Theme and sidebar', null, () {
            _open(
              context,
              'Appearance',
              AppearanceCard(session: widget.session),
            );
          }),
        ]),

        _group(context, 'Assigned to you', [
          _row(
            context,
            Icons.badge_outlined,
            'Role and access',
            me?.roleName ?? '',
            () => _open(
              context,
              'Role and access',
              AccountFactsCard(session: widget.session, dense: true),
            ),
          ),
          _row(context, Icons.mail_outline, 'Email', me?.email ?? '', () {
            _open(
              context,
              'Role and access',
              AccountFactsCard(session: widget.session, dense: true),
            );
          }),
        ]),

        _group(context, UiStrings.t('time_clock.work_schedule'), [
          _row(
            context,
            Icons.schedule_outlined,
            UiStrings.t('time_clock.work_schedule'),
            _scheduleSummary(),
            () => _open(
              context,
              UiStrings.t('time_clock.work_schedule'),
              _scheduleCard(),
            ),
          ),
        ]),

        _group(context, 'Signed in', [
          _row(
            context,
            Icons.devices_outlined,
            'Where you are signed in',
            '${_sessions.length}',
            () => _open(context, 'Where you are signed in', _sessionsCard()),
          ),
          _row(
            context,
            Icons.key_outlined,
            'API keys',
            _keyCount == null ? null : '$_keyCount',
            () => _open(context, 'API keys', _apiKeysCard()),
          ),
        ]),

        Padding(
          padding: const EdgeInsets.all(16),
          child: DeleteAccountCard(api: widget.api, session: widget.session),
        ),
      ],
    );
  }

  /// A fresh one per screen, like the identity card: it keeps the key it
  /// just made in its own state.
  ApiKeysCard _apiKeysCard() => ApiKeysCard(
    api: widget.api,
    formats: widget.session.me?.display ?? DisplayFormats.fallback,
  );

  /// A fresh one per screen: the form owns its own controllers, so two of
  /// them must never be the same instance.
  IdentityCard _identity() =>
      IdentityCard(api: widget.api, session: widget.session);

  // ------------------------------------------------------- work schedule

  Map<String, dynamic>? get _schedule => _profile['work_schedule'] is Map
      ? Map<String, dynamic>.from(_profile['work_schedule'] as Map)
      : null;

  Widget _scheduleCard() => WorkScheduleCard(
    schedule: _schedule,
    canEdit: _profile['can_edit_schedule'] == true,
    onEdit: _editSchedule,
  );

  /// Today's hours for the phone row, read without opening it.
  ///
  /// It used to list the working days — "Mon Tue Wed Thu Fri" — which answers
  /// "which days do I work". On a phone the question is "what am I doing
  /// today", and the row was the one place that could answer it before a tap.
  /// Jose, 2026-09-09.
  ///
  /// Three outcomes, and the third is the one that used to lie: working today,
  /// off today, and nobody has written a schedule at all. The last two both
  /// rendered as "Day off", which are opposite facts — one is the schedule
  /// answering, the other is the absence of one.
  String _scheduleSummary() {
    final days = _schedule?['days'];
    if (days is! Map) return UiStrings.t('time_clock.schedule_not_set');

    // `weekday` is 1..7 from Monday, which is the order `days` is written in,
    // so the -1 is an index rather than an assumption about locale.
    final window = days[WorkScheduleCard.days[DateTime.now().weekday - 1]];
    if (window is! Map) return UiStrings.t('time_clock.off_today');

    return UiStrings.t('time_clock.today_hours')
        .replaceFirst('{start}', '${window['start'] ?? ''}')
        .replaceFirst('{end}', '${window['end'] ?? ''}');
  }

  /// Only offered when the organization allows it (design 2026-09-08 §3.3);
  /// the server refuses otherwise, so the button is never a lie.
  Future<void> _editSchedule() async {
    final days = _schedule?['days'];

    final saved = await showWorkSchedulePanel(
      context,
      title: UiStrings.t('time_clock.work_schedule'),
      days: days is Map ? Map<String, dynamic>.from(days) : null,
      formats: widget.session.me?.display ?? DisplayFormats.fallback,
    );
    if (saved == null || !mounted) return;

    final response = await widget.api.patch('/me/schedule', {'days': saved});
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    final body = response.body;
    setState(() => _profile['work_schedule'] = body['work_schedule']);
  }

  /// Who you are looking at, before the rows begin.
  ///
  /// It spends about 150px of a phone screen saying something you already
  /// knew, and it earns them here: a staff account and an admin account are
  /// identical from the rows down, and changing the wrong one is silent.
  Widget _hero(BuildContext context, Me me) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 4),
      child: Column(
        children: [
          UserAvatar(
            api: widget.api,
            userId: me.id,
            name: me.name,
            radius: 34,
            hasAvatar: me.hasAvatar,
          ),
          const SizedBox(height: 10),
          Text(me.name, style: theme.textTheme.titleMedium),
          const SizedBox(height: 2),
          Text(
            [
              if (me.roleName != null) me.roleName!,
              me.organization.name,
            ].join(' · '),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }

  Widget _group(BuildContext context, String title, List<Widget> rows) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
          child: Text(
            title.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              letterSpacing: 0.6,
            ),
          ),
        ),
        Card(
          margin: const EdgeInsets.symmetric(horizontal: 12),
          child: Column(children: rows),
        ),
      ],
    );
  }

  /// [value] null is a row that opens something rather than holding a fact:
  /// Password, Two-factor and Theme printed "Not given", which read as "you
  /// have no password" to somebody who had just signed in with one.
  Widget _row(
    BuildContext context,
    IconData icon,
    String label,
    String? value,
    VoidCallback onTap,
  ) {
    final theme = Theme.of(context);
    return ListTile(
      leading: Icon(icon, size: 20),
      title: Text(label, style: theme.textTheme.bodyMedium),
      // The value, not a second copy of the label — showing it is the whole
      // reason this layout is worth its height, because most visits end
      // without opening anything. An empty one says so rather than sitting
      // blank: a blank is indistinguishable from a row that failed to load.
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (value != null)
            Flexible(
              child: Text(
                value.isEmpty ? 'Not given' : value,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.end,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          const Icon(Icons.chevron_right, size: 18),
        ],
      ),
      onTap: onTap,
    );
  }

  /// One row's screen: a back bar, and the card that already does the work.
  ///
  /// Every card here saves itself, so nothing needs a Save of its own — and
  /// reloading on the way back is what keeps the row's value honest.
  Future<void> _open(BuildContext context, String title, Widget body) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => Scaffold(
          appBar: AppBar(title: Text(title)),
          body: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: body,
          ),
        ),
      ),
    );
    if (mounted) _load();
  }

  Widget _sessionsCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Where you are signed in',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            Text(
              // The honest description. A token list is not a device list, and
              // calling it one would have people looking for a phone model.
              'One entry per sign-in. A browser you closed without signing out '
              'is still here until it expires.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final s in _sessions)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // The platform, drawn. Five rows reading "web" told nobody
                    // which one to sign out, which left "everywhere else" as
                    // the only usable control.
                    Icon(_deviceIcon(s['kind'] as String?), size: 20),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Flexible(
                                child: Text(
                                  s['label'] as String? ?? 'Unknown device',
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if (s['current'] == true) ...[
                                const SizedBox(width: 6),
                                const Tag('This one'),
                              ],
                            ],
                          ),
                          Text(
                            [
                              if (s['ip'] != null) s['ip'] as String,
                              _lastUsed(s['last_used_at'] as String?),
                            ].join(' · '),
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _sessions.length < 2 ? null : _revokeOthers,
              icon: const Icon(Icons.logout),
              label: const Text('Sign out everywhere else'),
            ),
          ],
        ),
      ),
    );
  }

  /// Apple, Windows, phone, or an honest shrug.
  ///
  /// A confidently wrong icon is worse than a generic one: somebody scanning
  /// for "the Mac I left at the office" and seeing a Windows logo will not
  /// revoke it.
  IconData _deviceIcon(String? kind) => switch (kind) {
    'mobile' => Icons.smartphone,
    'apple' => Icons.laptop_mac,
    'windows' => Icons.laptop_windows,
    'desktop' => Icons.desktop_windows_outlined,
    _ => Icons.device_unknown_outlined,
  };

  String _lastUsed(String? iso) {
    if (iso == null) return 'Never used';

    final at = DateTime.tryParse(iso)?.toLocal();

    if (at == null) return '';

    final ago = DateTime.now().difference(at);

    if (ago.inMinutes < 1) return 'Just now';
    if (ago.inHours < 1) return '${ago.inMinutes} min ago';
    if (ago.inDays < 1) return '${ago.inHours} h ago';

    return '${ago.inDays} d ago';
  }
}
