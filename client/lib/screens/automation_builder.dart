import 'dart:async';
import 'dart:convert';

import '../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/automation_catalogue.dart';
import '../core/branch_options.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/catalogue_picker.dart';
import '../widgets/error_note.dart';
import '../widgets/numbered_step.dart';
import '../widgets/scope_choice_field.dart';
import '../widgets/search_picker.dart';
import '../widgets/tag.dart';
import 'hr/work_hours_reports_tab.dart';

/// Building one automation, on the page rather than in a panel (plan §5.5).
///
/// ── Why this stopped being a side panel ──────────────────────────
///
/// A side panel is the right shape for a form you fill in beside the thing it
/// is about — editing a customer next to the list of customers. Writing a rule
/// is not that. There is no list behind it worth keeping in view, and the form
/// itself is four decisions deep: what it is called, what it reacts to, when it
/// should hold back, and what it then does. Squeezed into 560px, the trigger
/// became a dropdown of twenty raw event keys with nothing to say what any of
/// them meant, which is the state the owner objected to.
///
/// So it is a tab on the main screen with the whole width to work in, and the
/// four decisions are four numbered steps in the order somebody makes them.
///
/// ── Simple mode, and what it refuses to do ───────────────────────
///
/// The engine takes a nested AND/OR tree. This edits the flat "all of these"
/// case, which is what almost every real rule is, and sends it as
/// `{"all": [...]}` so the stored shape is already the full one.
///
/// A rule whose stored tree is NESTED can still be opened here — its name and
/// its actions are editable — but its conditions are shown as they are and sent
/// back untouched. Flattening a tree this form cannot draw would quietly change
/// what the rule means, and re-saving is exactly when nobody is watching for
/// that.
class AutomationBuilder extends StatefulWidget {
  const AutomationBuilder({
    super.key,
    required this.api,
    required this.catalogue,
    required this.formats,
    required this.onSaved,
    required this.onCancel,
    this.editing,
    this.canEditHoursReports = false,
    this.onTry,
    this.companies = const [],
    this.branches = const [],
  });

  final ApiClient api;
  final AutomationCatalogue catalogue;
  final DisplayFormats formats;

  /// `work_hours.manage`: a "Send the work hours report" step can change who
  /// the chosen report covers, with Work Hours' own editor (queue 33).
  final bool canEditHoursReports;

  /// The automation being edited, as `GET /automations/{id}` returned it.
  /// Null builds a new one.
  final Map<String, dynamic>? editing;

  final VoidCallback onSaved;
  final VoidCallback onCancel;

  /// Rehearses the SAVED rule against a recent real event, beside Save
  /// (UI queue 73). Null for a rule not saved yet: there is nothing to try.
  final VoidCallback? onTry;

  /// The person's reach, from `/me`, which names and groups the catalogue's
  /// places in "Where it applies" (UI queue 129).
  final List<CompanySummary> companies;
  final List<FranchiseSummary> branches;

  @override
  State<AutomationBuilder> createState() => _AutomationBuilderState();
}

/// One clause of "only if".
///
/// It owns its controllers rather than being redrawn from a map of strings, and
/// that is the fix for a real bug: `TextFormField(initialValue:)` inside a list
/// keeps the FIELD's state where it sits in the list, so deleting the first row
/// left the second one wearing the first one's text while the payload carried
/// the second one's. Text that follows a controller cannot do that, and the key
/// keeps focus with the row it belongs to.
class _ConditionRow {
  _ConditionRow({String field = '', this.op = 'eq', String value = ''})
    : field = TextEditingController(text: field),
      value = TextEditingController(text: value);

  final TextEditingController field;
  final TextEditingController value;
  final Key key = UniqueKey();

  String op;

  Map<String, dynamic> toJson() {
    final clause = <String, dynamic>{'field': field.text.trim(), 'op': op};

    switch (arityOf(op)) {
      case OperatorArity.none:
        // "is empty" and "changed" take no value, and sending an empty string
        // as one would store a clause that reads as a comparison against ''.
        break;
      case OperatorArity.one:
        clause['value'] = value.text;
      case OperatorArity.many:
        clause['value'] = parsedValues;
    }

    return clause;
  }

  /// The values "is one of" will actually compare against.
  ///
  /// Read by the save blocker as well as by toJson, so the check and the payload
  /// cannot disagree — checking the raw text instead is what let " , , " through
  /// as a configured-looking rule that matched nothing.
  List<String> get parsedValues => [
    for (final part in value.text.split(','))
      if (part.trim().isNotEmpty) part.trim(),
  ];

  void dispose() {
    field.dispose();
    value.dispose();
  }
}

/// One step of "then do this".
class _ActionRow {
  _ActionRow({this.catalogueKey, this.type, this.picking = true});

  final Key key = UniqueKey();

  final field = TextEditingController();
  final value = TextEditingController();
  final url = TextEditingController();
  final message = TextEditingController();

  /// WhatsApp: the chosen template and one controller per blank it declares.
  ///
  /// The COUNT comes from the server, never from counting {{n}} in the preview:
  /// Meta numbers positionally and "{{1}} owes {{3}}" is a three-parameter
  /// template with a gap. Deriving it would be right on every template anybody
  /// tested and wrong on the one with a hole in it, at send time.
  /// The recipient KIND while the box is empty, and only then.
  ///
  /// The kind is derived from the stored value so a saved rule opens on the
  /// right one with no second thing to keep in step. That works for every value
  /// except the empty one, which belongs to all three — so choosing "an address
  /// I type" cleared the box and the control snapped straight back to a person.
  /// Remembered here while it is empty and forgotten the moment it is not.
  String? recipientKind;

  /// An address being typed for a recipient LIST, before it is added.
  ///
  /// Held per row rather than in the stored value: half a typed address is not
  /// a recipient, and putting it in the list would send to it on save.
  final pendingAddress = TextEditingController();

  String whatsAppFrom = '';
  String whatsAppTemplate = '';
  int whatsAppParameters = 0;
  final List<TextEditingController> whatsAppValues = [];
  final Map<String, TextEditingController> parameters = {};
  final Set<String> attachedParameters = {};

  /// What was picked out of the catalogue — either a generic action type or a
  /// module's declared action. Kept beside [type] so the card can say
  /// "Customers › Update a field" while the payload says `update_field`.
  String? catalogueKey;

  /// The `type` the API accepts. Null until something is chosen.
  String? type;

  /// The picker is open on this card.
  bool picking;

  TextEditingController parameter(String key) =>
      parameters.putIfAbsent(key, TextEditingController.new);

  /// The `_` arm below throws, and it is UNREACHABLE — see [canDraw].
  ///
  /// Read on its own this method looks like it crashes on any action type the
  /// build does not know, which would make every server-side action
  /// registration a hazard. It does not: the picker consults [canDraw]
  /// first and offers an unknown type disabled, so nothing unknown ever
  /// reaches here to be serialised.
  ///
  /// Said here because two people checking whether a new automation action
  /// could break a released build both read the throw, concluded it could, and
  /// stopped — separately, within an hour, on 2026-08-28, while an App Store
  /// binary was frozen and the answer decided whether work shipped. The guard
  /// is forty lines down behind a docblock neither scrolled to. One of them
  /// then told the other to hold a finished, correct commit.
  Map<String, dynamic> toJson(CatalogueActionType? definition) {
    // Any action that DECLARES parameters is built from them, rather than
    // any action whose key happens to start with a particular prefix.
    //
    // The prefix was accurate while one module was the only one with
    // configurable actions, and it silently stopped being a description of
    // anything the moment Phone added one: "Send a message" was selectable,
    // drew no fields, and fell to the throw below — a StateError on a rule
    // somebody had just built.
    if (definition != null && definition.parameters.isNotEmpty) {
      final payload = <String, dynamic>{'type': type};
      for (final field in definition.parameters) {
        final value = parameter(field.key).text.trim();

        // A yes/no answer goes on the wire as a REAL boolean, not as the text
        // the switch happens to store it in.
        //
        // Every parameter shares one map of text controllers, so an off switch
        // is the empty string — and sending that leaves the server casting a
        // string to a bool. PHP casts '' to false and 'true' to true, which
        // looks fine and is one typo from disaster: the string 'false' casts
        // to TRUE. Typing it here means the server never has to know what the
        // control stored, and no future writer has to rediscover that rule.
        // A roles parameter is a LIST on the wire. It is held as a comma-joined
        // string only because every parameter shares one map of text
        // controllers; sending that string would hand the runner one role id
        // with commas in it, which matches nothing and tells nobody.
        // A recipient LIST is an array on the wire, like roles. Held as a
        // comma-joined string only because every parameter shares one map of
        // text controllers.
        if (field.kind == 'recipient_list') {
          payload[field.key] = [
            for (final one in value.split(','))
              if (one.trim().isNotEmpty) one.trim(),
          ];

          continue;
        }

        // Named people too: saved as one comma-joined id, the rule told nobody
        // it named (Bar's edit of "Complaint call", 2026-10-13).
        if (field.kind == 'roles' || field.kind == 'people') {
          payload[field.key] = [
            for (final id in value.split(','))
              if (id.trim().isNotEmpty) id.trim(),
          ];

          continue;
        }

        if (field.kind == 'boolean') {
          payload[field.key] = value == 'true';
          continue;
        }

        if (value.isNotEmpty) {
          payload[field.key] = value;
        } else if (field.required && field.nullable) {
          payload[field.key] = null;
        } else if (field.required) {
          payload[field.key] = '';
        }
      }
      return payload;
    }

    return switch (type) {
      'update_field' => {
        'type': 'update_field',
        'field': field.text.trim(),
        'value': value.text,
      },
      'webhook' => {'type': 'webhook', 'url': url.text.trim()},
      'whatsapp.send' => {
        'type': 'whatsapp.send',
        'from': whatsAppFrom,
        'template': whatsAppTemplate,
        'to_field': parameter('to_field').text.trim(),
        'parameters': [
          for (final c in whatsAppValues) c.text,
        ],
        // Kept when a saved step carried one, so a re-save does not reset it.
        if (parameter('language').text.trim().isNotEmpty)
          'language': parameter('language').text.trim(),
      },
      // Phone actions take no form fields — the subject of the event is enough.
      'voicemail.mark_dealt' || 'missed.create_job' => {'type': type},
      // ANY DECLARED TYPE WITH NO PARAMETERS acts on the record the rule fired
      // on and needs nothing else: switch it off, withdraw it, clock them out.
      // Without this arm those fell to the throw below, because the generic
      // branch above only fires when there is at least one parameter.
      _ when definition != null && definition.parameters.isEmpty => {
        'type': type,
      },
      _ => throw StateError(
        'No form for action type "$type" — it should not have been selectable.',
      ),
    };
  }

  /// Types that have a form written by hand rather than built from their
  /// declared parameters.
  static const Set<String> bespokeTypes = {
    'update_field',
    'webhook',
    'whatsapp.send',
    'voicemail.mark_dealt',
    'missed.create_job',
  };

  /// Every parameter kind [_parameterForm] can draw.
  static const Set<String> drawableKinds = {
    'text',
    'rich_text',
    'audience',
    'boolean',
    'work_hours_report',
    'whatsapp_group',
    'contract',
    'date',
    'field',
    'franchise',
    'people',
    'person',
    'person_or_field',
    'phone_number',
    'phone_recipient',
    'recipient',
    'recipient_list',
    'report',
    'roles',
    'training',
    'trigger_field',
  };

  /// Whether THIS BUILD can draw a form for an action type.
  ///
  /// DERIVED, not listed. This used to be a hand-kept set of seven type names,
  /// and it silently disagreed with the payload: `toJson` builds any type that
  /// DECLARES parameters from those parameters, while this refused every type
  /// nobody had remembered to add. So "Assign training", "Send a report" and
  /// "Switch it off" were built on the server, runnable, and drawn as a bare
  /// Message box or refused outright. Jose saw them as "Not built yet".
  ///
  /// What an old build genuinely cannot draw is a parameter KIND it has never
  /// heard of — that is the only honest "needs a newer version", and it is the
  /// only thing checked here. A type with no parameters needs nothing drawn.
  static bool canDraw(CatalogueActionType? type) {
    if (type == null) return false;
    if (bespokeTypes.contains(type.key)) return true;

    return type.parameters.every((p) => drawableKinds.contains(p.kind));
  }


  void dispose() {
    field.dispose();
    value.dispose();
    url.dispose();
    message.dispose();
    pendingAddress.dispose();
    for (final c in whatsAppValues) {
      c.dispose();
    }
    for (final controller in parameters.values) {
      controller.dispose();
    }
  }
}

class _AutomationBuilderState extends State<AutomationBuilder> {
  final _name = TextEditingController();
  final _description = TextEditingController();

  String? _trigger;
  final List<_ConditionRow> _conditions = [];
  final List<_ActionRow> _actions = [];

  /// A stored condition tree this form cannot draw. Held verbatim and sent back
  /// unchanged — see the class comment.
  /// Conditions this build cannot draw as rows, kept EXACTLY as stored.
  ///
  /// `Object?` and not `Map`, because the thing that made this dangerous was
  /// assuming a shape. The API types `conditions` as `['nullable','array']`, so
  /// a JSON list of clauses is storable — and a list arriving here used to be
  /// replaced with `{'all': []}`, which is not "handing it back" at all. Worse,
  /// the engine reads an empty `all` as matched (`! in_array(false, [])` is
  /// true), so opening such a rule and pressing Save turned a CONDITIONAL rule
  /// into one that fires on every event. Silently, and permanently.
  Object? _opaqueConditions;

  /// Whether anything is being held opaquely. A stored `null` is not.
  bool _hasOpaque = false;

  /// What was loaded, for the read-only view when [_lockedBecause] is set.
  List<Map<String, dynamic>> _loadedActions = [];

  /// What changing the trigger took away, in the words of what was lost.
  ///
  /// Changing what a rule reacts to changes the RECORD it is about, and with it
  /// which fields exist and which module's actions mean anything. Leaving a
  /// stale field selected would save a rule the server refuses; clearing it
  /// without a word is worse, because somebody chose it. So it is cleared and
  /// said out loud, here, until they dismiss it.
  String? _triggerNotice;

  // ── the schedule, for the one trigger that is a clock ────────────
  //
  // Held as separate fields rather than a map so the form can be half-filled
  // without meaning anything yet: somebody who picks "days of the week" and has
  // not ticked a day has an incomplete schedule, not an invalid one, and only
  // becomes a save error when they try to save.
  String _scheduleKind = 'daily';
  final TextEditingController _scheduleTime = TextEditingController(text: '09:00');
  final Set<String> _scheduleDays = <String>{};
  int _scheduleDayOfMonth = 1;
  String? _scheduleZone;

  /// Is the picked trigger the clock?
  ///
  /// Compared against the server's own key rather than a guess: the catalogue
  /// carries it, and a client that decided for itself which trigger is a clock
  /// would be a second definition of the same fact.
  bool get _isScheduled => _trigger == _clockTrigger;

  static const _clockTrigger = 'automation.schedule_reached';

  /// Why the version's contents — conditions and actions — cannot be edited.
  ///
  /// Only one thing causes it today: an action carrying a webhook signing
  /// secret. The API masks secrets on the way out (rightly), so saving the form
  /// back would write eight bullet characters over a live secret and every
  /// signed call the receiver checks would start failing. Name and description
  /// are still editable, because changing those alone writes no new version.
  String? _lockedBecause;

  bool _busy = false;

  /// A template waiting for "Use the template", when applying it would
  /// clear work already on the form. Shown inline under the picker: the
  /// house has no modal dialogs.
  Map<String, dynamic>? _pendingTemplate;
  String? _error;

  // ── the five steps (UI queue 113) ────────────────────────────────

  /// Which step is on screen: 0 Name, 1 When, 2 Only if, 3 Then, 4 Review.
  int _stepIndex = 0;

  /// Steps somebody has moved on from, so an Only if left empty on purpose
  /// reads as "none" rather than as not looked at yet.
  final Set<int> _left = <int>{};

  /// Which of the rule's steps the Then screen has open.
  int _selectedAction = 0;

  /// The box that takes `{{ }}` the cursor was last in, for the details beside
  /// the Then step. Checked against the open step's own boxes before use, so a
  /// removed step's controller is never written to.
  TextEditingController? _activeBox;
  final Set<TextEditingController> _watchedBoxes = <TextEditingController>{};

  /// Set while the Then step draws its details beside the editor, which is
  /// when the chips under each box would be the same chips twice.
  bool _detailsBeside = false;

  /// Below this width of the builder's own box the steps stack.
  static const _narrowBelow = 760.0;

  static const _stepLabels = ['Name', 'When', 'Only if', 'Then', 'Review'];
  static const _stepKeys = ['name', 'when', 'only-if', 'then', 'review'];

  /// How often each trigger happened in the last 30 days, when the server says.
  ///
  /// `GET /automations/trigger-stats` may not exist on the server this build
  /// talks to. Any failure leaves this null and the cards say nothing — a
  /// missing count is not a zero.
  Map<String, int>? _triggerStats;

  /// What `POST /automations/would-have-run` answered for the conditions as
  /// they are now, and the request it answered. Shown only while the two
  /// match, so a count never describes conditions that have since changed.
  ({int wouldRun, int total, List<({String label, bool ran})> examples})?
  _rehearsal;
  String? _rehearsalFor;
  String? _rehearsalPending;
  Timer? _rehearsalTimer;

  /// The most events `would-have-run` reads.
  static const _rehearsalCeiling = 500;

  bool get _isEdit => widget.editing != null;
  String? get _id => widget.editing?['id'] as String?;

  /// The phone lines this company holds, for the "Send from" picker.
  ///
  /// Fetched rather than declared in the catalogue because it is tenant data,
  /// not a description of what the module can do — the catalogue says a
  /// parameter is a phone line, and which lines exist is a different question
  /// with a different answer per company.
  List<(String, String)> _lines = const [];

  /// How many lines the company holds BEFORE the messaging filter.
  ///
  /// Without it an empty picker has two causes and one sentence: no phone lines
  /// at all, and lines that exist but none approved for texting. Those send
  /// somebody to two different screens - buy a number, or get an existing one
  /// approved - so the picker has to say which.
  int _linesHeld = 0;

  /// The approved WhatsApp templates, and WHY there are none when there are none.
  ///
  /// Two different empty answers — no number attached, and nothing approved yet —
  /// and the Meta session carries the reason on both. Rendered verbatim rather
  /// than replaced with a sentence of mine: they know which it is and I do not,
  /// and "nothing approved yet" is what Jose sees today because `report_summary`
  /// is still PENDING with Meta.
  /// Approved templates GROUPED BY SENDING NUMBER.
  ///
  /// Meta's endpoint changed shape when it turned out a template is approved per
  /// WABA and our two numbers sit on different ones — so a flat list was showing
  /// one number's templates and silently hiding the other's. The client kept
  /// parsing the old flat shape, which is why the Template menu rendered a row
  /// of blank entries rather than nothing: every row had no `name`.
  List<({String from, String label, List<({String name, int parameters, String preview})> templates})>
      _whatsAppNumbers = const [];
  String? _whatsAppBecause;

  Future<void> _loadWhatsAppTemplates() async {
    final response = await widget.api.get('/automations/whatsapp-templates');
    if (!mounted) return;

    setState(() {
      // `response.data` IS the `data` value, not the envelope — so the list is
      // here and `because` is a sibling of it, read off the body.
      _whatsAppNumbers = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>)
                  (
                    from: (row['from'] ?? '').toString(),
                    label: (row['label'] ?? row['from'] ?? '').toString(),
                    templates: [
                      for (final t in (row['templates'] as List? ?? const []))
                        if (t is Map<String, dynamic>)
                          (
                            name: (t['name'] ?? '').toString(),
                            parameters: (t['parameters'] as num?)?.toInt() ?? 0,
                            preview: (t['preview'] ?? '').toString(),
                          ),
                    ],
                  ),
            ]
          : const [];
      _whatsAppBecause = response.ok
          ? (response.body['because'] as String?)
          : 'The WhatsApp templates could not be read just now.';
    });
  }

  /// Work Hours' saved reports with who each covers, for the step that sends
  /// one (queue 33, Jose's 1). The catalogue names them only; this is Work
  /// Hours' own list, so a reader without its grant is refused and the step
  /// simply shows no more than the name.
  List<Map<String, dynamic>> _hoursReports = const [];
  List<Map<String, dynamic>> _hoursRoles = const [];
  List<Map<String, dynamic>> _hoursPeople = const [];

  Future<void> _loadHoursReports() async {
    final response = await widget.api.get('/hr/time-clock/reports');
    if (!mounted || !response.ok) return;

    List<Map<String, dynamic>> list(Object? raw) => [
      for (final row in (raw as List?) ?? const [])
        if (row is Map) Map<String, dynamic>.from(row),
    ];

    setState(() {
      _hoursReports = list(response.body['data']);
      _hoursRoles = list(response.body['roles']);
      _hoursPeople = list(response.body['people']);
    });
  }

  /// Who the chosen report covers, under the step that sends it, and the
  /// editor that changes it. A step picks a report; the report decides who is
  /// in it, so a change here is the report's everywhere it is sent.
  Widget _hoursCovers(BuildContext context, String reportId) {
    final report = _hoursReports
        .where((r) => '${r['id']}' == reportId)
        .firstOrNull;
    if (report == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Container(
      key: const ValueKey('step-hours-covers'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text.rich(
            TextSpan(
              children: [
                const TextSpan(
                  text: 'Covers ',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                TextSpan(
                  text: workHoursReportCovers(
                    report,
                    roles: _hoursRoles,
                    people: _hoursPeople,
                  ),
                ),
              ],
            ),
          ),
          if (widget.canEditHoursReports) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 10,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                OutlinedButton(
                  key: const ValueKey('step-hours-edit'),
                  onPressed: _busy
                      ? null
                      : () async {
                          final saved = await editWorkHoursReport(
                            context,
                            api: widget.api,
                            report: report,
                            roles: _hoursRoles,
                            people: _hoursPeople,
                          );
                          if (saved && mounted) _loadHoursReports();
                        },
                  child: const Text('Edit who is in it'),
                ),
                Text('Changes the report everywhere it is sent.', style: muted),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _loadPhoneLines() async {
    final response = await widget.api.get('/phone/numbers');
    if (!mounted || !response.ok) return;

    final rows = (response.data as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    setState(() {
      _linesHeld = rows.length;
      _lines = [
        for (final row in rows)
          // ONLY LINES THAT MAY SEND A TEXT. Twilio approves outbound SMS per
          // number, and a line without it accepts the rule, runs, and is refused
          // by the carrier at send time - a failure that arrives in the run log
          // long after the rule was written and reads as a Twilio problem rather
          // than as a choice made in this picker. `sms_outbound_allowed` is what
          // the Phone module already tracks for exactly this question.
          if (row['sms_outbound_allowed'] == true)
            (
              (row['id'] ?? '').toString(),
              [
                (row['label'] ?? '').toString(),
                formatPhoneNumber((row['e164'] ?? '').toString()),
              ].where((part) => part.isNotEmpty).join(' · '),
            ),
      ];
    });
  }

  /// The field names a condition on [_trigger] can actually read.
  ///
  /// Fetched, not declared, and for the same reason as [_lines]: this is what
  /// events of this kind have actually carried in THIS organization, which the
  /// catalogue cannot know. The server reads them the way the evaluator
  /// resolves them, so anything offered here is something a condition can read.
  ///
  /// Empty means one of two things and [_fieldsSampled] tells them apart: the
  /// trigger has never fired here, or it is still loading. Neither is "this
  /// event has no fields", and the form must not say so.
  List<String> _triggerFields = const [];
  int? _fieldsSampled;

  /// Words a message can drop in that the event itself does not carry, such
  /// as `company_name` on a lead. Chips only: a condition reads the event, so
  /// these never reach the "only if" dropdown.
  List<String> _messageFields = const [];

  /// Everything a `{{ }}` box can use: the event's fields, then the extras.
  List<String> get _insertableFields => [
    ..._triggerFields,
    for (final field in _messageFields)
      if (!_triggerFields.contains(field)) field,
  ];

  /// Value => label for fields whose set is fixed in code, keyed by field.
  ///
  /// A field with no entry keeps its text box: a dropdown over an open set
  /// would be a shorter list pretending to be the whole one.
  Map<String, List<({String value, String label})>> _fieldValues = const {};

  /// One real example per field, for the preview under a message box.
  ///
  /// Only holds fields whose record this person reaches — the server decides
  /// that, and a field can be offered with no example beside it. So an absent
  /// entry means "not yours to see", never "this field does not exist".
  Map<String, String> _fieldSamples = const {};

  /// Where this rule applies, in the stored shape the catalogue hands out:
  /// `organization`, `company:<id>` or `franchise:<id>`.
  ///
  /// Null on a NEW rule until somebody chooses, and null means the field is not
  /// sent — which is what lets the server scope the rule to the view it was
  /// written in. Defaulting to `organization` here would put every rule back to
  /// applying across the whole business, this time explicitly.
  String? _place;

  Future<void> _loadTriggerFields() async {
    final key = _trigger;

    if (key == null) {
      setState(() {
        _triggerFields = const [];
        _messageFields = const [];
        _fieldsSampled = null;
      });

      return;
    }

    final response = await widget.api.get(
      '/automations/trigger-fields?trigger=${Uri.encodeQueryComponent(key)}',
    );

    // A trigger whose tab this person cannot open answers 403, and a rule they
    // are editing may well be on one. Leaving the last trigger's fields on
    // screen would offer fields that belong to a different event, so they go.
    if (!mounted || _trigger != key) return;

    setState(() {
      _triggerFields = response.ok
          ? [
              for (final field in (response.data?['fields'] as List? ?? const []))
                field.toString(),
            ]
          : const [];
      _messageFields = response.ok
          ? [
              for (final field
                  in (response.data?['message_fields'] as List? ?? const []))
                field.toString(),
            ]
          : const [];
      _fieldsSampled = response.ok
          ? (response.data?['sampled'] as num?)?.toInt() ?? 0
          : null;
      _fieldValues = response.ok
          ? {
              for (final entry
                  in (response.data?['values'] as Map? ?? const {}).entries)
                entry.key.toString(): [
                  for (final option in (entry.value as List? ?? const []))
                    (
                      value: (option['value'] ?? '').toString(),
                      label: (option['label'] ?? '').toString(),
                    ),
                ],
            }
          : const {};
      _fieldSamples = response.ok
          ? {
              for (final entry
                  in (response.data?['samples'] as Map? ?? const {}).entries)
                entry.key.toString(): entry.value.toString(),
            }
          : const {};
    });
  }

  @override
  void initState() {
    super.initState();

    _name.addListener(_changed);
    _description.addListener(_changed);
    _loadPhoneLines();
    _loadWhatsAppTemplates();
    _loadHoursReports();

    final editing = widget.editing;

    // An existing rule opens on Review, so the whole of it is read first; a
    // new one starts at its name.
    _stepIndex = editing == null ? 0 : 4;
    if (editing == null) _loadTriggerStats();

    if (editing != null) {
      _name.text = editing['name'] as String? ?? '';
      _description.text = editing['description'] as String? ?? '';
      _trigger = editing['trigger_key'] as String?;
      // AN EXISTING RULE ALWAYS RESTATES ITS SCOPE, "everywhere" included.
      // There is no view to fall back on when saving one, so silence would let
      // a save re-scope a rule to wherever the person happened to be standing.
      final scope = editing['scope'] as Map?;
      _place = scope == null || scope['type'] == null
          ? 'organization'
          : '${scope['type']}:${scope['id']}';
      _adoptActions(editing['actions']);
      _adoptSchedule(editing['schedule']);
      _adoptConditions(editing['conditions']);
      _loadTriggerFields();
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _scheduleTime.dispose();
    _rehearsalTimer?.cancel();

    for (final condition in _conditions) {
      condition.dispose();
    }

    for (final action in _actions) {
      action.dispose();
    }

    super.dispose();
  }

  /// Every keystroke re-asks whether the form can be saved.
  ///
  /// It has to: the Create button used to be computed once from `_name.text`
  /// with nothing listening, so typing the name LAST left the button dead until
  /// something else forced a rebuild. A blocked button that never explains
  /// itself is worse than no button.
  void _changed() {
    if (mounted) setState(() {});
  }

  // ── loading an existing rule back in ─────────────────────────────

  void _adoptActions(Object? stored) {
    final actions = stored is List
        ? stored.whereType<Map>().map(Map<String, dynamic>.from).toList()
        : <Map<String, dynamic>>[];

    _loadedActions = actions;

    for (final action in actions) {
      if (action.containsKey('secret')) {
        _lockedBecause =
            'One of these calls a webhook with a signing secret. The API masks '
            'secrets when it hands an automation back, so saving these steps '
            'again would replace the real secret with the mask and every signed '
            'call would start being rejected. Name and description can still be '
            'changed here.';
        return;
      }
    }

    for (final action in actions) {
      final type = action['type'];

      if (type is! String ||
          !widget.catalogue.actionTypes.any((t) => t.key == type)) {
        _lockedBecause =
            'One of these steps is an action this version of the app does not '
            'know how to show. Re-saving would drop what it does, so the steps '
            'are read-only until the app catches up.';
        return;
      }
    }

    for (final action in actions) {
      final row = _ActionRow(
        catalogueKey: action['type'] as String,
        type: action['type'] as String,
        picking: false,
      );

      row.field.text = action['field'] as String? ?? '';
      row.value.text = '${action['value'] ?? ''}';
      row.url.text = action['url'] as String? ?? '';
      // `message` is no longer excluded: notify declares it as a parameter now,
      // so a rule SAVED BEFORE THIS CHANGE - whose action is still
      // {type: notify, message: "..."} on the wire - has to load into the
      // parameter controller or editing an existing rule silently empties the
      // box and saves it blank. The shape on the wire did not change, only
      // which controller reads it.
      for (final entry in action.entries) {
        if (entry.key != 'type' &&
            !const {'field', 'value', 'url'}.contains(entry.key)) {
          // A LIST IS JOINED, NOT PRINTED. Every list parameter — roles, named
          // people, template blanks, report recipients — is held as one
          // comma-joined string because all parameters share one map of text
          // controllers, and `'$value'` on a List gives Dart's own "[a, b]".
          //
          // Reading that back produced ONE id with brackets in it, and saving
          // wrapped it again: Jose's live rule went [] -> ["[]"] -> ["[[]]"] ->
          // ["[[[]]]"] over three edits, each save adding a layer, and then the
          // dispatcher refused a role id that had never existed. Nothing warned,
          // because a string is exactly what this line wanted.
          row.parameter(entry.key).text = switch (entry.value) {
            null => '',
            final List<dynamic> list => list.join(','),
            final other => '$other',
          };
        }
      }

      // The WhatsApp form keeps its own state, not parameter controllers, so a
      // saved step opened with no number, template or blanks and could not be
      // saved again (Jose, 2026-10-09).
      if (row.type == 'whatsapp.send') {
        final values = action['parameters'] is List
            ? action['parameters'] as List
            : const [];
        row.whatsAppFrom = '${action['from'] ?? ''}';
        row.whatsAppTemplate = '${action['template'] ?? ''}';
        row.whatsAppParameters = values.length;
        row.whatsAppValues.addAll([
          for (final v in values)
            TextEditingController(text: '${v ?? ''}')..addListener(_changed),
        ]);
      }

      _attach(row);
      _actions.add(row);
    }
  }

  /// Runs AFTER [_adoptActions], which is what may have locked the version:
  /// conditions and actions are saved as one version, so if the actions cannot
  /// be rewritten the conditions cannot either, and drawing them as editable
  /// boxes would promise a save that never happens.
  void _adoptConditions(Object? stored) {
    if (stored == null) return;

    if (stored is! Map) {
      // Held verbatim, which is what "hand it straight back" has to mean. This
      // build cannot draw it, so it does not get to reinterpret it either — a
      // shape we do not understand is still the shape the engine was given.
      _opaqueConditions = stored;
      _hasOpaque = true;
      return;
    }

    final tree = Map<String, dynamic>.from(stored);
    final flat = _lockedBecause == null ? _flatten(tree) : null;

    if (flat == null) {
      _opaqueConditions = tree;
      _hasOpaque = true;
      return;
    }

    for (final (field, op, value) in flat) {
      final row = _ConditionRow(field: field, op: op, value: value);
      _attach(row);
      _conditions.add(row);
    }
  }

  /// The clauses of a tree this form can edit, or null when it cannot.
  ///
  /// Editable means one level of "all of these", every child a plain
  /// field/operator comparison. Anything with an `any`, a `not`, or a nested
  /// group is handed back untouched instead.
  List<(String, String, String)>? _flatten(Map<String, dynamic> tree) {
    final all = tree['all'];

    final leaves = all is List
        ? all
        : (tree.containsKey('field') ? [tree] : null);

    if (leaves == null) return null;

    final clauses = <(String, String, String)>[];

    for (final leaf in leaves) {
      if (leaf is! Map) return null;

      final field = leaf['field'];
      final op = leaf['op'];

      if (field is! String || op is! String) return null;

      clauses.add((field, op, _valueText(leaf['value'])));
    }

    return clauses;
  }

  static String _valueText(Object? value) => switch (value) {
    null => '',
    final List list => list.join(', '),
    _ => '$value',
  };

  void _attach(Object row) {
    if (row is _ConditionRow) {
      row.field.addListener(_changed);
      row.value.addListener(_changed);
    } else if (row is _ActionRow) {
      row.field.addListener(_changed);
      row.value.addListener(_changed);
      row.url.addListener(_changed);
      row.message.addListener(_changed);
      _attachTaskParameters(row);
    }
  }

  void _attachTaskParameters(_ActionRow row) {
    final definition = widget.catalogue.actionType(row.type);
    if (definition == null) return;
    for (final parameter in definition.parameters) {
      if (row.attachedParameters.add(parameter.key)) {
        row.parameter(parameter.key).addListener(_changed);
      }
    }
  }

  // ── what can be offered ──────────────────────────────────────────

  /// Triggers, filed by TOP-LEVEL module rather than one category per module.
  ///
  /// Jose, 2026-09-24: *"The Marketing Tab is divided into tabs, when all of it
  /// should be together."* Newsletter, Web Management, Reviews and Business
  /// Profile are drawn inside Marketing in the app, and here they were five
  /// separate categories — so the picker was grouped by how the SERVER splits
  /// permissions rather than by where a person had just been looking.
  ///
  /// A child's rows carry its name, because once the category no longer says
  /// "Reviews", "Reply published" beside "Newsletter sent" would not say which
  /// part of Marketing it belongs to. It also keeps the child's name searchable.
  List<PickerGroup> get _triggerGroups => _filedByTopLevel(
    (module) => [
      for (final trigger in module.triggers)
        PickerItem(
          key: trigger.key,
          label: _underChild(module, trigger.label),
          description: trigger.description,
          // ENABLED stays true on purpose. A trigger declared ahead of its
          // emitter is a reasonable thing to offer — hiding it or refusing the
          // tap answers nobody's question about why an obvious event is
          // missing, and the description explains the consequence. What was
          // wrong was that the ONLY marker was the tail of a paragraph, so the
          // row looked like every other one.
          badge: trigger.isRaised ? null : 'Not raised yet',
          note: switch (_triggerStats?[trigger.key]) {
            final count? => _timesInMonth(count),
            null => null,
          },
        ),
    ],
  );

  /// One category per top-level module, children folded in, order kept.
  ///
  /// Order is first appearance, so Marketing stays where Marketing was rather
  /// than moving to wherever its last child happened to sit. A module with
  /// nothing to offer contributes no rows, and a category that ends up empty is
  /// dropped — an empty heading reads as a module that is broken.
  List<PickerGroup> _filedByTopLevel(
    List<PickerItem> Function(CatalogueModule module) itemsOf,
  ) {
    final filed = <String, (CatalogueModule, List<PickerItem>)>{};

    for (final module in widget.catalogue.modules) {
      final items = itemsOf(module);
      if (items.isEmpty) continue;

      final top = widget.catalogue.topLevelOf(module);
      filed.putIfAbsent(top.key, () => (top, <PickerItem>[])).$2.addAll(items);
    }

    return [
      for (final (top, items) in filed.values)
        PickerGroup(key: top.key, label: top.label, items: items),
    ];
  }

  /// A row's label, prefixed with its module when that module was folded into
  /// its parent's category and the heading no longer names it.
  String _underChild(CatalogueModule module, String label) =>
      widget.catalogue.topLevelOf(module).key == module.key
      ? label
      : '${module.label} · $label';

  /// The record this rule is about, decided by the trigger and nothing else.
  CatalogueSubject? get _subject => widget.catalogue.subjectOf(_trigger);

  /// The fields a step may set — the subject's, never the picked action's.
  List<CatalogueField> get _writableFields =>
      widget.catalogue.writableFieldsFor(_trigger);

  /// The generic actions first, then what each module declares.
  ///
  /// Both, because neither list alone is the truth. The generic three are the
  /// only ones the engine runs, and two of them — a webhook and a notification
  /// — belong to no module at all, so a picker built from the modules would
  /// hide the most useful things it can do. The module lists are what this
  /// system is going to be able to do, marked so nobody plans a rule around
  /// one and finds out at save time.
  ///
  /// A third reason a row can be refused now: it does not apply to THIS rule.
  /// "Customers › Update a field" was offered under a job trigger, enabled,
  /// with a description promising to set a field on the customer — and the
  /// engine wrote to the job. Marked and disabled, in the same way and for the
  /// same reason as "Not built yet": hiding it answers nobody's question about
  /// why an obvious action is missing.
  List<PickerGroup> get _actionGroups {
    final subject = _subject;
    // Only when a trigger has been chosen. Before that, "nothing is writable"
    // and "we do not know yet" look identical, and refusing on the second would
    // grey out the picker for a form nobody has filled in yet.
    final nothingWritable = subject != null && subject.writableFields.isEmpty;

    return [
      PickerGroup(
        key: '_generic',
        label: 'Works with anything',
        items: [
          for (final type in widget.catalogue.actionTypes)
            if (!type.moduleOnly) _genericItem(type, subject, nothingWritable),
        ],
      ),
      // Filed the same way as the triggers, for the same reason: Marketing's
      // actions were spread across as many categories as it has tabs.
      ..._filedByTopLevel(
        (module) => [
          for (final action in module.actions)
            // NOT LISTED rather than listed and greyed. Jose, 2026-09-24: "i
            // don't want to see any not built yet anymore". A module may still
            // DECLARE an action ahead of the engine — the coverage audit reads
            // those — but the builder offers only what will run.
            if (widget.catalogue.canUse(action))
              _moduleItem(module, action, subject),
        ],
      ),
    ];
  }

  PickerItem _genericItem(
    CatalogueActionType type,
    CatalogueSubject? subject,
    bool nothingWritable,
  ) {
    // The catalogue can name a type this build has no form for — that is the
    // point of it being server-driven — and offering one produced a card saying
    // "Create a task" that saved as a notification. Checked first, because
    // "this app is too old" is true regardless of the trigger.
    if (!_ActionRow.canDraw(type)) {
      return PickerItem(
        key: type.key,
        label: type.label,
        enabled: false,
        badge: 'Needs a newer version of the app',
      );
    }

    if (type.key == 'update_field' && nothingWritable) {
      return PickerItem(
        key: type.key,
        label: type.label,
        enabled: false,
        badge: 'Nothing to set here',
        description:
            'This rule reacts to something about ${_article(subject!.label)}, '
            'and a rule may not change ${_article(subject.label)}.',
      );
    }

    return PickerItem(key: type.key, label: type.label);
  }

  PickerItem _moduleItem(
    CatalogueModule module,
    CatalogueAction action,
    CatalogueSubject? subject,
  ) {
    // "Not built yet" wins. An action the engine cannot run is not usable for
    // any trigger, so saying it is the wrong trigger would be a smaller truth
    // in front of a larger one.
    if (!widget.catalogue.canUse(action)) {
      return PickerItem(
        key: action.key,
        label: _underChild(module, action.label),
        description: action.description,
        enabled: false,
        badge: 'Not built yet',
      );
    }

    if (widget.catalogue.moduleActionApplies(module, action.key, _trigger)) {
      return PickerItem(
        key: action.key,
        label: _underChild(module, action.label),
        description: action.description,
      );
    }

    return PickerItem(
      key: action.key,
      label: _underChild(module, action.label),
      enabled: false,
      badge: 'Not for this trigger',
      // The catalogue's own sentence, then why it cannot happen here. The
      // sentence is not wrong — it is about a customer — and that is exactly
      // what makes it misleading under a job trigger.
      description:
          '${action.description ?? ''} This rule reacts to something about '
                  '${_article(subject!.label)}, so it cannot act on '
                  '${_article(module.subject!.label)}.'
              .trim(),
    );
  }

  /// "a job", "an organization" — the record named the way a sentence needs it.
  /// "a job", "an organization" — and "a user", which is why this is not a
  /// vowel test.
  ///
  /// It was `'aeiou'.contains(word[0])`, and the users module is a real subject
  /// with three real triggers, so the picker said "This rule reacts to something
  /// about an user, and a rule may not change an user" — twice in one sentence,
  /// on a screen whose entire purpose this week was to stop the product sounding
  /// like a database.
  ///
  /// English picks the article by SOUND, not spelling: a leading "u" that says
  /// "you" takes "a" (user, unit), and a silent h or a vowel-sounding initialism
  /// takes "an" (hour, SMS). Those are the cases the subjects here can actually
  /// hit, so they are the cases handled; anything else falls back to the vowel
  /// rule, which is right far more often than not.
  static String _article(String label) {
    final word = label.toLowerCase();

    if (word.isEmpty) return word;

    // "you"-sounding u: a user, a unit, a URL.
    final saysYou =
        word.startsWith('u') &&
        !RegExp(r'^u[aeiou]').hasMatch(word) &&
        !word.startsWith('un') &&
        !word.startsWith('up');

    final silentH = word.startsWith('hour') || word.startsWith('honest');

    final needsAn = silentH || (!saysYou && 'aeiou'.contains(word[0]));

    return '${needsAn ? 'an' : 'a'} $word';
  }

  /// The `type` a picked key stores as, or null when this build cannot run it.
  ///
  /// Null covers two different "no"s and deliberately answers both the same
  /// way: the SERVER cannot run it (implemented: false), or THIS BUILD has no
  /// form for it — a type added to the catalogue after this client shipped.
  /// Either way it must not be selectable, because the only alternatives are a
  /// save the server refuses or, as it was, a save that silently means
  /// something else.
  String? _payloadTypeFor(String picked) {
    for (final type in widget.catalogue.actionTypes) {
      if (type.key == picked) {
        return _ActionRow.canDraw(type) ? type.key : null;
      }
    }

    for (final module in widget.catalogue.modules) {
      for (final action in module.actions) {
        if (action.key == picked) {
          // And a third "no": it does not apply to this rule's subject.
          //
          // Belt to the picker's braces, and unreachable today — catalogue_picker
          // sets `onTap: null` on a disabled row, so an inapplicable action
          // cannot get here. Kept because this method is what turns a KEY into a
          // stored type, and the next caller may not come from the picker; the
          // one thing it must never do is answer "yes" for something the engine
          // would refuse. An earlier version of this comment claimed the
          // trigger-change sweep read it, which was simply untrue —
          // _dropWhatNoLongerApplies does its own check and clears the row.
          return widget.catalogue.canUse(action) &&
                  widget.catalogue.moduleActionApplies(
                    module,
                    action.key,
                    _trigger,
                  )
              ? widget.catalogue.payloadTypeFor(action)
              : null;
        }
      }
    }

    return null;
  }

  /// A stored action's `type`, in the catalogue's words.
  ///
  /// The read-only card for a version that cannot be rewritten used to print the
  /// raw key — `webhook` rather than "Call a webhook" — on the one path where
  /// somebody cannot edit their way out of it. From the catalogue rather than a
  /// map in Dart, so a type added on the server reads correctly here without a
  /// client release.
  String _actionTypeLabel(String type) {
    for (final known in widget.catalogue.actionTypes) {
      if (known.key == type) return known.label;
    }

    return type;
  }

  String _actionLabel(String picked) {
    for (final type in widget.catalogue.actionTypes) {
      if (type.key == picked) return type.label;
    }

    for (final module in widget.catalogue.modules) {
      for (final action in module.actions) {
        if (action.key == picked) {
          return '${widget.catalogue.pathLabelOf(module)} › ${action.label}';
        }
      }
    }

    return picked;
  }

  // ── changing what it reacts to ───────────────────────────────────

  /// Picking a trigger, and dealing honestly with what the last one allowed.
  ///
  /// Only reachable while BUILDING: the trigger is fixed once a rule exists,
  /// because every run already recorded would otherwise describe something the
  /// rule no longer does. Which is also why this only has to cope with a form
  /// somebody is still filling in.
  void _chooseTrigger(String key) {
    if (_trigger == key) return;

    final previous = _trigger;

    setState(() {
      _trigger = key;
      _triggerNotice = _dropWhatNoLongerApplies(previous);
      // Cleared before the fetch, not after: the previous trigger's fields are
      // wrong the instant the trigger changes, and offering them for the moment
      // the request is in flight is offering fields that cannot be read.
      _triggerFields = const [];
      _messageFields = const [];
      _fieldsSampled = null;
      _fieldValues = const {};
      _fieldSamples = const {};
    });

    _loadTriggerFields();
  }

  /// Clear the steps the new subject makes impossible, and say which.
  ///
  /// Two things can go stale, and they are different. A step can be pointed at
  /// ANOTHER module's action — "Customers › Update a field" under a job trigger
  /// — and that whole choice is wrong, label and all. Or the action is fine and
  /// only the FIELD belongs to the old record: `email` on a job. The first
  /// reopens the picker; the second empties one box. Clearing more than the
  /// change requires is its own kind of rude.
  ///
  /// Returns the sentence to show, or null when nothing had to go.
  ///
  /// [previous] is the trigger being left behind, and it is needed for one
  /// reason: to name what is being cleared the way the reader saw it. They
  /// chose "Email" from a list; a sentence about `email` would be the wire key
  /// leaking back out at exactly the moment somebody is being asked to trust
  /// the form.
  String? _dropWhatNoLongerApplies(String? previous) {
    final subject = _subject;
    final writable = _writableFields;
    final before = widget.catalogue.writableFieldsFor(previous);
    final lost = <String>[];

    for (var i = 0; i < _actions.length; i++) {
      final row = _actions[i];
      final picked = row.catalogueKey;
      final actionKey = picked;
      final module = picked == null
          ? null
          : widget.catalogue.moduleOwning(picked);

      if (module != null &&
          actionKey != null &&
          !widget.catalogue.moduleActionApplies(module, actionKey, _trigger)) {
        lost.add('step ${i + 1}, “${_actionLabel(actionKey)}”');
        row.catalogueKey = null;
        row.type = null;
        row.picking = true;
        row.field.text = '';
        continue;
      }

      final field = row.field.text.trim();

      // Only when the new subject is KNOWN. An unplaceable trigger has an empty
      // field list for want of information, and clearing somebody's field on
      // the strength of that would be the silent deletion this exists to avoid.
      if (row.type == 'update_field' &&
          subject != null &&
          field.isNotEmpty &&
          !writable.any((f) => f.key == field)) {
        final named = before
            .where((f) => f.key == field)
            .map((f) => f.label)
            .firstOrNull;

        lost.add('the field “${named ?? field}” on step ${i + 1}');
        row.field.text = '';
      }
    }

    if (lost.isEmpty) return null;

    final last = lost.removeLast();
    final list = lost.isEmpty ? last : '${lost.join(', ')} and $last';

    return 'This rule is now about ${_article(subject?.label ?? 'record')}, so '
        '$list did not survive the change and ${lost.isEmpty ? 'has' : 'have'} '
        'been cleared. Everything else is as you left it — choose again below.';
  }

  // ── whether it can be saved, and why not ─────────────────────────

  /// The one thing stopping a save, in the words of whoever has to fix it.
  ///
  /// The same checks in the same order as when the form was one page — only
  /// split by the step that owns each, so Next can refuse with the sentence
  /// Save would have used.
  String? get _blocker =>
      _nameProblem ?? _whenProblem ?? _conditionsProblem ?? _actionsProblem;

  String? get _nameProblem =>
      _name.text.trim().isEmpty ? 'Give it a name first.' : null;

  String? get _whenProblem {
    if (_trigger == null) return 'Choose what it should react to.';

    // Before the lock check below, not after: a schedule is part of WHAT THE
    // RULE IS, like its trigger, and it stays editable on a rule whose version
    // is locked. Putting this after the lock would let a locked scheduled rule
    // be saved with a half-filled schedule.
    return _scheduleProblem;
  }

  String? get _conditionsProblem {
    // Nothing below is editable when the version is locked, so nothing below
    // can be wrong. Requiring an action there would leave the button dead
    // forever on the very rule whose name this screen still offers to change.
    if (_lockedBecause != null) return null;

    for (final condition in _conditions) {
      if (condition.field.text.trim().isEmpty) {
        // Left as it was, this saved a clause with no field, which the
        // evaluator refuses outright — the rule looked configured and every
        // single run failed.
        return 'One of the conditions has no field, so the rule could never run.';
      }

      if (arityOf(condition.op) != OperatorArity.none &&
          condition.value.text.trim().isEmpty) {
        return 'One of the conditions is missing the value to compare against.';
      }

      // The PARSED list, not the raw text. "is one of" splits on commas and
      // drops the blanks, so a box holding only " , , " passed the check above,
      // serialised to `value: []`, and produced a rule that saves, looks
      // configured, and can never match — `in_array($x, [])` is always false.
      // Same class as the empty-condition-row gap, one step further in.
      if (arityOf(condition.op) == OperatorArity.many &&
          condition.parsedValues.isEmpty) {
        return 'One of the conditions lists no values to match against.';
      }
    }

    return null;
  }

  String? get _actionsProblem {
    if (_lockedBecause != null) return null;

    if (_actions.isEmpty) return 'Add at least one thing for it to do.';

    for (final action in _actions) {
      if (action.type == null) return 'One of the steps has no action chosen.';

      final missing = switch (action.type) {
        'update_field' when action.field.text.trim().isEmpty =>
          'Which field should the step set?',
        // A field the subject does not have. Reachable two ways: a rule saved
        // before the field list was published, and a trigger changed under a
        // step whose choice this build could not clear. The server refuses
        // both; saying so here means nobody fills the form in first.
        //
        // Only when the subject is KNOWN. A trigger this build cannot place
        // has an empty field list for want of information, not because nothing
        // is writable, and blocking on that would strand an edit over a rule
        // that is very likely fine.
        'update_field'
            when _subject != null &&
                !_writableFields.any(
                  (f) => f.key == action.field.text.trim(),
                ) =>
          '“${action.field.text.trim()}” cannot be set on '
              '${_article(_subject?.label ?? 'record')}. Choose a field from '
              'the list.',
        'webhook' when action.url.text.trim().isEmpty =>
          'The webhook step needs an address to call.',
        'notify' when action.parameter('message').text.trim().isEmpty =>
          'The notification step needs a message.',
        'sms.send' => _missingParameter(action, 'sms.send'),
        'email.send' => _missingParameter(action, 'email.send'),
        // Refused HERE rather than by Meta. Meta catches a short parameter list
        // too — at send time, in the run log, on the rule that looked finished.
        'whatsapp.send' when action.whatsAppTemplate.isEmpty =>
          'The WhatsApp step needs a template.',
        'whatsapp.send'
            when action.parameter('to_field').text.trim().isEmpty =>
          'The WhatsApp step needs a field to take the number from.',
        'whatsapp.send'
            when action.whatsAppValues.any((c) => c.text.trim().isEmpty) =>
          'Every blank in the WhatsApp template needs a value — Meta rejects a '
              'template sent with one missing.',
        _ => null,
      };

      if (missing != null) return missing;
    }

    return null;
  }

  Object? _conditionsPayload() {
    if (_hasOpaque) return _opaqueConditions;
    if (_conditions.isEmpty) return null;

    return {
      'all': [for (final row in _conditions) row.toJson()],
    };
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final body = <String, dynamic>{
      'name': _name.text.trim(),
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      // Never on an update: changing what a rule reacts to makes it a different
      // rule, and every run already recorded would describe something it no
      // longer does. The API refuses the field outright.
      if (!_isEdit) 'trigger_key': _trigger,
      // Only when somebody chose, or when editing a rule that already has one.
      // An untouched picker on a NEW rule leaves the field out entirely and the
      // server scopes it to the view it was written in — the difference between
      // "apply this everywhere" and "nobody said".
      if (_place != null) ...{
        'scope_type': _place == 'organization' ? null : _place!.split(':').first,
        'scope_id': _place == 'organization' ? null : _place!.split(':').last,
      },
      // Sent on create AND on edit: retiming a rule is the one thing about a
      // scheduled rule that can legitimately change without it becoming a
      // different rule. The server recomputes the next run from it.
      if (_isScheduled) 'schedule': _schedulePayload(),
      if (_lockedBecause == null) ...{
        // Always sent together. A PATCH carrying actions and no conditions
        // writes a version whose conditions are NULL — the new version would
        // quietly start matching everything.
        'conditions': _conditionsPayload(),
        'actions': [
          for (final action in _actions)
            action.toJson(widget.catalogue.actionType(action.type)),
        ],
      },
    };

    final response = _isEdit
        ? await widget.api.patch('/automations/$_id', body)
        : await widget.api.post('/automations', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onSaved();
  }

  Future<void> _restore(String versionId, int number) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/automations/$_id/activate/$versionId',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text('Version $number is live again.')));

    widget.onSaved();
  }

  // ── the form ─────────────────────────────────────────────────────

  /// Five steps with a timeline across the top (UI queue 113, Jose's 1 and 4).
  ///
  /// Jose, 2026-10-20: *"Right now all the steps are in one screens, instead
  /// perhaps we could do multiple, like steps with sort of like a timeline on
  /// the top to go back and forth easily."* Each step has the width to itself;
  /// what the form asks, sends and refuses is unchanged — only where each part
  /// sits. Below [_narrowBelow] of the builder's OWN width the timeline is
  /// numbered dots and every step's panes stack.
  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < _narrowBelow;
        final gutter = narrow ? 12.0 : 24.0;
        // The width inside a step's box: the page, less its gutters and the
        // box's own padding. Decided here, once, so a step can choose between
        // side by side and stacked while it builds.
        final inner =
            (constraints.maxWidth - gutter * 2).clamp(0.0, 1180.0) - 32;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(gutter, 12, gutter, 10),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1180),
                  child: _timeline(context, narrow),
                ),
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: SingleChildScrollView(
                padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 24),
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 1180),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_isEdit) _editingBanner(context),
                        _currentStep(context, narrow, inner),
                        if (_error != null) ...[
                          const SizedBox(height: 20),
                          ErrorNote(_error!),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
            const Divider(height: 1),
            _footer(context, narrow),
          ],
        );
      },
    );
  }

  Widget _currentStep(BuildContext context, bool narrow, double inner) {
    _detailsBeside = false;

    return switch (_stepIndex) {
      0 => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (!_isEdit) _templateBar(context),
          _nameStep(context),
        ],
      ),
      1 => _triggerStep(context, narrow, inner),
      2 => _conditionStep(context, narrow, inner),
      3 => _actionStep(context, narrow, inner),
      _ => _reviewStep(context),
    };
  }

  void _goTo(int step) {
    setState(() {
      if (step != _stepIndex) _left.add(_stepIndex);
      _stepIndex = step;
    });
  }

  /// What stops the step on screen from being finished — the share of
  /// [_blocker] that belongs to it, word for word.
  String? _problemOf(int step) => switch (step) {
    0 => _nameProblem,
    1 => _whenProblem,
    2 => _conditionsProblem,
    3 => _actionsProblem,
    _ => _blocker,
  };

  bool _stepDone(int step) => switch (step) {
    0 => _nameProblem == null,
    1 => _whenProblem == null,
    // An empty Only if is an answer once somebody has moved past it, and on a
    // saved rule, where it was given when the rule was written.
    2 =>
      _conditionsProblem == null &&
          (_conditions.isNotEmpty ||
              _hasOpaque ||
              _left.contains(2) ||
              _isEdit),
    3 => _actionsProblem == null,
    _ => false,
  };

  /// The line under a step on the timeline, once there is something to say.
  String? _stepSummary(int step) {
    switch (step) {
      case 0:
        final name = _name.text.trim();
        return name.isEmpty ? null : name;
      case 1:
        final trigger = _trigger;
        if (trigger == null) return null;
        return widget.catalogue.locate(trigger)?.$2.label ?? trigger;
      case 2:
        if (_hasOpaque) return 'kept as stored';
        if (_conditions.isNotEmpty) {
          return '${_conditions.length} '
              '${_conditions.length == 1 ? 'condition' : 'conditions'}';
        }
        return _left.contains(2) || _isEdit ? 'none' : null;
      case 3:
        final count = _lockedBecause != null
            ? _loadedActions.length
            : _actions.length;
        return count == 0 ? null : '$count ${count == 1 ? 'step' : 'steps'}';
      default:
        return 'test and turn on';
    }
  }

  Widget _timeline(BuildContext context, bool narrow) {
    final children = <Widget>[];

    for (var i = 0; i < _stepLabels.length; i++) {
      if (i > 0) {
        final line = _timelineLine(context, _stepDone(i - 1));
        children.add(
          narrow ? Expanded(child: line) : SizedBox(width: 18, child: line),
        );
      }
      children.add(
        narrow
            ? _timelineDot(context, i)
            : Expanded(child: _timelineStep(context, i)),
      );
    }

    return Row(children: children);
  }

  Widget _timelineLine(BuildContext context, bool done) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 4),
    child: SizedBox(
      height: 2,
      child: ColoredBox(
        color: done
            ? ToneColours.of(context, Tone.ok).ink
            : EzBiziTheme.edge(context),
      ),
    ),
  );

  /// The number in its circle: filled on the step on screen, a tick once a
  /// step is complete, an outline before either.
  Widget _timelineMark(BuildContext context, int step) {
    final scheme = Theme.of(context).colorScheme;
    final current = step == _stepIndex;
    final done = !current && _stepDone(step);
    final ok = ToneColours.of(context, Tone.ok);

    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: current
            ? scheme.primary
            : done
            ? ok.fill
            : scheme.surface,
        border: Border.all(
          color: current
              ? scheme.primary
              : done
              ? ok.ink
              : scheme.outline,
          width: 1.5,
        ),
      ),
      child: done
          ? Icon(Icons.check, size: 14, color: ok.ink)
          : Text(
              '${step + 1}',
              style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: current ? scheme.onPrimary : scheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
    );
  }

  Widget _timelineStep(BuildContext context, int step) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final current = step == _stepIndex;
    final summary = _stepSummary(step);

    return Material(
      color: current ? scheme.primaryContainer : scheme.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        key: ValueKey('automation-step-${_stepKeys[step]}'),
        borderRadius: BorderRadius.circular(10),
        onTap: _busy ? null : () => _goTo(step),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
          child: Row(
            children: [
              _timelineMark(context, step),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      _stepLabels[step],
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelLarge?.copyWith(
                        color: current ? scheme.onPrimaryContainer : null,
                      ),
                    ),
                    if (summary != null)
                      Text(
                        summary,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// The phone's timeline: a dot per step, and the words only on the one on
  /// screen (Jose's 4).
  Widget _timelineDot(BuildContext context, int step) {
    final current = step == _stepIndex;

    return InkWell(
      key: ValueKey('automation-step-${_stepKeys[step]}'),
      borderRadius: BorderRadius.circular(14),
      onTap: _busy ? null : () => _goTo(step),
      child: Padding(
        padding: const EdgeInsets.all(2),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _timelineMark(context, step),
            if (current) ...[
              const SizedBox(width: 6),
              Text(
                _stepLabels[step],
                style: Theme.of(context).textTheme.labelLarge,
              ),
            ],
          ],
        ),
      ),
    );
  }

  // ── the counts the server may or may not answer ─────────────────

  String _timesInMonth(int count) =>
      '$count ${count == 1 ? 'time' : 'times'} in the last 30 days';

  /// `GET /automations/trigger-stats?triggers=a,b` answers
  /// `{data: {key: {last_30_days: n}}}`, leaving out triggers that are unknown
  /// or out of reach. Any failure — a 404 from a server without the endpoint
  /// included — leaves the cards without a count.
  Future<void> _loadTriggerStats() async {
    final keys = [
      for (final module in widget.catalogue.modules)
        for (final trigger in module.triggers) trigger.key,
    ];
    if (keys.isEmpty) return;

    final response = await widget.api.get(
      '/automations/trigger-stats?triggers='
      '${Uri.encodeQueryComponent(keys.join(','))}',
    );
    if (!mounted || !response.ok) return;

    final data = response.data;
    final counts = <String, int>{};

    if (data is Map) {
      for (final entry in data.entries) {
        final count = entry.value is Map
            ? (entry.value as Map)['last_30_days']
            : null;
        if (count is num) counts['${entry.key}'] = count.toInt();
      }
    }

    if (counts.isEmpty) return;
    setState(() => _triggerStats = counts);
  }

  /// The conditions as `POST /automations/would-have-run` is asked about
  /// them, or null when there is nothing honest to ask: no trigger, a clause
  /// the save would refuse, or no conditions at all (which runs every time).
  String? get _rehearsalRequest {
    if (_trigger == null || _conditionsProblem != null) return null;
    if (_conditions.isEmpty && !_hasOpaque) return null;

    // The conditions exactly as the save sends them.
    return jsonEncode({
      'trigger': _trigger,
      'conditions': _conditionsPayload(),
    });
  }

  /// Asks once per distinct set of conditions, after typing pauses.
  void _scheduleRehearsal() {
    final request = _rehearsalRequest;
    if (request == null ||
        request == _rehearsalFor ||
        request == _rehearsalPending) {
      return;
    }

    _rehearsalPending = request;
    _rehearsalTimer?.cancel();
    _rehearsalTimer = Timer(
      const Duration(milliseconds: 600),
      () => _rehearse(request),
    );
  }

  Future<void> _rehearse(String request) async {
    final response = await widget.api.post(
      '/automations/would-have-run',
      jsonDecode(request) as Map<String, dynamic>,
    );
    if (!mounted || _rehearsalPending != request) return;

    // `{total, matched, examples: [{occurred_at, matched}]}`, the examples
    // newest first and at most five.
    final data = response.ok ? response.data : null;
    final wouldRun = data is Map ? data['matched'] : null;
    final total = data is Map ? data['total'] : null;

    setState(() {
      _rehearsalPending = null;
      _rehearsalFor = request;
      _rehearsal = data is Map && wouldRun is num && total is num
          ? (
              wouldRun: wouldRun.toInt(),
              total: total.toInt(),
              examples: [
                for (final example in (data['examples'] as List? ?? const []))
                  if (example is Map)
                    (
                      label: formatStamp(
                        example['occurred_at'],
                        'date unknown',
                        formats: widget.formats,
                      ),
                      ran: example['matched'] == true,
                    ),
              ].take(5).toList(),
            )
          : null;
    });
  }

  /// "9 of 23 would have run", beside the conditions it describes — or
  /// nothing, when the server did not answer for these conditions.
  Widget? _rehearsalCard(BuildContext context) {
    final rehearsal = _rehearsal;
    if (rehearsal == null || _rehearsalFor != _rehearsalRequest) return null;

    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final ok = ToneColours.of(context, Tone.ok);

    return Container(
      key: const ValueKey('automation-would-have-run'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The server reads at most the newest 500 events, so a total of 500
          // is a ceiling, not a month.
          Text(
            rehearsal.total >= _rehearsalCeiling
                ? '${rehearsal.wouldRun} of the newest ${rehearsal.total} '
                      'would have run'
                : '${rehearsal.wouldRun} of ${rehearsal.total} would have run, '
                      'last 30 days',
            style: theme.textTheme.titleSmall,
          ),
          for (final example in rehearsal.examples) ...[
            const SizedBox(height: 6),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  example.ran ? Icons.check : Icons.close,
                  size: 16,
                  color: example.ran ? ok.ink : scheme.onSurfaceVariant,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(example.label, style: theme.textTheme.bodySmall),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ── Review ───────────────────────────────────────────────────────

  Widget _reviewStep(BuildContext context) {
    return _step(
      context,
      5,
      'Review',
      'The rule as one sentence. Tap any part of it to change that part.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _sentence(context),
          if (widget.onTry case final tryIt?) ...[
            const SizedBox(height: 16),
            _tryCard(context, tryIt),
          ],
          const SizedBox(height: 16),
          _whereLine(context),
          if (_isEdit) ...[const SizedBox(height: 20), _historyStep(context)],
        ],
      ),
    );
  }

  String get _triggerWords =>
      _trigger == null ? 'nothing chosen yet' : widget.catalogue.describeTrigger(_trigger!);

  String _clauseWords(_ConditionRow row) {
    final field = row.field.text.trim().isEmpty
        ? 'a field not chosen yet'
        : row.field.text.trim();
    final op = widget.catalogue.operatorLabel(row.op);

    return switch (arityOf(row.op)) {
      OperatorArity.none => '$field $op',
      OperatorArity.one => '$field $op ${row.value.text.trim()}',
      OperatorArity.many => '$field $op ${row.parsedValues.join(', ')}',
    };
  }

  String get _conditionWords {
    if (_hasOpaque) return 'only if its stored conditions hold';
    if (_conditions.isEmpty) return 'every time';

    return 'only if ${[for (final row in _conditions) _clauseWords(row)].join(' and ')}';
  }

  String get _actionWords {
    final labels = _lockedBecause != null
        ? [
            for (final action in _loadedActions)
              _actionTypeLabel(action['type'] as String? ?? ''),
          ]
        : [
            for (final row in _actions)
              row.catalogueKey == null
                  ? 'a step not chosen yet'
                  : _actionLabel(row.catalogueKey!),
          ];

    return labels.isEmpty ? 'nothing yet' : labels.join(', then ');
  }

  /// Where it applies, as the end of the sentence.
  ///
  /// Named the way the picker names it: "Entire organization", "Ace Air Duct
  /// (all branches)", "Ace Air Duct · Main".
  String get _placeWords {
    final place = _place;
    if (place == null) return 'In the view you are in now';

    final named = scopeLabel(place, widget.companies, widget.branches);
    if (named != null) return named;

    for (final known in widget.catalogue.places) {
      if (known.key == place) return known.label;
    }

    final label = (widget.editing?['scope'] as Map?)?['label'];
    return label is String && label.isNotEmpty ? label : 'In one place';
  }

  /// The catalogue's places as the scope picker takes them, optionally only
  /// one kind.
  Map<String, String> _placesOf({String? kind}) => {
    for (final place in widget.catalogue.places)
      if (kind == null || place.key.startsWith('$kind:'))
        place.key: place.label,
  };

  /// "When …, only if …, then …. Entire organization." Each part is
  /// the way back to the step that wrote it.
  Widget _sentence(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final base = theme.textTheme.titleSmall?.copyWith(
      fontWeight: FontWeight.w500,
      height: 1.6,
    );
    final link = base?.copyWith(
      color: scheme.primary,
      fontWeight: FontWeight.w700,
      decoration: TextDecoration.underline,
      decorationColor: scheme.primary,
    );

    InlineSpan part(String text, int step) => WidgetSpan(
      alignment: PlaceholderAlignment.baseline,
      baseline: TextBaseline.alphabetic,
      child: InkWell(
        key: ValueKey('automation-sentence-${_stepKeys[step]}'),
        onTap: _busy ? null : () => _goTo(step),
        child: Text(text, style: link),
      ),
    );

    return Container(
      key: const ValueKey('automation-sentence'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text.rich(
        TextSpan(
          style: base,
          children: [
            const TextSpan(text: 'When '),
            part(_triggerWords, 1),
            const TextSpan(text: ', '),
            part(_conditionWords, 2),
            const TextSpan(text: ', then '),
            part(_actionWords, 3),
            const TextSpan(text: '. '),
            part('$_placeWords.', 0),
          ],
        ),
      ),
    );
  }

  Widget _tryCard(BuildContext context, VoidCallback tryIt) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Try it', style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  'A rehearsal of the saved rule against the most recent real '
                  'event. Nothing is actually done.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          OutlinedButton(
            key: const Key('automation-builder-try'),
            onPressed: _busy ? null : tryIt,
            child: const Text('Try the saved rule'),
          ),
        ],
      ),
    );
  }

  Widget _whereLine(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        const Icon(Icons.place_outlined, size: 18),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            // Not "Where it applies: In the view…", which says where twice.
            _place == null
                ? 'Where it applies: the view you are in now'
                : 'Where it applies: $_placeWords',
            style: theme.textTheme.bodyMedium,
          ),
        ),
        TextButton(
          key: const ValueKey('automation-review-where'),
          onPressed: _busy ? null : () => _goTo(0),
          child: const Text('Change'),
        ),
      ],
    );
  }

  Widget _editingBanner(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Row(
        children: [
          const Icon(Icons.edit_outlined, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Editing “${widget.editing?['name']}”. Saving makes this the live '
              'version; the earlier ones stay under History.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }

  /// Start from a ready-made rule instead of an empty form.
  ///
  /// Jose, 2026-09-17: *"i want to have Templates as well, these will be
  /// global, with basic actions that you can think of we'll be adding more, so
  /// people can easily select them and apply them."*
  ///
  /// APPLYING ONE SAVES NOTHING. It fills the form in and stops — the person
  /// still names it, still reads every condition and step, and still presses
  /// the button. So a template cannot switch anything on behind somebody's
  /// back, and a badly chosen one costs a glance rather than an incident.
  ///
  /// NEW RULES ONLY. On an edit the trigger is fixed for the life of the rule,
  /// so a control that rewrote it would be offering something the form refuses
  /// two steps further down.
  Widget _templateBar(BuildContext context) {
    if (widget.catalogue.templates.isEmpty) return const SizedBox.shrink();

    final pending = _pendingTemplate;

    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _templatePicker(context),
          // THE CONFIRM, IN PLACE. It sits under the picker it answers, and
          // nothing covers the form it is about to clear.
          if (pending != null)
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.fromLTRB(14, 12, 8, 8),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Starting from "${pending['name']}" clears the name, the '
                    'conditions and the steps you have here now.',
                  ),
                  Wrap(
                    alignment: WrapAlignment.end,
                    spacing: 8,
                    children: [
                      TextButton(
                        onPressed: () => setState(() => _pendingTemplate = null),
                        child: const Text('Keep mine'),
                      ),
                      FilledButton(
                        onPressed: () {
                          setState(() => _pendingTemplate = null);
                          _fillFromTemplate(pending);
                        },
                        child: const Text('Use the template'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _templatePicker(BuildContext context) {
    return SearchPickerField<String>(
        key: const ValueKey('tour-automations-template'),
        value: null,
        label: 'Start from a template',
        helperText: 'Fills this form in. Nothing is saved until you press the '
            'button at the bottom, and you can change every part of it first.',
        helperMaxLines: 3,
        options: [
          for (final template in widget.catalogue.templates)
            SearchOption<String>(
              value: template['key'] as String,
              // STILL REQUIRED with a child, and not decoration: this is what
              // the search filters on. A card has no single string in it, so
              // something has to say which words the list matches.
              // The area it runs on is searchable too, so "phone" or
              // "reviews" finds every template for that part of EzBizi.
              label: [
                template['name'] as String,
                if (template['trigger_key'] is String)
                  widget.catalogue.describeTrigger(
                    template['trigger_key'] as String,
                  ),
              ].join(' '),
              detail: template['description'] as String?,
              // A CARD, because the plain two-line row read as a wall. Jose,
              // 2026-09-22: "perhaps in card format or something, right now
              // it's just bad". Each one is a thing somebody is choosing
              // between, and five of them stacked with no edges made it hard
              // to see where one ended.
              child: _templateCard(context, template),
            ),
        ],
        onChanged: _busy ? null : _applyTemplate,
    );
  }

  /// One template, drawn as something you can tell apart from the next one.
  ///
  /// The sheet gives each option a `ListTile` title, so this is deliberately a
  /// bounded card rather than a Column of text: the edge is the whole point —
  /// it is what says where one choice stops and the next begins.
  ///
  /// The trigger is named on it. Two of these differ only in what sets them
  /// off, and a description alone leaves somebody reading two paragraphs to
  /// find the difference.
  Widget _templateCard(BuildContext context, Map<String, dynamic> template) {
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;
    final trigger = template['trigger_key'] as String?;

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 4),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLowest,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.bolt_outlined, size: 16, color: scheme.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  template['name'] as String,
                  style: text.titleSmall,
                ),
              ),
            ],
          ),
          if (template['description'] != null) ...[
            const SizedBox(height: 6),
            Text(
              template['description'] as String,
              style: text.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
          if (trigger != null) ...[
            const SizedBox(height: 8),
            Text(
              'Runs on: ${widget.catalogue.describeTrigger(trigger)}',
              style: text.labelSmall?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ],
      ),
    );
  }

  /// Put a template into the form, replacing whatever is there.
  ///
  /// REPLACES rather than merges, and says so by asking first when there is
  /// anything to lose. A merge would produce a rule that is neither what the
  /// template says nor what the person wrote, and they would have to read the
  /// whole form to find out which parts came from where.
  Future<void> _applyTemplate(String? key) async {
    if (key == null) return;

    final template = widget.catalogue.templates
        .where((one) => one['key'] == key)
        .firstOrNull;

    if (template == null) return;

    final hasWork = _name.text.trim().isNotEmpty ||
        _conditions.isNotEmpty ||
        _actions.isNotEmpty;

    if (hasWork) {
      setState(() => _pendingTemplate = template);
      return;
    }

    _fillFromTemplate(template);
  }

  void _fillFromTemplate(Map<String, dynamic> template) {
    setState(() {
      _name.text = template['name'] as String;
      _description.text = (template['description'] as String?) ?? '';
      _trigger = template['trigger_key'] as String?;

      for (final row in _conditions) {
        row.dispose();
      }
      _conditions.clear();

      for (final row in _actions) {
        row.dispose();
      }
      _actions.clear();
    });

    // AFTER the clear, and through the same readers a saved rule goes through:
    // a second way of turning stored JSON into rows is a second thing to keep
    // in step with the wire, and it would drift on the first parameter either
    // of them gained.
    _adoptConditions(template['conditions']);
    _adoptActions(template['actions']);
    _adoptSchedule(template['schedule']);

    if (mounted) setState(() {});

    _loadTriggerFields();
  }

  Widget _nameStep(BuildContext context) {
    return _step(
      context,
      1,
      'Name it',
      'What you will see in the list, and in every run it records.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FieldPair(
            first: TextField(
              key: const ValueKey('tour-automations-name'),
              controller: _name,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Name',
                hintText: 'Tell the office when a job is completed',
              ),
            ),
            second: TextField(
              controller: _description,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'What it is for (optional)',
                hintText: 'Why this rule exists, for whoever reads it next',
              ),
            ),
          ),
          if (widget.catalogue.places.isNotEmpty) ...[
            const SizedBox(height: 12),
            ScopeChoiceField(
              key: const ValueKey('automation-where-it-applies'),
              value: _place,
              label: 'Where it applies',
              // SAYS WHAT SILENCE MEANS. On a new rule an untouched picker
              // scopes to wherever the person is standing, and a control that
              // acts without being touched has to say so — otherwise a rule's
              // reach is decided by a chip in the top bar that nobody would
              // ever connect to it.
              helperText: _place == null
                  ? 'Left alone, it applies in the view you are in now. A '
                        'company covers all its branches.'
                  : 'A company covers its branches, its sub companies and '
                        'theirs.',
              helperMaxLines: 3,
              places: _placesOf(),
              companies: widget.companies,
              branches: widget.branches,
              fallbackLabel: _placeWords,
              onChanged: _busy ? null : (value) => setState(() => _place = value),
            ),
          ],
        ],
      ),
    );
  }

  Widget _triggerStep(BuildContext context, bool narrow, double inner) {
    final chosen = _trigger;

    if (_isEdit) {
      return _step(
        context,
        2,
        'When this happens',
        'This stays as it is once a rule is saved, so its past runs keep '
            'making sense. To react to something else, make a new rule.',
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              key: const ValueKey('automation-when-locked'),
              children: [
                const Icon(Icons.bolt_outlined, size: 18),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    chosen == null
                        ? 'Unknown'
                        : widget.catalogue.describeTrigger(chosen),
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
              ],
            ),
            if (chosen != null) ...[
              const SizedBox(height: 12),
              _pickedTrigger(context, chosen, showChosen: false),
            ],
            // THE WHEN STAYS EDITABLE. What a rule reacts to is fixed; when a
            // clock rule runs is not, and the save sends it on every edit.
            // Hidden here, a scheduled rule could not be saved at all.
            if (_isScheduled) _scheduleEditor(context),
          ],
        ),
      );
    }

    final picker = CataloguePicker(
      key: const ValueKey('tour-automations-trigger'),
      groups: _triggerGroups,
      selected: chosen,
      searchHint: 'Search events',
      height: narrow ? 380 : 460,
      cards: true,
      onSelected: _chooseTrigger,
    );
    final picked = chosen == null ? null : _pickedTrigger(context, chosen);
    final beside = picked != null && !narrow && inner >= 900;

    return _step(
      context,
      2,
      'When this happens',
      'What sets it off. Pick a category, then the event, or search them '
          'all.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (beside)
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: picker),
                const SizedBox(width: 16),
                SizedBox(width: 300, child: picked),
              ],
            )
          else ...[
            picker,
            if (picked != null) ...[const SizedBox(height: 12), picked],
          ],
          if (_isScheduled) _scheduleEditor(context),
        ],
      ),
    );
  }

  /// The chosen event: what it is, how often it happened, and what it carries
  /// for the steps after it.
  Widget _pickedTrigger(
    BuildContext context,
    String key, {
    bool showChosen = true,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final trigger = widget.catalogue.locate(key)?.$2;
    final count = _triggerStats?[key];
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return Container(
      key: const ValueKey('automation-picked-trigger'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (showChosen)
            Text(
              'Chosen: ${widget.catalogue.describeTrigger(key)}',
              style: theme.textTheme.titleSmall,
            ),
          if (showChosen && trigger?.description != null)
            const SizedBox(height: 6),
          if (trigger?.description != null)
            Text(trigger!.description!, style: muted),
          if (count != null) ...[
            const SizedBox(height: 8),
            Text(
              _timesInMonth(count),
              key: const ValueKey('automation-trigger-count'),
              style: theme.textTheme.labelLarge?.copyWith(
                color: scheme.primary,
              ),
            ),
          ],
          if (_triggerFields.isNotEmpty || _fieldsSampled == 0) ...[
            const SizedBox(height: 12),
            Text('What this event carries', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            if (_triggerFields.isEmpty)
              Text(
                'This has not happened here yet, so there is nothing to list.',
                style: muted,
              )
            else ...[
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final field in _triggerFields)
                    Chip(
                      label: Text(field),
                      visualDensity: VisualDensity.compact,
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                'Test them in Only if, and drop them into a message in Then.',
                style: muted,
              ),
            ],
          ],
        ],
      ),
    );
  }

  /// Read a stored schedule back into the form.
  ///
  /// Tolerant of every field being missing, because the alternative is an edit
  /// screen that will not open for a rule saved by a newer build. What it will
  /// not do is invent: a zone it does not recognise is kept as the stored id
  /// rather than silently replaced with a default, since replacing it would
  /// retime somebody's rule the next time they pressed Save on an unrelated
  /// change.
  void _adoptSchedule(Object? stored) {
    if (stored is! Map) return;

    final kind = stored['kind'];
    if (kind is String && kind.isNotEmpty) _scheduleKind = kind;

    final time = stored['time'];
    if (time is String && time.isNotEmpty) _scheduleTime.text = time;

    final zone = stored['timezone'];
    if (zone is String && zone.isNotEmpty) _scheduleZone = zone;

    final days = stored['days'];
    if (days is List) {
      _scheduleDays
        ..clear()
        ..addAll(days.whereType<String>());
    }

    final day = stored['day_of_month'];
    if (day is int && day >= 1 && day <= 31) _scheduleDayOfMonth = day;
  }

  /// The schedule as the API takes it — only the keys this kind uses.
  ///
  /// `days` on a monthly rule and `day_of_month` on a weekly one are not merely
  /// redundant, they are misleading: they would be stored, read back into this
  /// form, and shown to whoever edits it next as though they meant something.
  Map<String, dynamic> _schedulePayload() => {
    'kind': _scheduleKind,
    'time': _scheduleTime.text.trim(),
    'timezone': _scheduleZone,
    if (_scheduleKind == 'weekly') 'days': _scheduleDays.toList()..sort(),
    if (_scheduleKind == 'monthly') 'day_of_month': _scheduleDayOfMonth,
  };

  /// Why this schedule cannot be saved yet, or null when it can.
  ///
  /// The server refuses all of these too — this is the same question asked
  /// early, so somebody is told before the round trip rather than by a 422.
  /// The server remains the one that decides; nothing here is the only check.
  String? get _scheduleProblem {
    if (!_isScheduled) return null;

    if (widget.catalogue.timezones.isEmpty) {
      return 'This build cannot offer timezones, so a schedule cannot be set here.';
    }

    if (_scheduleZone == null) {
      return 'Choose the timezone the time is written in.';
    }

    if (!RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(_scheduleTime.text.trim())) {
      return 'Write the time as HH:MM, such as 09:00.';
    }

    if (_scheduleKind == 'weekly' && _scheduleDays.isEmpty) {
      return 'Pick at least one day, or this rule never comes round.';
    }

    return null;
  }

  /// When it runs — shown only for the clock trigger.
  ///
  /// Inside "When this happens" rather than as a step of its own, because for
  /// this trigger the schedule IS what happens. A fifth numbered step would
  /// also renumber the three below it for every other rule in the product.
  Widget _scheduleEditor(BuildContext context) {
    final zones = widget.catalogue.timezones;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 14),
        Text('How often', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          children: [
            for (final kind in widget.catalogue.scheduleKinds)
              ChoiceChip(
                label: Text(kind.label),
                selected: _scheduleKind == kind.key,
                onSelected: (_) => setState(() => _scheduleKind = kind.key),
              ),
          ],
        ),
        if (_scheduleKind == 'weekly') ...[
          const SizedBox(height: 12),
          Text('On these days', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final day in _weekdays)
                FilterChip(
                  label: Text(_dayLabel(day)),
                  selected: _scheduleDays.contains(day),
                  onSelected: (on) => setState(() {
                    on ? _scheduleDays.add(day) : _scheduleDays.remove(day);
                  }),
                ),
            ],
          ),
        ],
        if (_scheduleKind == 'monthly') ...[
          const SizedBox(height: 12),
          Text('Day of the month', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 6),
          SizedBox(
            width: 200,
            child: SearchPickerField<int>(
              value: _scheduleDayOfMonth,
              label: 'Day of the month',
              options: [
                for (var d = 1; d <= 31; d++)
                  SearchOption(value: d, label: '$d'),
              ],
              onChanged: (d) => setState(() => _scheduleDayOfMonth = d ?? 1),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'A month without that date runs on its last day, so the 31st still '
            'runs in February.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 140,
              child: TextField(
                controller: _scheduleTime,
                decoration: const InputDecoration(
                  labelText: 'At',
                  hintText: '09:00',
                ),
                onChanged: (_) => setState(() {}),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: SearchPickerField<String>(
                value: _scheduleZone,
                label: 'Timezone',
                options: [
                  for (final zone in zones)
                    SearchOption(value: zone.key, label: zone.label),
                ],
                onChanged: (z) => setState(() => _scheduleZone = z),
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          // Said plainly because it is the reason the zone is asked for at all,
          // and because somebody who picks a fixed offset should know what they
          // have chosen rather than find out in March.
          'The time is read in this timezone, so it stays correct when the '
          'clocks change. A "UTC−5" style zone has no daylight saving and will '
          'not.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        // The problem is deliberately NOT drawn here. `_scheduleProblem` is
        // already what the save blocker reports, and this screen's rule is one
        // sentence per refusal — a second copy beside the control is how the
        // two come to disagree about which one is current. The blocker is the
        // single place a person is told why Save is dead.
      ],
    );
  }

  static const _weekdays = [
    'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
  ];

  String _dayLabel(String day) =>
      day.substring(0, 1).toUpperCase() + day.substring(1, 3);

  Widget _conditionStep(BuildContext context, bool narrow, double inner) {
    _scheduleRehearsal();
    final rehearsal = _rehearsalCard(context);

    if (_lockedBecause != null || _hasOpaque) {
      return _step(
        context,
        3,
        'Only if',
        _lockedBecause != null
            ? 'Read-only, for the same reason the steps below are — conditions '
                  'and steps are saved together as one version.'
            : 'These conditions nest groups inside each other, which this form '
                  'cannot draw. They are shown as stored and saved back exactly '
                  'as they are, rather than being flattened into something that '
                  'would mean something else.',
        _withRehearsal(
          !_hasOpaque
            ? Text(
                'There are none, so it runs every time.',
                style: Theme.of(context).textTheme.bodyMedium,
              )
            : Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  '$_opaqueConditions',
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                ),
              ),
          rehearsal,
          beside: !narrow && inner >= 900,
        ),
      );
    }

    return _step(
      context,
      3,
      'Only if',
      _conditions.isEmpty
          ? 'Optional. With nothing here it runs every time the event happens.'
          : 'Every one of these has to be true.',
      _withRehearsal(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final row in _conditions)
              Padding(
                key: row.key,
                padding: const EdgeInsets.only(bottom: 10),
                child: _conditionRow(context, row, narrow),
              ),
            _fieldCatalogue(context),
          ],
        ),
        rehearsal,
        beside: !narrow && inner >= 900,
      ),
      action: TextButton.icon(
        key: const ValueKey('tour-automations-add-condition'),
        onPressed: _busy
            ? null
            : () => setState(() {
                final row = _ConditionRow();
                _attach(row);
                _conditions.add(row);
              }),
        icon: const Icon(Icons.add, size: 16),
        label: const Text('Add a condition'),
      ),
    );
  }

  /// The conditions, and beside them (or under them) what recent events say
  /// they would have done.
  Widget _withRehearsal(Widget rows, Widget? card, {required bool beside}) {
    if (card == null) return rows;

    if (beside) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: rows),
          const SizedBox(width: 16),
          SizedBox(width: 280, child: card),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [rows, const SizedBox(height: 12), card],
    );
  }

  /// Everything this event carries, in one place, with the words that fit.
  ///
  /// Jose, 2026-09-17: *"i set website_chat manually cause i knew it, but i
  /// dont know the rest ... i need to know which ones they are, make them
  /// searchable, give me a catalog as well to see them all"*.
  ///
  /// The pickers answer it one field at a time, which is the right shape while
  /// somebody is writing a clause. This answers the other question — what is
  /// there AT ALL — which a picker cannot, because reading a picker means
  /// first guessing which field to hang it off.
  ///
  /// THREE DIFFERENT THINGS, AND THEY ARE NOT INTERCHANGEABLE. A field with a
  /// closed set lists every allowed value, because anything else matches
  /// nothing for as long as the rule exists. A field without one shows a real
  /// example, because the shape of a value is the only guide there is. A field
  /// with neither is still named, because knowing it exists is what lets
  /// somebody test it at all.
  ///
  /// Collapsed by default: a reference, not a step.
  Widget _fieldCatalogue(BuildContext context) {
    if (_triggerFields.isEmpty) return const SizedBox.shrink();

    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        childrenPadding: const EdgeInsets.only(bottom: 8),
        title: Text(
          'What this event carries (${_triggerFields.length})',
          style: text.titleSmall,
        ),
        subtitle: Text(
          'Every field you can test above, or drop into a message below.',
          style: text.bodySmall,
        ),
        children: [
          for (final field in _triggerFields)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SelectableText(field, style: text.titleSmall),
                  if (_fieldValues[field] != null)
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final option in _fieldValues[field]!)
                          Chip(
                            label: Text('${option.label} - ${option.value}'),
                            visualDensity: VisualDensity.compact,
                          ),
                      ],
                    )
                  else if (_fieldSamples[field] != null)
                    Text(
                      'Anything. Last one seen: ${_fieldSamples[field]}',
                      style: text.bodySmall,
                    )
                  else
                    Text('Anything.', style: text.bodySmall),
                ],
              ),
            ),
        ],
      ),
    );
  }

  /// Choosing a field, rather than guessing one.
  ///
  /// ── What this replaced, and what it cost ─────────────────────
  ///
  /// A free `TextField` with the hint "status". The vocabulary is fixed and the
  /// form published none of it, so a customer building "tell me about missed
  /// calls" wrote `from` and `Status` for an email whose payload carries
  /// `from_address` and `subject`. Both clauses read null, the rule skipped
  /// every run for a fortnight, and the trace said "the value at the time was
  /// nothing" each time — which reads as missing data, not as a wrong name. No
  /// error, nothing red; the rule simply never fired.
  ///
  /// A typo cannot be typed into a list. Search comes free with DropdownMenu
  /// and matters at the wide end: a Task event carries a couple of dozen
  /// fields, and scrolling a menu to find `due_on` is the same problem in a
  /// shorter costume.
  ///
  /// ── Why it still accepts free text ───────────────────────────
  ///
  /// The list is what recent events CARRIED, so a real field that has not
  /// appeared lately is absent. Refusing anything off-list would make a
  /// correct rule unsaveable because the office was quiet, so typing still
  /// works and an off-list value is kept and marked rather than dropped —
  /// the same idiom the settings selectors use for a stored value that is no
  /// longer offered.
  Widget _fieldChooser(BuildContext context, _ConditionRow row) {
    final typed = row.field.text.trim();
    final known = _triggerFields.contains(typed);

    // Nothing to offer yet. Say WHICH kind of nothing: a trigger that has not
    // fired here cannot be answered by waiting, and telling somebody to wait
    // for a list that will never arrive is worse than the text box was.
    if (_triggerFields.isEmpty) {
      return TextField(
        controller: row.field,
        enabled: !_busy,
        decoration: InputDecoration(
          labelText: 'Field',
          isDense: true,
          hintText: 'status',
          helperMaxLines: 3,
          helperText: _trigger == null
              ? 'Choose what this reacts to first, and the fields it carries '
                    'will be offered here.'
              : _fieldsSampled == 0
              ? 'This has not happened here yet, so there is nothing to list. '
                    'Type the field name if you know it.'
              : null,
        ),
      );
    }

    return DropdownMenu<String>(
      controller: row.field,
      enabled: !_busy,
      enableFilter: true,
      requestFocusOnTap: true,
      expandedInsets: EdgeInsets.zero,
      label: const Text('Field'),
      helperText: known || typed.isEmpty ? null : 'Not seen on this event lately',
      inputDecorationTheme: const InputDecorationTheme(isDense: true),
      dropdownMenuEntries: [
        // The stored value first when it is not one of the offered ones, so
        // opening an existing rule shows what it actually says instead of an
        // empty box that looks like the rule lost its field.
        if (typed.isNotEmpty && !known)
          DropdownMenuEntry(value: typed, label: '$typed — not seen lately'),
        for (final field in _triggerFields)
          DropdownMenuEntry(value: field, label: field),
      ],
      onSelected: (picked) {
        if (picked != null) _changed();
      },
    );
  }

  Widget _conditionRow(BuildContext context, _ConditionRow row, bool narrow) {
    final arity = arityOf(row.op);

    final field = _fieldChooser(context, row);
    final operator = SearchPickerField<String>(
                   value: row.op,
                   label: 'Is',
                   isDense: true,
                   options: [
              for (final operator in widget.catalogue.operators)
                SearchOption(value: operator.key, label: operator.label),
            ],
                   onChanged: _busy
                ? null
                : (value) => setState(() => row.op = value ?? row.op),
                 );
    final compared = arity == OperatorArity.none
              ? Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(
                    'Nothing to compare against — the field itself is the whole '
                    'test.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                )
              : (arity == OperatorArity.one &&
                        _fieldValues[row.field.text.trim()] != null)
              // A CLOSED SET GETS A LIST, because nothing on this screen told
              // anybody the value they had to type was `website_chat`. The
              // field picker fixed half of "people cannot guess them around"
              // and left the other half: knowing the field is `source` does not
              // tell you what goes in the box beside it.
              //
              // Only for `is`. "is one of" takes several values separated by
              // commas, and one dropdown cannot say two things - that case
              // keeps the text box and lists the options underneath instead.
              ? SearchPickerField<String>(
                  value: row.value.text.trim().isEmpty
                      ? null
                      : row.value.text.trim(),
                  label: 'Value',
                  isDense: true,
                  onChanged: _busy
                      ? null
                      : (chosen) => setState(
                          () => row.value.text = chosen ?? '',
                        ),
                  options: [
                    for (final option in _fieldValues[row.field.text.trim()]!)
                      SearchOption<String>(
                        value: option.value,
                        // The wire name is shown as well as the plain one: a
                        // rule saved before this existed reads back as the raw
                        // value, and somebody comparing the two needs to see
                        // they are the same thing.
                        label: '${option.label}  (${option.value})',
                      ),
                  ],
                )
              : (arity == OperatorArity.many &&
                    _fieldValues[row.field.text.trim()] != null)
              // A CLOSED SET under "is one of" is ticked, not typed (Jose,
              // 2026-10-20: email auto-replies set up in the builder by him —
              // which inboxes, with nobody typing an address).
              ? Wrap(
                  key: ValueKey('values-${row.key}'),
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final option in _fieldValues[row.field.text.trim()]!)
                      FilterChip(
                        label: Text(option.label),
                        selected: row.parsedValues.contains(option.value),
                        onSelected: _busy
                            ? null
                            : (on) => setState(() {
                                final picked = [...row.parsedValues]
                                  ..remove(option.value);
                                if (on) picked.add(option.value);
                                row.value.text = picked.join(', ');
                              }),
                      ),
                  ],
                )
              : TextField(
                  controller: row.value,
                  enabled: !_busy,
                  decoration: InputDecoration(
                    isDense: true,
                    labelText: arity == OperatorArity.many ? 'Values' : 'Value',
                    // "is one of" could not be expressed at all before: the form
                    // sent one string, the evaluator compares against a list, so
                    // the clause never matched anything.
                    helperText: arity == OperatorArity.many
                        ? (_fieldValues[row.field.text.trim()] == null
                              ? 'Separate them with commas'
                              : 'Separate them with commas — '
                                    '${_fieldValues[row.field.text.trim()]!.map((o) => o.value).join(', ')}')
                        : null,
                  ),
                );
    final remove = IconButton(
      icon: const Icon(Icons.close, size: 18),
      tooltip: 'Remove this condition',
      onPressed: _busy
          ? null
          : () => setState(() {
              _conditions.remove(row);
              row.dispose();
            }),
    );

    // On a phone the three boxes do not fit in one line, so the field gets a
    // line of its own and the comparison sits under it.
    if (narrow) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [Expanded(child: field), remove],
          ),
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(flex: 2, child: operator),
              const SizedBox(width: 8),
              Expanded(flex: 3, child: compared),
              const SizedBox(width: 40),
            ],
          ),
        ],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(flex: 3, child: field),
        const SizedBox(width: 8),
        Expanded(flex: 3, child: operator),
        const SizedBox(width: 8),
        Expanded(flex: 4, child: compared),
        remove,
      ],
    );
  }

  Widget _actionStep(BuildContext context, bool narrow, double inner) {
    final budget = widget.catalogue.actionBudget;

    if (_lockedBecause != null) {
      return _step(
        context,
        4,
        'Then do this',
        'Read-only.',
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ErrorNote(_lockedBecause!),
            const SizedBox(height: 12),
            for (final action in _loadedActions)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.play_arrow_outlined, size: 18),
                title: Text(_actionTypeLabel(action['type'] as String? ?? '')),
                subtitle: Text(
                  [
                    for (final entry in action.entries)
                      if (entry.key != 'type') '${entry.key}: ${entry.value}',
                  ].join(' · '),
                ),
              ),
          ],
        ),
      );
    }

    final selected = _actions.isEmpty
        ? null
        : _selectedAction.clamp(0, _actions.length - 1);

    final add = TextButton.icon(
      key: const ValueKey('tour-automations-add-step'),
      onPressed: _busy
          ? null
          : () => setState(() {
              final row = _ActionRow();
              _attach(row);
              _actions.add(row);
              _selectedAction = _actions.length - 1;
            }),
      icon: const Icon(Icons.add, size: 16),
      label: const Text('Add a step'),
    );

    // The details sit beside the editor whenever the list does, so the chips
    // under each box would be the same chips twice. Set before the editor is
    // built, which reads it.
    _detailsBeside = !narrow;

    final editor = selected == null
        ? Text(
            'Nothing yet. A rule with no actions would watch for something and '
            'then do nothing about it.',
            style: Theme.of(context).textTheme.bodyMedium,
          )
        : Padding(
            key: _actions[selected].key,
            padding: EdgeInsets.zero,
            child: _actionCard(context, selected, _actions[selected]),
          );

    final Widget body;

    if (narrow) {
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (var i = 0; i < _actions.length; i++)
                ChoiceChip(
                  key: ValueKey('automation-then-step-$i'),
                  label: Text(_stepListLabel(i)),
                  selected: i == selected,
                  onSelected: _busy
                      ? null
                      : (_) => setState(() => _selectedAction = i),
                ),
              add,
            ],
          ),
          const SizedBox(height: 12),
          editor,
        ],
      );
    } else {
      final details = _detailsPanel(
        context,
        selected == null ? null : _actions[selected],
      );
      final list = SizedBox(
        width: 200,
        child: _stepList(context, selected, add),
      );

      // Three columns only while the editor keeps room for the action
      // picker's category column (640px inside its card); below that the
      // details go under the editor rather than squeezing it.
      body = inner >= 1100
          ? Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                list,
                const SizedBox(width: 16),
                Expanded(child: editor),
                const SizedBox(width: 16),
                SizedBox(width: 220, child: details),
              ],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                list,
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [editor, const SizedBox(height: 12), details],
                  ),
                ),
              ],
            );
    }

    return _step(
      context,
      4,
      'Then do this',
      // Unbuilt actions are no longer offered at all (Jose, 2026-09-23), so
      // the sentence that promised them "listed and marked" went too.
      'The steps run one after another, in order.'
          '${budget == null ? '' : ' One event can set off at most $budget '
                    'steps in all.'}',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_triggerNotice != null) ...[
            _triggerNoticeBanner(context),
            const SizedBox(height: 12),
          ],
          body,
        ],
      ),
    );
  }

  String _stepListLabel(int index) {
    final picked = _actions[index].catalogueKey;

    return '${index + 1} · ${picked == null ? 'New step' : _actionLabel(picked)}';
  }

  /// The rule's steps in order, the open one marked.
  Widget _stepList(BuildContext context, int? selected, Widget add) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < _actions.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Material(
              color: i == selected ? scheme.primaryContainer : scheme.surface,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
                side: BorderSide(
                  color: i == selected ? scheme.primary : scheme.outlineVariant,
                ),
              ),
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                key: ValueKey('automation-then-step-$i'),
                onTap: _busy ? null : () => setState(() => _selectedAction = i),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                  child: Text(
                    _stepListLabel(i),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: i == selected ? scheme.onPrimaryContainer : null,
                      fontWeight: i == selected ? FontWeight.w600 : null,
                    ),
                  ),
                ),
              ),
            ),
          ),
        Align(alignment: Alignment.centerLeft, child: add),
      ],
    );
  }

  /// The boxes of one step that take `{{ }}`, by the same rule [_actionFields]
  /// uses to draw them.
  List<TextEditingController> _variableBoxesOf(_ActionRow row) {
    switch (row.type) {
      case 'update_field' ||
          'webhook' ||
          'voicemail.mark_dealt' ||
          'missed.create_job':
        return const [];
      case 'whatsapp.send':
        return row.whatsAppValues;
    }

    final definition = widget.catalogue.actionType(row.type);
    if (definition == null) return [row.message];

    return [
      for (final parameter in definition.parameters)
        if (parameter.variables) row.parameter(parameter.key),
    ];
  }

  /// What the event carries, beside the step being written: one tap drops a
  /// field in where the cursor last was — the same insert the chips under each
  /// box have always done.
  Widget _detailsPanel(BuildContext context, _ActionRow? row) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final boxes = row == null || row.picking
        ? const <TextEditingController>[]
        : _variableBoxesOf(row);
    final target = boxes.contains(_activeBox)
        ? _activeBox
        : (boxes.isEmpty ? null : boxes.first);

    return Container(
      key: const ValueKey('automation-then-details'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Details', style: theme.textTheme.titleSmall),
          const SizedBox(height: 4),
          Text(
            _insertableFields.isEmpty
                ? _variableHint()
                : target == null
                ? 'Nothing in this step takes details.'
                : 'Click one to drop it in where the cursor is.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          if (_insertableFields.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final field in _insertableFields)
                  _variableChip(
                    field,
                    target == null || _busy
                        ? null
                        : () => _insertVariable(target, field),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _variableChip(String field, VoidCallback? onPressed) => ActionChip(
    label: Text(
      _fieldSamples[field] == null ? field : '$field — ${_fieldSamples[field]}',
    ),
    visualDensity: VisualDensity.compact,
    onPressed: onPressed,
  );

  /// What the last trigger change took away.
  ///
  /// It sits inside step 4 rather than over the page, because that is where the
  /// gap it describes now is. Dismissible and not automatic: it stops being
  /// news when the reader says so, not when the form guesses they have read it.
  Widget _triggerNoticeBanner(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 2),
            child: Icon(Icons.info_outline, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _triggerNotice!,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          IconButton(
            icon: const Icon(Icons.close, size: 18),
            tooltip: 'Dismiss',
            onPressed: () => setState(() => _triggerNotice = null),
          ),
        ],
      ),
    );
  }

  Widget _actionCard(BuildContext context, int index, _ActionRow row) {
    final chosen = row.catalogueKey;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 12,
                  backgroundColor: Theme.of(
                    context,
                  ).colorScheme.surfaceContainerHighest,
                  child: Text(
                    '${index + 1}',
                    style: const TextStyle(fontSize: 11),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    chosen == null
                        ? 'Choose what this step does'
                        : _actionLabel(chosen),
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
                if (chosen != null && !row.picking)
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => setState(() => row.picking = true),
                    child: const Text('Change'),
                  ),
                IconButton(
                  icon: const Icon(Icons.close, size: 18),
                  tooltip: 'Remove this step',
                  onPressed: _busy
                      ? null
                      : () => setState(() {
                          _actions.remove(row);
                          row.dispose();
                          if (_selectedAction >= _actions.length) {
                            _selectedAction = _actions.isEmpty
                                ? 0
                                : _actions.length - 1;
                          }
                        }),
                ),
              ],
            ),
            if (row.picking) ...[
              const SizedBox(height: 12),
              CataloguePicker(
                groups: _actionGroups,
                selected: chosen,
                searchHint: 'Search actions',
                height: 260,
                onSelected: (key) => setState(() {
                  row.catalogueKey = key;
                  row.type = _payloadTypeFor(key);
                  _attachTaskParameters(row);
                  row.picking = false;
                }),
              ),
            ] else ...[
              const SizedBox(height: 12),
              Padding(
                padding: const EdgeInsets.only(left: 36, right: 8),
                child: _actionFields(row),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _actionFields(_ActionRow row) {
    return switch (row.type) {
      'update_field' => _updateFieldForm(row),
      'webhook' => TextField(
        controller: row.url,
        enabled: !_busy,
        decoration: const InputDecoration(
          labelText: 'https:// URL',
          isDense: true,
          helperText: 'Public addresses only — internal ones are refused.',
        ),
      ),
      'voicemail.mark_dealt' || 'missed.create_job' => _note(
        'Uses the call that triggered the rule. Nothing else to fill in.',
      ),
      'whatsapp.send' => _whatsAppForm(row),
      // THE SAME RULE THE PAYLOAD USES, so the two cannot disagree. `toJson`
      // builds any action that DECLARES parameters from those parameters; this
      // used to draw the parameter form for four types named by hand and fall
      // through to a bare Message box for everything else. So "Send a report",
      // "Assign to a person" and "Change the status" drew a Message box, while
      // the save posted parameter controllers nobody had been shown — and the
      // step was refused for a report nobody could have picked.
      //
      // A type with parameters gets them; a type with NONE gets a sentence
      // saying so, because an empty card reads as a form that failed to load.
      _ when (widget.catalogue.actionType(row.type)?.parameters.isNotEmpty ??
              false) =>
        _parameterForm(row, widget.catalogue.actionType(row.type)),
      _ when widget.catalogue.actionType(row.type) != null => _note(
        'Uses the record that triggered the rule. Nothing else to fill in.',
      ),
      _ => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            controller: row.message,
            enabled: !_busy,
            decoration: InputDecoration(
              labelText: 'Message',
              isDense: true,
              helperText: _variableHint(),
            ),
          ),
          _variableTools(row.message),
        ],
      ),
    };
  }

  String? _missingParameter(_ActionRow row, String type) {
    final definition = widget.catalogue.actionType(type);
    if (definition == null) {
      return 'This step needs a newer version of the app.';
    }

    for (final field in definition.parameters) {
      // A BOOLEAN IS NEVER MISSING. Off is an answer, and it is stored as the
      // empty string — so an emptiness test reads a deliberate "no" as a blank
      // and refuses to save with "X is required", against a switch that is
      // plainly sitting there answered. Every other kind is missing when empty;
      // this one cannot be.
      if (field.kind == 'boolean') {
        continue;
      }

      if (field.required &&
          !field.nullable &&
          row.parameter(field.key).text.trim().isEmpty) {
        return '${field.label} is required.';
      }
    }
    return null;
  }

  Widget _parameterForm(_ActionRow row, CatalogueActionType? definition) {
    if (definition == null) {
      return _note('This step needs a newer version of the app.');
    }

    return Column(
      children: [
        for (var i = 0; i < definition.parameters.length; i++) ...[
          if (definition.parameters[i].kind == 'phone_number')
            // A line is picked from the ones this company holds. The value
            // stored is an id, and asking somebody to type one is asking them
            // to fail — which is what a plain text field here would do.
            SearchPickerField<String>(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              helperText: _lines.isNotEmpty
                    ? 'Only lines approved for messaging are listed.'
                    : _linesHeld == 0
                    ? 'No phone lines on this company yet.'
                    // The sentence that used to be missing. Lines exist and none
                    // of them may text, which is a different errand entirely -
                    // and before the filter this case showed a full list whose
                    // every option failed at the carrier.
                    : 'This company has $_linesHeld phone '
                          '${_linesHeld == 1 ? 'line' : 'lines'}, and none are '
                          'approved for text messaging yet.',
              isDense: true,
              options: [
                for (final line in _lines)
                  SearchOption(value: line.$1, label: line.$2),
              ],
              onChanged: _busy
                  ? null
                  : (id) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          id ?? '',
                    ),
            )
          // WHICH FIELD OF THE EVENT CARRIES THE ADDRESS, from the same list
          // the condition builder offers. Not a typed address: on EzBizi's own
          // sending identity a rule that can email anywhere is a spam cannon
          // with the whole platform's reputation behind it. `sms.send` takes a
          // typed destination and that is deliberate — the line there is the
          // tenant's own.
          // THREE SOURCES, ONE BOX, AND TYPING IS ALLOWED. A person here, a
          // field of the event, or an address somebody types. Jose asked for
          // the first and last on 2026-09-16; the middle one is what the step
          // shipped with.
          // A GUIDE OR A TEST, one choice. Published only — the server
          // offers nothing else, because assigning a draft saves and refuses.
          // ONE FRANCHISE, from the places this person can reach — the same
          // list "Where it applies" uses, narrowed to franchises, because a
          // customer is owned by a franchise and never by a company.
          // A CONTRACT TEMPLATE, by name. Human Resources decides the list, so
          // a template nobody could sign is never offered here.
          else if (definition.parameters[i].kind == 'contract')
            SearchPickerField<String>(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              isDense: true,
              helperText: widget.catalogue.contracts.isEmpty
                  ? 'No contract template has a published version with a signature '
                        'yet, so there is nothing to start against.'
                  : null,
              options: [
                for (final contract in widget.catalogue.contracts)
                  SearchOption<String>(value: contract.key, label: contract.label),
              ],
              onChanged: _busy || widget.catalogue.contracts.isEmpty
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen ?? '',
                    ),
            )
          // A MAILCHIMP LIST. The step itself checks the tickbox; the list is
          // the only thing the rule author chooses.
          else if (definition.parameters[i].kind == 'audience')
            SearchPickerField<String>(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              isDense: true,
              helperText: widget.catalogue.audiences.isEmpty
                  ? 'No Mailchimp list is connected yet. Connect one in Newsletter first.'
                  : null,
              options: [
                for (final audience in widget.catalogue.audiences)
                  SearchOption<String>(value: audience.key, label: audience.label),
              ],
              onChanged: _busy || widget.catalogue.audiences.isEmpty
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen ?? '',
                    ),
            )
          else if (definition.parameters[i].kind == 'franchise')
            // Branches only, so each company is a header above its own.
            ScopeChoiceField(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              isDense: true,
              places: _placesOf(kind: 'franchise'),
              companies: widget.companies,
              branches: widget.branches,
              onChanged: _busy
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen,
                    ),
            )
          else if (definition.parameters[i].kind == 'training')
            SearchPickerField<String>(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              isDense: true,
              helperText: widget.catalogue.trainings.isEmpty
                  ? 'Nothing is published in Training yet, so there is nothing '
                        'to assign.'
                  : null,
              options: [
                for (final training in widget.catalogue.trainings)
                  SearchOption<String>(
                    value: training.key,
                    label: training.label,
                  ),
              ],
              onChanged: _busy || widget.catalogue.trainings.isEmpty
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen ?? '',
                    ),
            )
          // A WORK HOURS REPORT: who is in it and its default recipients
          // live on the definition, under Work Hours > Reports.
          // A STAFF WHATSAPP GROUP, by purpose.
          else if (definition.parameters[i].kind == 'whatsapp_group')
            SearchPickerField<String>(
              value: row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              label: definition.parameters[i].label,
              isDense: true,
              helperText: widget.catalogue.whatsappGroups.isEmpty
                  ? 'No WhatsApp group is set up yet.'
                  : null,
              options: [
                for (final group in widget.catalogue.whatsappGroups)
                  SearchOption<String>(value: group.key, label: group.label),
              ],
              onChanged: _busy || widget.catalogue.whatsappGroups.isEmpty
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen ?? '',
                    ),
            )
          else if (definition.parameters[i].kind == 'work_hours_report')
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SearchPickerField<String>(
                  value:
                      row.parameter(definition.parameters[i].key).text.isEmpty
                      ? null
                      : row.parameter(definition.parameters[i].key).text,
                  label: definition.parameters[i].label,
                  isDense: true,
                  helperText: widget.catalogue.workHoursReports.isEmpty
                      ? 'No work hours report yet. Make one in Work Hours, '
                            'Reports.'
                      : null,
                  options: [
                    for (final report in widget.catalogue.workHoursReports)
                      SearchOption<String>(
                        value: report.key,
                        label: report.label,
                      ),
                  ],
                  onChanged: _busy || widget.catalogue.workHoursReports.isEmpty
                      ? null
                      : (chosen) => setState(
                          () =>
                              row.parameter(definition.parameters[i].key).text =
                                  chosen ?? '',
                        ),
                ),
                _hoursCovers(
                  context,
                  row.parameter(definition.parameters[i].key).text,
                ),
              ],
            )
          else if (definition.parameters[i].kind == 'report')
            DropdownMenu<String>(
              initialSelection:
                  row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              enabled: !_busy && widget.catalogue.reports.isNotEmpty,
              label: Text(definition.parameters[i].label),
              expandedInsets: EdgeInsets.zero,
              enableFilter: true,
              requestFocusOnTap: true,
              helperText: widget.catalogue.reports.isEmpty
                  ? 'No saved reports yet. Save one from Reports first.'
                  : null,
              onSelected: _busy
                  ? null
                  : (chosen) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          chosen ?? '',
                    ),
              dropdownMenuEntries: [
                for (final report in widget.catalogue.reports)
                  DropdownMenuEntry<String>(
                    value: report.key,
                    label: report.label,
                  ),
              ],
            )
          else if (definition.parameters[i].kind == 'recipient_list')
            _recipientList(
              row,
              definition.parameters[i].key,
              definition.parameters[i].label,
            )
          else if (definition.parameters[i].kind == 'recipient' ||
              definition.parameters[i].kind == 'phone_recipient' ||
              definition.parameters[i].kind == 'person_or_field')
            _recipientPicker(
              row,
              definition.parameters[i].key,
              definition.parameters[i].label,
              definition.parameters[i].kind,
            )
          else if (definition.parameters[i].kind == 'trigger_field')
            _triggerFieldPicker(
              row,
              definition.parameters[i].key,
              definition.parameters[i].label,
            )
          // WHICH ROLES, and nothing chosen means everybody with reach — which
          // is what every rule saved before this did, so an untouched picker
          // must not change one. It narrows and can never widen: reach still
          // decides who COULD be told.
          else if (definition.parameters[i].kind == 'roles')
            _rolePicker(row, definition.parameters[i].key, definition.parameters[i].label)
          // NAMES, BESIDE THE ROLES. A role covers the people who hold it today
          // and a name covers one person whatever their role becomes, and Jose
          // asked for both. Searchable rather than a row of chips: thirteen
          // people fit, a hundred do not.
          else if (definition.parameters[i].kind == 'people')
            _peoplePicker(row, definition.parameters[i].key, definition.parameters[i].label)
          else if (definition.parameters[i].kind == 'boolean')
            // A yes/no answer, drawn as a switch.
            //
            // Without this kind a boolean parameter renders as a TEXT BOX
            // somebody types "true" into — so a module declaring one would
            // ship a control that looks broken and stores a string the server
            // has to guess at. The Settings session hit exactly that wanting a
            // per-rule "also email this", and the honest options were a bespoke
            // key outside the parameter mechanism or this. This is smaller and
            // it is the last time anyone has to choose.
            //
            // Stored as the same text the other kinds store — 'true' or '' —
            // because every parameter travels through one map of controllers
            // and a second typed store beside it would be a second thing to
            // keep in step. The server reads it as a boolean; the empty string
            // is false, which is also what an older client that never drew the
            // switch sends.
            SwitchListTile(
              value:
                  row.parameter(definition.parameters[i].key).text == 'true',
              onChanged: _busy
                  ? null
                  : (on) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          on ? 'true' : '',
                    ),
              title: Text(definition.parameters[i].label),
              contentPadding: EdgeInsets.zero,
              dense: true,
            )
          else
            TextField(
              controller: row.parameter(definition.parameters[i].key),
              enabled: !_busy,
              keyboardType: definition.parameters[i].kind == 'date'
                  ? TextInputType.datetime
                  : TextInputType.text,
              maxLines: definition.parameters[i].kind == 'rich_text' ? 3 : 1,
              decoration: InputDecoration(
                labelText: definition.parameters[i].label,
                isDense: true,
                hintText: definition.parameters[i].kind == 'date'
                    ? 'YYYY-MM-DD'
                    : null,
                helperText: definition.parameters[i].nullable
                    ? 'Leave blank to clear it.'
                    : definition.parameters[i].variables
                    ? _variableHint()
                    : null,
              ),
            ),
          if (definition.parameters[i].variables)
            _variableTools(row.parameter(definition.parameters[i].key)),
          if (i < definition.parameters.length - 1) const SizedBox(height: 10),
        ],
      ],
    );
  }

  /// Roles to narrow a notification to, stored as IDS.
  ///
  /// Not names: a Role has no key column and its name is editable, so a rule
  /// storing names would re-point itself the day somebody tidied "Office" into
  /// "Office Staff" — silently, and in the WIDENING direction. An id survives a
  /// rename and fails loudly on a delete.
  ///
  /// Stored in the parameter controller as a comma-joined list because every
  /// parameter shares one map of text controllers; the payload splits it back
  /// into an array, the same shape the runner reads.
  /// Pick people by name, for a step that also offers roles.
  ///
  /// Stores bare user ids, comma-joined, the way the role picker stores role
  /// ids — the notification dispatcher takes the two lists side by side and
  /// adds them up.
  ///
  /// NOTHING CHOSEN MEANS THE ROLES DECIDE, and if neither is chosen everybody
  /// with reach is told, which is what every rule saved before today did. An
  /// untouched control must not change a rule.
  Widget _peoplePicker(_ActionRow row, String key, String label) {
    final controller = row.parameter(key);

    final chosen = controller.text
        .split(',')
        .map((one) => one.trim())
        .where((one) => one.isNotEmpty)
        .toList();

    String labelFor(String id) =>
        widget.catalogue.people.where((p) => p.key == 'user:$id').firstOrNull?.label ??
        '$id (no longer here)';

    void store(List<String> next) =>
        setState(() => controller.text = next.join(','));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        Text(
          chosen.isEmpty
              ? 'Nobody named — the roles above decide, and if none are ticked '
                    'everybody who can see the record is told.'
              : 'These people are told as well, and only if they can already '
                    'see the record.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (chosen.isNotEmpty) ...[
          const SizedBox(height: 4),
          Wrap(
            spacing: 8,
            children: [
              for (final one in chosen)
                InputChip(
                  label: Text(labelFor(one)),
                  onDeleted: _busy ? null : () => store([...chosen]..remove(one)),
                ),
            ],
          ),
        ],
        const SizedBox(height: 8),
        SearchPickerField<String>(
          value: null,
          label: 'Add somebody by name',
          options: [
            for (final person in widget.catalogue.people)
              if (!chosen.contains(person.key.replaceFirst('user:', '')))
                SearchOption(
                  value: person.key.replaceFirst('user:', ''),
                  label: person.label,
                ),
          ],
          onChanged: _busy
              ? null
              : (added) {
                  if (added == null || chosen.contains(added)) return;
                  store([...chosen, added]);
                },
        ),
      ],
    );
  }

  Widget _rolePicker(_ActionRow row, String key, String label) {
    final chosen = row
        .parameter(key)
        .text
        .split(',')
        .where((id) => id.trim().isNotEmpty)
        .toSet();

    final roles = widget.catalogue.roles;

    if (roles.isEmpty) {
      return _note('No roles to choose from, so this will tell everybody who '
          'can see the record.');
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        Text(
          chosen.isEmpty
              ? 'Nothing chosen — everybody who can see the record is told.'
              : 'Only these roles are told, and only those of them who can '
                    'already see the record.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 4),
        Wrap(
          spacing: 8,
          children: [
            for (final role in roles)
              FilterChip(
                label: Text(role.label),
                selected: chosen.contains(role.key),
                onSelected: _busy
                    ? null
                    : (on) => setState(() {
                        final next = {...chosen};
                        on ? next.add(role.key) : next.remove(role.key);
                        row.parameter(key).text = next.join(',');
                      }),
              ),
          ],
        ),
      ],
    );
  }

  /// Who an email goes to: KIND FIRST, then one control that fits that kind.
  ///
  /// It was a single box with everything in one menu — every person plus every
  /// field of the event. That overflows: thirteen people and ten fields already
  /// fills the screen, and a hundred employees makes it unusable. Jose said so
  /// while it was still small enough to work, which is the only time this kind
  /// of thing gets fixed.
  ///
  /// So: choose what KIND of address, then answer one question. A typed address
  /// gets a box. A person gets a SEARCHABLE list, because scrolling a hundred
  /// names is the same problem again. A field of the event keeps its own list,
  /// which is neither of the two Jose named but is what the step shipped with
  /// and what his live rule uses — removing it would break a saved rule.
  ///
  /// THE STORED VALUE IS UNCHANGED: `user:<id>`, a literal address, or a field
  /// name. The kind is DERIVED from what is stored rather than kept beside it,
  /// so a rule saved before this screen existed opens on the right kind and
  /// there is no second thing to keep in step.
  Widget _recipientPicker(_ActionRow row, String key, String label, String kindOf) {
    final controller = row.parameter(key);
    final value = controller.text.trim();
    final kind = _recipientKindOf(row, value, kindOf);

    // What each step is actually asking for differs, so the choices do too.
    // An assignment wants a PERSON and cannot use a typed anything; a text
    // wants a number; an email wants an address. Same control, three vocabularies.
    final wantsPhone = kindOf == 'phone_recipient';
    final typedAllowed = kindOf != 'person_or_field';

    final people = widget.catalogue.people
        .where((p) => kindOf == 'person_or_field'
            ? true
            : (wantsPhone ? p.hasPhone : p.hasEmail))
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SearchPickerField<String>(
          value: kind,
          label: label,
          isDense: true,
          options: [
            const SearchOption(value: 'person', label: 'Somebody who works here'),
            if (typedAllowed)
              SearchOption(value: 'typed', label: wantsPhone
                    ? 'A number I type'
                    : 'An address I type'),
            // THE CUSTOMER IT IS ABOUT: the job's, the report's, or the
            // customer itself. Read when the rule runs, so it is their number
            // as it is then (Jose, 2026-09-24: text the customer when a job
            // is Done).
            if (typedAllowed)
              const SearchOption(
                value: 'customer',
                label: 'The customer it is about',
              ),
            // Whoever the task is given to, on a task trigger (Jose, 2026-10-13).
            if (typedAllowed && (_trigger ?? '').startsWith('task.'))
              const SearchOption(
                value: 'assignee',
                label: 'Whoever the task is given to',
              ),
            SearchOption(value: 'field', label: kindOf == 'person_or_field'
                  ? 'Whoever the event names'
                  : wantsPhone
                      ? 'A number from the event'
                      : 'An address from the event'),
          ],
          onChanged: _busy
              ? null
              : (chosen) => setState(() {
                  row.recipientKind = chosen;
                  controller.text = switch (chosen) {
                    'customer' => 'customer:',
                    'assignee' => 'assignee:',
                    _ => '',
                  };
                }),
        ),
        const SizedBox(height: 10),
        if (kind == 'customer')
          Text(
            wantsPhone
                ? 'Their number is read when the rule runs. A rule about something '
                    'with no customer, or a customer with no number, sends nothing '
                    'and says so in the run log.'
                : 'Their address is read when the rule runs. A rule about something '
                    'with no customer, or a customer with no address, sends nothing '
                    'and says so in the run log.',
            style: Theme.of(context).textTheme.bodySmall,
          )
        else if (kind == 'person')
          DropdownMenu<String>(
            initialSelection: value.isEmpty ? null : value,
            enabled: !_busy,
            label: const Text('Search people'),
            expandedInsets: EdgeInsets.zero,
            enableFilter: true,
            requestFocusOnTap: true,
            helperText: people.isEmpty
                // Says WHICH is missing rather than "nobody found": with only an
                // email on file somebody is a valid choice for an email step and
                // not for a text, and that is a thing to go and fix.
                ? (wantsPhone
                    ? 'Nobody here has a phone number on their account yet.'
                    : 'Nobody here has an email address on their account yet.')
                : 'Type to search. If their details change it follows them.',
            onSelected: _busy
                ? null
                : (chosen) => setState(() => controller.text = chosen ?? ''),
            dropdownMenuEntries: [
              for (final person in people)
                DropdownMenuEntry<String>(value: person.key, label: person.label),
            ],
          )
        else if (kind == 'field')
          _triggerFieldPicker(
            row,
            key,
            kindOf == 'person_or_field'
                ? 'Which field names the person'
                : wantsPhone
                    ? 'Which field carries the number'
                    : 'Which field carries the address',
          )
        else
          TextField(
            controller: controller,
            enabled: !_busy,
            keyboardType:
                wantsPhone ? TextInputType.phone : TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: wantsPhone ? 'Phone number' : 'Email address',
              isDense: true,
              helperText: wantsPhone
                  ? 'Goes to this number every time.'
                  : 'Goes to this address every time.',
            ),
          ),
        if (kind == 'person' && value.isNotEmpty && !_personIsKnown(value))
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'The person this was set to no longer works here, so nothing '
              'would be sent.',
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
      ],
    );
  }

  /// SEVERAL recipients, because a report goes to the people who read it.
  ///
  /// The other sends address one person; this one usually addresses a handful,
  /// and one bad address among five must not stop the other four — which is why
  /// the runner treats partial failure as a success and why this control lets
  /// somebody add and remove rather than retype a list.
  ///
  /// Stored as a comma-joined string because every parameter shares one map of
  /// text controllers; the payload splits it into the array the runner reads.
  /// Each entry is the same `user:<id>` or literal address the single-recipient
  /// control stores, so one resolver serves both.
  Widget _recipientList(_ActionRow row, String key, String label) {
    final controller = row.parameter(key);

    final chosen = controller.text
        .split(',')
        .map((one) => one.trim())
        .where((one) => one.isNotEmpty)
        .toList();

    String labelFor(String stored) {
      if (!stored.startsWith('user:')) return stored;

      final person =
          widget.catalogue.people.where((p) => p.key == stored).firstOrNull;

      // A person who has left keeps their id on screen rather than vanishing:
      // a chip that disappeared would look like somebody removed it.
      return person?.label ?? '$stored (no longer here)';
    }

    void store(List<String> next) =>
        setState(() => controller.text = next.join(','));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        if (chosen.isEmpty)
          Text(
            'Nobody yet. Add the people who should receive it.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        if (chosen.isNotEmpty)
          Wrap(
            spacing: 8,
            children: [
              for (final one in chosen)
                InputChip(
                  label: Text(labelFor(one)),
                  onDeleted: _busy
                      ? null
                      : () => store([...chosen]..remove(one)),
                ),
            ],
          ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: DropdownMenu<String>(
                enabled: !_busy,
                label: const Text('Add somebody who works here'),
                expandedInsets: EdgeInsets.zero,
                enableFilter: true,
                requestFocusOnTap: true,
                onSelected: _busy
                    ? null
                    : (added) {
                        if (added == null || chosen.contains(added)) return;
                        store([...chosen, added]);
                      },
                dropdownMenuEntries: [
                  for (final person in widget.catalogue.people.where((p) => p.hasEmail))
                    DropdownMenuEntry<String>(
                      value: person.key,
                      label: person.label,
                    ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: row.pendingAddress,
                enabled: !_busy,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'Or an address outside the business',
                  isDense: true,
                  helperText: 'An accountant, say. Press add.',
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton(
              tooltip: 'Add this address',
              icon: const Icon(Icons.add_circle_outline),
              onPressed: _busy
                  ? null
                  : () {
                      final typed = row.pendingAddress.text.trim();

                      if (typed.isEmpty || chosen.contains(typed)) return;

                      store([...chosen, typed]);
                      row.pendingAddress.clear();
                    },
            ),
          ],
        ),
      ],
    );
  }

  /// Which of the three a stored value is. Derived, never stored beside it.
  /// What to say under a box the runner substitutes `{{ field }}` in.
  ///
  /// NAMES REAL FIELDS rather than saying variables exist. "Use {{ title }}"
  /// was already under two boxes and told nobody which words work here — and a
  /// name that does not resolve becomes an empty string with no error, so a
  /// guess costs a message with a hole in it that nobody can explain. These are
  /// the same names the "only if" dropdown lists, because they come from the
  /// same event.
  String _variableHint() {
    if (_insertableFields.isEmpty) {
      return _trigger == null
          ? 'Choose what sets the rule off and the fields you can drop in appear here.'
          : 'Use {{ field }} to drop in a detail. Nothing of this kind has '
                'happened here yet, so there are no names to suggest.';
    }

    final fields = _insertableFields;
    final shown = fields.take(4).join(', ');

    return fields.length > 4
        ? 'Drop in a detail with {{ }} — $shown and ${fields.length - 4} more.'
        : 'Drop in a detail with {{ }} — $shown.';
  }

  /// The catalogue and the preview that sit under a box taking `{{ }}`.
  ///
  /// Two complaints, one control. A name typed from memory that does not exist
  /// becomes an empty string with no error, so the chips insert the real name
  /// and nobody has to remember it; and a message that reads fine in the
  /// builder can still arrive with a hole in it, so the preview shows what a
  /// recipient would actually get.
  ///
  /// THE PREVIEW IS BUILT THE WAY THE RUNNER BUILDS IT — an unknown name goes
  /// to an empty string here too, deliberately. A preview that left `{{oops}}`
  /// on screen would be kinder and would hide the exact failure this exists to
  /// show: the gap is what the customer sees.
  ///
  /// A field the server sent no example for is one whose record this person
  /// does not reach. It still gets a chip, because the field is real and the
  /// rule will resolve it when it runs; what it cannot have is a preview.
  Widget _variableTools(TextEditingController controller) {
    // Remembers which box the cursor is in, for the details beside the step.
    if (_watchedBoxes.add(controller)) {
      controller.addListener(() {
        if (controller.selection.isValid) _activeBox = controller;
      });
    }

    if (_insertableFields.isEmpty) return const SizedBox.shrink();

    final typed = controller.text;
    final used = RegExp(r'\{\{\s*([a-z0-9_.]+)\s*\}\}', caseSensitive: false)
        .allMatches(typed)
        .map((m) => m.group(1)!)
        .toList();

    final preview = typed.replaceAllMapped(
      RegExp(r'\{\{\s*([a-z0-9_.]+)\s*\}\}', caseSensitive: false),
      (m) => _fieldSamples[m.group(1)!] ?? '',
    );

    // Nothing to preview against: every name used is one this person cannot see
    // an example of, so a preview would be the message with its values deleted.
    final canPreview = used.isNotEmpty &&
        used.any((field) => _fieldSamples.containsKey(field));

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Under the box only when they are not already beside the step.
          if (!_detailsBeside)
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final field in _insertableFields)
                  _variableChip(
                    field,
                    _busy ? null : () => _insertVariable(controller, field),
                  ),
              ],
            ),
          if (canPreview) ...[
            const SizedBox(height: 6),
            Text(
              'They will read: $preview',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  /// Put `{{ field }}` where the cursor is, not at the end.
  ///
  /// A chip that appended would make writing a sentence around a variable a
  /// retyping exercise, which is how a catalogue ends up unused.
  void _insertVariable(TextEditingController controller, String field) {
    final selection = controller.selection;
    final text = controller.text;
    final at = selection.isValid ? selection.start : text.length;
    final end = selection.isValid ? selection.end : text.length;

    controller.value = TextEditingValue(
      text: text.replaceRange(at, end, '{{$field}}'),
      selection: TextSelection.collapsed(offset: at + field.length + 4),
    );
  }

  String _recipientKindOf(_ActionRow row, String value, String kindOf) {
    // A value decides its own kind. Only the empty one has to be remembered,
    // because it is the one value that could be any of the three.
    if (value.startsWith('user:')) return 'person';
    if (value == 'customer:') return 'customer';
    if (value == 'assignee:') return 'assignee';
    // An assignment names a person or a field and never a typed value, so a
    // non-empty value there can only be a field.
    if (kindOf != 'person_or_field') {
      if (kindOf == 'phone_recipient'
          ? RegExp(r'^[+0-9][0-9 ()\-.]*$').hasMatch(value)
          : value.contains('@')) {
        return 'typed';
      }
    }

    if (value.isNotEmpty) return 'field';

    return row.recipientKind ?? 'person';
  }

  bool _personIsKnown(String value) =>
      widget.catalogue.people.any((p) => p.key == value);

  /// WHICH FIELD OF THE EVENT carries the address or number.
  ///
  /// Shared by the email step's declared parameter and the WhatsApp step's own
  /// form, because they are asking the same question and a second copy would
  /// answer it differently the first time either changed.
  ///
  /// Not a typed destination, and that is the point on both: these send on
  /// EzBizi's own sending identity and EzBizi's own WhatsApp number, shared by
  /// every tenant, so a rule that could write to any address is a spam cannon
  /// with the platform's reputation behind it. `sms.send` takes a typed number
  /// on purpose — the line there is the tenant's own.
  Widget _triggerFieldPicker(_ActionRow row, String key, String label) {
    return DropdownMenu<String>(
      initialSelection: row.parameter(key).text.isEmpty
          ? null
          : row.parameter(key).text,
      enabled: !_busy && _triggerFields.isNotEmpty,
      label: Text(label),
      expandedInsets: EdgeInsets.zero,
      enableFilter: true,
      requestFocusOnTap: true,
      helperText: _triggerFields.isEmpty
          ? (_trigger == null
                ? 'Choose what sets the rule off first.'
                : 'Nothing of this kind has happened here yet, so there are no '
                      'fields to choose from.')
          : null,
      onSelected: _busy
          ? null
          : (chosen) => setState(() => row.parameter(key).text = chosen ?? ''),
      dropdownMenuEntries: [
        for (final field in _triggerFields)
          DropdownMenuEntry<String>(value: field, label: field),
      ],
    );
  }

  /// Pick an approved template, say who it goes to, fill its blanks.
  ///
  /// The blanks are drawn from the SERVER's count, not from the preview text:
  /// Meta numbers positionally and "{{1}} owes {{3}}" is three with a gap, so
  /// counting placeholders would be right on every template anybody tested and
  /// wrong at send time on the one with a hole. The save is refused when a blank
  /// is empty for the same reason — Meta would catch it, in the run log, later.
  Widget _whatsAppForm(_ActionRow row) {
    if (_whatsAppNumbers.isEmpty) {
      return _note(
        _whatsAppBecause ??
            'No WhatsApp templates are available for this business yet.',
      );
    }

    // A TEMPLATE IS APPROVED PER NUMBER, not per business. Meta approves against
    // the WABA behind a number and ours sit on different ones, so "which
    // template" cannot be asked before "from which number" — a flat list showed
    // one number's templates and silently hid the other's.
    final from = row.whatsAppFrom.isEmpty && _whatsAppNumbers.length == 1
        ? _whatsAppNumbers.single.from
        : row.whatsAppFrom;

    final number = _whatsAppNumbers.where((n) => n.from == from).firstOrNull;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_whatsAppNumbers.length > 1) ...[
          SearchPickerField<String>(
            value: from.isEmpty ? null : from,
            label: 'Send from',
            helperText: 'Templates are approved per number, so this decides '
                  'which are available.',
            isDense: true,
            options: [
              for (final n in _whatsAppNumbers)
                SearchOption(value: n.from, label: '${n.label} · ${n.from}'),
            ],
            onChanged: _busy
                ? null
                : (chosen) => setState(() {
                    row.whatsAppFrom = chosen ?? '';
                    // The template belonged to the old number and may not exist
                    // on the new one.
                    row.whatsAppTemplate = '';
                    row.whatsAppParameters = 0;
                    for (final c in row.whatsAppValues) {
                      c.dispose();
                    }
                    row.whatsAppValues.clear();
                  }),
          ),
          const SizedBox(height: 10),
        ],
        SearchPickerField<String>(
          value: row.whatsAppTemplate.isEmpty ? null : row.whatsAppTemplate,
          label: 'Template',
          helperText: 'Only templates Meta has approved are listed.',
          isDense: true,
          options: [
            for (final t in number?.templates ?? const [])
              SearchOption(
                value: t.name,
                // THE NAME IS THE LABEL AND THE BODY IS THE DETAIL.
                //
                // Both used to be one string — `'$name — $preview'` — so the
                // picker drew five whole WhatsApp templates as five paragraphs
                // with nothing between them. Jose, 2026-09-24: *"stuff like
                // this is unacceptable"*.
                //
                // Both are still searched, so typing a word from the body
                // still finds the template it belongs to.
                label: t.name,
                detail: t.preview,
              ),
          ],
          onChanged: _busy
              ? null
              : (chosen) => setState(() {
                  row.whatsAppFrom = from;
                  row.whatsAppTemplate = chosen ?? '';

                  final picked = (number?.templates ?? const [])
                      .where((t) => t.name == chosen)
                      .firstOrNull;

                  // FROM THE SERVER, never counted from the preview: Meta
                  // numbers positionally and "{{1}} owes {{3}}" is three with a
                  // gap.
                  row.whatsAppParameters = picked?.parameters ?? 0;

                  for (final c in row.whatsAppValues) {
                    c.dispose();
                  }
                  row.whatsAppValues
                    ..clear()
                    ..addAll([
                      for (var i = 0; i < row.whatsAppParameters; i++)
                        TextEditingController()..addListener(_changed),
                    ]);
                }),
        ),
        const SizedBox(height: 10),
        _recipientPicker(row, 'to_field', 'Send to', 'phone_recipient'),
        for (var i = 0; i < row.whatsAppValues.length; i++) ...[
          const SizedBox(height: 10),
          TextField(
            controller: row.whatsAppValues[i],
            enabled: !_busy,
            decoration: InputDecoration(
              labelText: 'Blank ${i + 1}',
              isDense: true,
              helperText: _variableHint(),
            ),
          ),
          _variableTools(row.whatsAppValues[i]),
        ],
      ],
    );
  }


  /// Which field, and what to set it to.
  ///
  /// The field was a free-text box, and the server took whatever was in it:
  /// `organization_id` on a job trigger saved with a 201 and was then refused
  /// by the engine on every run, forever. It is a picker now, and the options
  /// are the SUBJECT's — the record the trigger is about — because that is what
  /// the engine writes to, whichever module's action was chosen.
  Widget _updateFieldForm(_ActionRow row) {
    final subject = _subject;
    final fields = _writableFields;

    if (_trigger == null) {
      // An empty dropdown is a dead end that explains nothing. Which fields
      // exist is not knowable until step 2 is answered, so it says so.
      return _note(
        'Choose what this reacts to first, in step 2. Which fields a step can '
        'set depends on the record the event is about.',
      );
    }

    if (subject == null) {
      // A trigger this build cannot place: a module removed, or a server older
      // than this app. "No fields" and "I cannot tell" are different answers,
      // and treating the second as the first would dead-end the form over a
      // rule that is probably fine — including, on an edit, one that has been
      // running for months. So it degrades to what this box always was, says
      // why, and lets the server be the one that refuses.
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: TextField(
              controller: row.field,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Field',
                isDense: true,
                helperText:
                    'This app cannot tell what that event is about, so it '
                    'cannot offer the list. Saving will check it.',
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(child: _valueBox(row)),
        ],
      );
    }

    if (fields.isEmpty) {
      return _note(
        'Nothing on ${_article(subject.label)} can be set by a rule, so there '
        'is no field to choose. Pick a different kind of step.',
      );
    }

    final current = row.field.text.trim();
    final known = fields.any((f) => f.key == current);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: SearchPickerField<String>(
            // Null for anything the subject does not have, because the picker
            // shows nothing for a value with no matching option — and a saved
            // rule can legitimately carry one, from before any of this was
            // checked.
            value: known ? current : null,
            label: 'Field',
            isDense: true,
            helperText: known || current.isEmpty
                ? 'On ${_article(subject.label)} — whichever one the event '
                      'was about.'
                : null,
            // Named, not silently dropped. Somebody chose that value, or the
            // server stored it, and a box that just went blank is the thing
            // people file bugs about.
            errorText: known || current.isEmpty
                ? null
                : '“$current” is not something a rule may set on '
                      '${_article(subject.label)}.',
            options: [
              for (final field in fields)
                SearchOption(value: field.key, label: field.label),
            ],
            onChanged: _busy
                ? null
                : (value) => setState(() {
                    if (value != null) row.field.text = value;
                  }),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(child: _valueBox(row)),
      ],
    );
  }

  /// What to set the field to — a list when the field HAS a list.
  ///
  /// It was a text box for everything, including a status. The values have been
  /// served since the condition dropdowns landed, keyed by field, and this side
  /// never read them — so a rule could set a lead's status to "Booked" and skip
  /// for ever because the stored value is `booked`. That is the exact complaint
  /// that started the value pickers, surviving in the other half of the screen.
  ///
  /// Anything with an open set keeps its text box: a shorter list pretending to
  /// be the whole one is worse than typing.
  Widget _valueBox(_ActionRow row) {
    final options = _fieldValues[row.field.text.trim()];

    if (options == null) {
      return TextField(
        controller: row.value,
        enabled: !_busy,
        decoration: const InputDecoration(labelText: 'Set to', isDense: true),
      );
    }

    return SearchPickerField<String>(
      value: row.value.text.trim().isEmpty ? null : row.value.text.trim(),
      label: 'Set to',
      isDense: true,
      onChanged: _busy
          ? null
          : (chosen) => setState(() => row.value.text = chosen ?? ''),
      options: [
        for (final option in options)
          SearchOption<String>(
            value: option.value,
            // The wire name is shown too: a rule saved before this reads back as
            // the raw value, and somebody comparing the two needs to see they
            // are the same thing.
            label: '${option.label}  (${option.value})',
          ),
      ],
    );
  }

  Widget _note(String text) => Builder(
    builder: (context) =>
        Text(text, style: Theme.of(context).textTheme.bodySmall),
  );

  Widget _historyStep(BuildContext context) {
    final versions = (widget.editing?['versions'] as List? ?? [])
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList();

    final theme = Theme.of(context);

    return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('History', style: theme.textTheme.titleSmall),
          Text(
            'Every save is kept here. Put an older one back to undo a change.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 4),
          for (final version in versions)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Row(
                children: [
                  Text('Version ${version['version']}'),
                  if (version['is_active'] == true) const Tag('In use'),
                ],
              ),
              subtitle: Text(
                formatStamp(
                  version['created_at'],
                  'date unknown',
                  formats: widget.formats,
                ),
              ),
              trailing: version['is_active'] == true
                  ? null
                  : TextButton(
                      onPressed: _busy
                          ? null
                          : () => _restore(
                              version['id'] as String,
                              version['version'] as int,
                            ),
                      child: const Text('Put this one back'),
                    ),
            ),
        ],
    );
  }

  /// Back, where you are, Cancel, and the way forward — Next while there is a
  /// next step, Save on Review. Next refuses with the sentence Save would have
  /// used for the same gap, so nobody reaches Review to learn it there.
  Widget _footer(BuildContext context, bool narrow) {
    final review = _stepIndex == 4;
    final blocker = _blocker;
    final problem = _problemOf(_stepIndex);
    final note = review
        // The button says no; this says why. A disabled control with no
        // explanation is the thing people file bugs about.
        ? (blocker ??
              (_isEdit
                  ? 'Saving makes this the live version straight away.'
                  : 'It is saved switched off. Turn it on once you have read '
                        'it back.'))
        : problem;
    final style = Theme.of(context).textTheme.bodySmall;

    final back = TextButton(
      key: const ValueKey('automation-builder-back'),
      onPressed: _busy || _stepIndex == 0
          ? null
          : () => _goTo(_stepIndex - 1),
      child: const Text('‹ Back'),
    );
    final cancel = TextButton(
      onPressed: _busy ? null : widget.onCancel,
      child: const Text('Cancel'),
    );
    final skip =
        _stepIndex == 2 &&
            _conditions.isEmpty &&
            !_hasOpaque &&
            _lockedBecause == null
        ? TextButton(
            key: const ValueKey('automation-builder-skip'),
            onPressed: _busy ? null : () => _goTo(3),
            child: const Text('Skip, no conditions'),
          )
        : null;
    final forward = review
        ? FilledButton(
            key: const ValueKey('tour-automations-save'),
            onPressed: _busy || blocker != null ? null : _submit,
            child: Text(_isEdit ? 'Save changes' : 'Save'),
          )
        : FilledButton(
            key: const ValueKey('automation-builder-next'),
            onPressed: _busy || problem != null
                ? null
                : () => _goTo(_stepIndex + 1),
            child: Text(
              narrow ? 'Next ›' : 'Next: ${_stepLabels[_stepIndex + 1]} ›',
            ),
          );
    final count = Text('Step ${_stepIndex + 1} of 5', style: style);

    if (narrow) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            if (note != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(note, style: style),
              ),
            Row(
              children: [
                back,
                Expanded(child: Center(child: count)),
                forward,
              ],
            ),
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              children: [cancel, ?skip],
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 16),
      child: Row(
        children: [
          back,
          const SizedBox(width: 12),
          count,
          const SizedBox(width: 16),
          Expanded(child: Text(note ?? '', style: style)),
          const SizedBox(width: 16),
          cancel,
          if (skip != null) ...[const SizedBox(width: 10), skip],
          const SizedBox(width: 10),
          forward,
        ],
      ),
    );
  }

  /// The numbered-step shape this screen invented, now drawn by a shared widget
  /// so the contract designer cannot slowly disagree with it about a heading
  /// size. Kept as a method because every call site here reads
  /// `_step(context, 3, …)` and rewriting six of them buys nothing.
  ///
  /// Steps 2, 3 and 4 are one chain, When → Only if → Then (UI queue 73): each
  /// a card with its own mark, joined by a line, so the form reads like the
  /// sentence it makes. Naming it and its History stay numbered steps.
  Widget _step(
    BuildContext context,
    int number,
    String title,
    String note,
    Widget body, {
    Widget? action,
  }) {
    final mark = switch (number) {
      2 => (Icons.bolt, Tone.info),
      3 => (Icons.alt_route, Tone.caution),
      4 => (Icons.play_arrow, Tone.ok),
      _ => null,
    };
    if (mark == null) {
      return NumberedStep(
        number: number,
        title: title,
        note: note,
        action: action,
        child: body,
      );
    }

    final theme = Theme.of(context);
    final tone = ToneColours.of(context, mark.$2);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // A box, not a Card: a step's own action cards are Cards, and the
        // two must stay told apart.
        DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border.all(color: EzBiziTheme.edge(context)),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Container(
                      width: 30,
                      height: 30,
                      decoration: BoxDecoration(
                        color: tone.fill,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Icon(mark.$1, size: 18, color: tone.ink),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        title,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    ?action,
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  note,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 12),
                body,
              ],
            ),
          ),
        ),
      ],
    );
  }
}
