import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_html/flutter_html.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/files.dart';
import '../../core/onboarding.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../core/us_states.dart';
import '../../widgets/address_fields.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/pdf_view.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/signature_field.dart';
import '../../widgets/tab_bar_like.dart';
import '../../widgets/tag.dart';
import 'tax_form_panel.dart';
import '../../widgets/work_schedule_panel.dart';

/// The key on the control that answers one question.
///
/// Public because a test has no other reliable handle on a particular box: a
/// question's LABEL is the tenant's, two of them may legitimately read the same,
/// and none of them is attached to the control as a `labelText` — the wording
/// sits above the box with the tags beside it. The field key is the one thing on
/// a question that is unique by construction, so it is what the widget carries.
Key answerKey(String fieldKey) => Key('answer:$fieldKey');

// ── the questions every A-1 contract asks, and their controls ──────
//
// Jose, 2026-10-01: "we can't just let them put anything anywhere. contracts
// have format". These are catalog keys HR named (7ed8111d, d834214e); any
// other key keeps the control its kind of question gets.

/// Digits and one decimal point. An answer typed before this rule
/// ("20sdsadsa") loses its letters at the first edit.
final _decimal = [
  FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
  TextInputFormatter.withFunction(
    (old, next) => '.'.allMatches(next.text).length > 1 ? old : next,
  ),
];

/// A state's full name from "FL" or "florida"; null when it is neither.
String? stateName(String raw) {
  final text = raw.trim();
  if (usStates[text.toUpperCase()] case final name?) return name;
  for (final name in usStates.values) {
    if (name.toLowerCase() == text.toLowerCase()) return name;
  }
  return null;
}

const _payPeriods = ['Hour', 'Week', 'Month', 'Year'];

/// The contract's stylesheet sizes everything in points, for paper (A1's
/// look: a 9.5pt body, 18pt title). flutter_html reads a point as a pixel,
/// which drew 12pt headings smaller than a 15px body; every size is scaled
/// alike instead, so the body reads at 15 and the headings keep their order.
String contractOnScreen(String html) {
  String px(Match size) =>
      '${(double.parse(size[1]!) * 15 / 9.5).toStringAsFixed(2)}px';
  final points = RegExp(r'(\d+(?:\.\d+)?)pt\b');

  return html.replaceAllMapped(
    RegExp(r'(<style[^>]*>)(.*?)(</style>)', dotAll: true),
    (style) =>
        '${style[1]}${style[2]!.replaceAllMapped(points, px)}${style[3]}',
  );
}

/// AddressFields' boxes, and the parts the server names them by.
const _addressParts = {
  'line1': 'address_line1',
  'line2': 'address_line2',
  'city': 'city',
  'state': 'state',
  'postal': 'postal_code',
  'country': 'country',
};

const _weekDays = [
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
];

String _dayName(int i) =>
    _weekDays[i][0].toUpperCase() + _weekDays[i].substring(1);

/// "14:00" as "2:00 PM".
String _clock(String hhmm) {
  final hour = int.parse(hhmm.substring(0, 2));
  final twelve = hour % 12 == 0 ? 12 : hour % 12;
  return '$twelve:${hhmm.substring(3, 5)} ${hour < 12 ? 'AM' : 'PM'}';
}

/// The week the schedule panel returns, as the two answers the contract prints
/// and the server reads into the person's schedule (HR, d834214e):
/// `Monday to Friday, Sunday` and
/// `Monday to Friday 2:00 PM to 8:00 PM; Sunday 8:00 AM to 8:00 PM`.
/// Consecutive days share a range; for the hours they must share the times too.
(String days, String hours) scheduleText(Map<String, dynamic> week) {
  Map? at(int i) => week[_weekDays[i]] as Map?;

  List<(int, int)> runs(bool Function(int a, int b) same) {
    final out = <(int, int)>[];
    for (var i = 0; i < 7; i++) {
      if (at(i) == null) continue;
      if (out.isNotEmpty && out.last.$2 == i - 1 && same(out.last.$1, i)) {
        out.last = (out.last.$1, i);
      } else {
        out.add((i, i));
      }
    }
    return out;
  }

  String span((int, int) run) => run.$1 == run.$2
      ? _dayName(run.$1)
      : '${_dayName(run.$1)} to ${_dayName(run.$2)}';

  final days = runs((a, b) => true).map(span).join(', ');
  final hours = runs(
    (a, b) =>
        at(a)!['start'] == at(b)!['start'] && at(a)!['end'] == at(b)!['end'],
  ).map((run) {
    final window = at(run.$1)!;
    return '${span(run)} ${_clock('${window['start']}')} to '
        '${_clock('${window['end']}')}';
  }).join('; ');

  return (days, hours);
}

/// [scheduleText]'s hours read back into the panel's week. Null when the text
/// is not in that form — an answer typed before the picker existed.
Map<String, dynamic>? scheduleFrom(String hours) {
  String? hhmm(String hour, String minute, String half) {
    final h = int.parse(hour);
    if (h < 1 || h > 12) return null;
    final h24 = (h % 12) + (half.toUpperCase() == 'PM' ? 12 : 0);
    return '${h24.toString().padLeft(2, '0')}:$minute';
  }

  final week = <String, dynamic>{for (final day in _weekDays) day: null};
  final group = RegExp(
    r'^([a-z]+)(?: to ([a-z]+))? (\d{1,2}):(\d{2}) ([AP]M) to (\d{1,2}):(\d{2}) ([AP]M)$',
    caseSensitive: false,
  );

  for (final part in hours.split(';')) {
    final m = group.firstMatch(part.trim());
    if (m == null) return null;
    final from = _weekDays.indexOf(m[1]!.toLowerCase());
    final to = m[2] == null ? from : _weekDays.indexOf(m[2]!.toLowerCase());
    final start = hhmm(m[3]!, m[4]!, m[5]!);
    final end = hhmm(m[6]!, m[7]!, m[8]!);
    if (from < 0 || to < from || start == null || end == null) return null;
    for (var i = from; i <= to; i++) {
      week[_weekDays[i]] = {'start': start, 'end': end};
    }
  }

  return week;
}

/// The form both sides fill in (EZBIZI-HR-PLAN.md, "Onboarding").
///
/// ── One widget, two sides, on purpose ────────────────────────────
///
/// The employer opens this from the Onboarding tab; the new starter meets it as
/// the first thing in the app after accepting their invitation; the Records tab
/// opens it to read what was collected. It is the same form and the same code,
/// because the difference between the three is entirely a question of which
/// boxes are live — and that question is answered by the server, per field, in
/// `writable`.
///
/// Two copies of this screen would be two answers to "who may write the wage",
/// and only one of them would be the one the PATCH enforces. Worse on the
/// Records side: a second renderer for the same answers is a second place for a
/// sensitive value to be drawn in the clear by accident.
///
/// ── What the server decides, and this does not ───────────────────
///
///   - who may write a field: `writable`, carried, never derived from
///     `filled_by` and a guess at which side is reading;
///   - which questions exist and what they are called: the pinned contract
///     version, whose fields arrive with the onboarding, the common core among
///     them;
///   - what a sensitive answer looks like when it is withheld: the server sends
///     the mask, and this end never spells one;
///   - every word of the signing ceremony — see [Onboarding.consentStatement]
///     — and the fingerprint a signature is taken against, which arrives with
///     the document and is sent back untouched; see [OnboardingDocument].
///
/// What this file decides is which CONTROL draws a kind of question, which is
/// the carve-out contract_form_preview.dart already takes and for the same
/// reason: a server cannot send a widget. A kind this build has never met gets
/// a plain box and says so, rather than being dropped from a form whose whole
/// job is to be complete.
///
/// ── Why it fetches its own data ──────────────────────────────────
///
/// Saving answers returns the whole onboarding — new provenance, new masks, a
/// signature that may have become ready — so the thing on screen has to be
/// replaced by the answer to the save. Owning the fetch is what makes that one
/// path instead of a callback that hands a new object to a widget already
/// holding half-typed text.
class OnboardingForm extends StatefulWidget {
  const OnboardingForm({
    super.key,
    required this.api,
    required this.onboardingId,
    required this.vocabulary,
    required this.formats,
    this.onChanged,
    this.onBack,
    this.backLabel = 'Back',
    this.aside,
  });

  final ApiClient api;
  final String onboardingId;

  /// The words for a kind of question and for who fills one in, from
  /// `GET /hr/contracts/catalogue`.
  ///
  /// A FALLBACK, not the source. The onboarding brings its own — see
  /// [Onboarding.vocabulary] for why it has to, and [_OnboardingFormState] for
  /// which wins. The employer's tab has already fetched the catalogue for its
  /// list and passes it; the employee's destination has no permission to fetch
  /// it and passes [ContractVocabulary.none].
  final ContractVocabulary vocabulary;

  final DisplayFormats formats;

  /// Something behind this changed: a list of onboardings in flight, or the
  /// shell's own destination, is now out of date. Called after a save and after
  /// a signature — not on every keystroke.
  final VoidCallback? onChanged;

  /// Shown as a link above the form where there is somewhere to go back to. The
  /// employee's own destination has nowhere, and passes null.
  final VoidCallback? onBack;
  final String backLabel;

  /// Drawn after the form, about the PERSON rather than this onboarding —
  /// the HR record's Training card. Null everywhere the form is the whole
  /// page, which is every screen but Records.
  final Widget? aside;

  @override
  State<OnboardingForm> createState() => _OnboardingFormState();
}

class _OnboardingFormState extends State<OnboardingForm> {
  bool _loading = true;
  String? _error;

  Onboarding? _form;

  /// One controller per text-ish question, kept for the life of the screen.
  ///
  /// Not rebuilt from the payload on every frame: a `TextFormField(initialValue:)`
  /// inside a list keeps the FIELD's state where it sits, which is the bug the
  /// contract designer's `_Question` documents. These are keyed by the field
  /// key, so a question cannot end up wearing another question's text.
  final Map<String, TextEditingController> _boxes = {};

  /// Work days and hours set in the panel, or typed as availability (Jose,
  /// 2026-10-05: some technicians "do not have set hours and instead have
  /// certain availability", and "it should be one or the other, never
  /// both"). Null until chosen: a stored answer that is not a schedule then
  /// reads as typed.
  bool? _typedSchedule;

  bool _approving = false;

  /// What has been changed and not yet saved, by field key.
  ///
  /// Only these are sent. A form that PATCHed everything it was holding would
  /// re-send the other side's answers under this person's name and destroy the
  /// provenance that makes `either` usable at all — and for a masked answer it
  /// would send the mask, which is how the automations screen once saved
  /// `••••••••` over a working secret.
  final Map<String, String> _pending = {};

  /// Sensitive values this person has asked to see, by field key. Held only for
  /// as long as the screen is open; a reload masks them again, because the next
  /// look is another disclosure and gets its own audit entry.
  final Map<String, String> _revealed = {};

  /// Which field's reveal is in flight, so its own button spins rather than the
  /// page.
  String? _revealing;

  bool _saving = false;
  String? _uploading;

  /// Whatever the last save or reveal was refused with.
  ///
  /// One field for both because they end up in the same place on screen, and
  /// because the alternative — an error per action — produced the bug it was
  /// meant to avoid: a reveal refused on a read-only record had nowhere to
  /// report, since the only note on the page was inside a Save bar that a
  /// read-only record does not draw.
  String? _actionError;

  /// When this session last saved. Distinct from the onboarding's own
  /// `lastAnsweredAt`, which may be the other side's work: "you saved at 10:32"
  /// is the sentence that makes somebody believe they can close the tab.
  DateTime? _savedAt;

  /// Which signature is being submitted, and which one was refused with what.
  ///
  /// Both keyed, because a document can be waiting on more than one signature
  /// and an unkeyed error would print itself under every unsigned block on the
  /// page — including the one that was never attempted.
  String? _signingKey;
  String? _signErrorKey;
  String? _signError;

  /// The rendered document and its fingerprint, from its own endpoint.
  ///
  /// Fetched beside the form rather than with it, because that is how the
  /// server serves it — a preview rendered on demand until something is signed,
  /// the stored bytes afterwards — and refetched after every write, because
  /// answering a question changes the document a preview would produce. Signing
  /// against a stale hash is the one thing that must not happen: the server
  /// compares it, and a mismatch is a refusal rather than a silent signature
  /// over the wrong text.
  OnboardingDocument? _paper;
  String? _paperError;

  /// The contract as the answers on screen would make it, saved or not, from
  /// `POST …/preview` (Jose, 2026-10-01: "see the contract on the right side,
  /// changing as they input the information"). It writes nothing and keeps
  /// sensitive answers masked, so it is for READING: the signature still goes
  /// against [_paper]'s fingerprint, and Sign waits until nothing is unsaved.
  String? _previewHtml;
  String? _previewError;
  bool _previewing = false;
  Timer? _previewTimer;
  int _previewRun = 0;

  /// The pane shows the live text, or the PDF it would print as, stamped
  /// DRAFT (Jose, 2026-10-01: "PDF (Final Result view)").
  bool _asPdf = false;
  Uint8List? _pdf;

  /// Every key changed since the page opened, highlighted in the contract
  /// (Jose: "Dispatcher Master 5000 should be highlighted on the contract").
  final Set<String> _changed = {};

  /// On a phone the form and the contract take turns.
  bool _showContract = false;

  /// The stored bytes of the document, which the rendered contract replaces
  /// as the thing to read. Still one tap away: they are what is signed.
  bool _showBytes = false;

  /// The business address's five boxes, composed into its one-line answer.
  final Map<String, Map<String, TextEditingController>> _addresses = {};

  /// The onboarding's own vocabulary where it sent one, and otherwise whatever
  /// the screen around this was able to fetch.
  ///
  /// This way round because the payload's copy is the one that describes THESE
  /// questions: a pinned version can be older than the catalogue, and the
  /// employee has no catalogue at all. The fallback exists so an employer's
  /// form still reads properly against a server that has not started sending
  /// the block yet.
  ContractVocabulary get _vocabulary =>
      _form?.vocabulary.isUsable == true ? _form!.vocabulary : widget.vocabulary;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final box in _boxes.values) {
      box.dispose();
    }
    _newItem.dispose();
    for (final parts in _addresses.values) {
      for (final box in parts.values) {
        box.dispose();
      }
    }
    _previewTimer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get(
      '/hr/onboardings/${widget.onboardingId}',
    );

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.reason;
        return;
      }

      _adopt(response);
    });

    await _loadDocument();

    if (mounted && (_form?.anyWritable ?? false)) await _preview();
  }

  /// The pending answers as they go out. A question answered in parts goes
  /// as its parts, under its own key, so `state` here never meets the
  /// contract's governing `state`.
  Map<String, dynamic> _answersOut() => {
    for (final MapEntry(:key, :value) in _pending.entries)
      key: switch (_form == null ? null : _question(_form!, key)?.parts) {
        _? => {
          for (final MapEntry(key: box, value: part) in _addressParts.entries)
            part: _addresses[key]?[box]?.text.trim() ?? '',
        },
        null => value,
      },
  };

  /// One answer changed: queue it for Save and redraw the contract beside it.
  ///
  /// The saved value again is no change: a date box re-reports its value when
  /// it loses focus, and that left "1 answer not saved" behind every save,
  /// blocking Sign until the form was opened again (Jose, 2026-10-06).
  void _answer(String key, String value) {
    final form = _form;
    final question = form == null ? null : _question(form, key);
    setState(() {
      if (question != null && !question.masked && value == question.value) {
        _pending.remove(key);
      } else {
        _pending[key] = value;
      }
      _changed.add(key);
    });
    _previewTimer?.cancel();
    _previewTimer = Timer(const Duration(milliseconds: 600), _preview);
  }

  Future<void> _preview() async {
    final run = ++_previewRun;
    setState(() => _previewing = true);

    final path = '/hr/onboardings/${widget.onboardingId}/preview';
    final body = {'answers': _answersOut(), 'highlight': _changed.toList()};

    if (_asPdf) {
      final pdf = await widget.api.postBytesResponse(path, body, {
        'format': 'pdf',
      });
      if (!mounted || run != _previewRun) return;
      // A file says it is a PDF in its first bytes. A server from before the
      // PDF view answers this with the JSON preview, which is not one.
      final bytes = pdf.bytes;
      final isPdf = bytes != null &&
          bytes.length > 4 &&
          String.fromCharCodes(bytes.take(4)) == '%PDF';
      setState(() {
        _previewing = false;
        _previewError = !pdf.ok
            ? pdf.message
            : isPdf
            ? null
            : 'The PDF view is not available yet.';
        if (isPdf) _pdf = Uint8List.fromList(bytes);
      });
      return;
    }

    final response = await widget.api.post(path, body);

    // A slow answer to an older set of answers must not land on a newer one.
    if (!mounted || run != _previewRun) return;

    final content = response.data is Map
        ? (response.data as Map)['content']
        : null;

    setState(() {
      _previewing = false;
      _previewError = response.ok ? null : response.reason;
      if (content is String) _previewHtml = content;
    });
  }

  /// The document, whenever the answers behind it may have moved.
  ///
  /// Skipped entirely when this contract asks for no signature: rendering a
  /// document nobody is going to sign is a request for nothing, and the section
  /// that would show it is not drawn either.
  Future<void> _loadDocument() async {
    if (_form?.signatures.isEmpty ?? true) return;

    final response = await widget.api.get(
      '/hr/onboardings/${widget.onboardingId}/document',
    );

    if (!mounted) return;

    setState(() {
      if (!response.ok) {
        // Beside the signing step rather than over the whole page: the form is
        // still perfectly readable and answerable without a document, and
        // blanking it would take away the very answers that produce one.
        _paper = null;
        _paperError =
            'The document could not be rendered, so there is nothing to sign '
            'against: ${response.reason}';
        return;
      }

      _paperError = null;
      _paper = response.data is Map
          ? OnboardingDocument.fromJson(
              Map<String, dynamic>.from(response.data as Map),
            )
          : null;
    });
  }

  /// Take a fresh copy of the form and bring the boxes into line with it.
  ///
  /// Called inside a setState by every path that receives one. A box whose key
  /// is still pending is left alone: that is somebody's unsaved typing, and the
  /// server's older value landing on top of it is the "it reloads for every
  /// change" complaint in its most destructive form.
  void _adopt(ApiResponse response) {
    if (response.data is! Map) return;

    final form = Onboarding.fromJson(
      Map<String, dynamic>.from(response.data as Map),
    );

    _form = form;

    for (final question in form.questions) {
      if (_pending.containsKey(question.key)) continue;

      _boxes[question.key]?.text = _startingText(question);
    }
  }

  /// What a box holds before anybody touches it.
  ///
  /// Empty for a masked answer, and that is the whole trick: there is then no
  /// state in which the mask could be sent back as though it were the value.
  /// The row says the stored answer is kept unless something new is typed.
  String _startingText(OnboardingQuestion question) {
    if (_revealed.containsKey(question.key)) return _revealed[question.key]!;
    if (question.voided) return '';

    return question.masked ? '' : question.value;
  }

  /// Answered "doesn't apply", saved or not.
  /// Whether this viewer may mark [question] as not applying now: their own
  /// term while they may still write it, or, for the employer, one of the
  /// employee's questions while the form is still open (HR 4c43b180, Jose:
  /// "the employer should also be able to null the employee side stuff too,
  /// like photo in uniform"). A finished record has nothing writable.
  bool _mayVoid(OnboardingQuestion question) =>
      question.voidable &&
      (question.writable ||
          (question.field.filledBy == filledByEmployee &&
              (_form?.anyWritable ?? false)));

  bool _voided(OnboardingQuestion question) =>
      question.voidable && _answerOf(question.key).trim() == notApplicable;

  /// HR 0c0884af, Jose: "some sections can be entirely voided. so they don't
  /// appear in the contract". On saves "N/A" and the clause leaves the
  /// preview; off clears the answer back to empty.
  void _setVoided(OnboardingQuestion question, bool voided) {
    for (final q in _form!.questions) {
      if (!_mayVoid(q) || q.group != question.group) continue;
      _box(q).clear();
      _addresses[q.key]?.values.forEach((box) => box.clear());
      _answer(q.key, voided ? notApplicable : '');
    }
  }

  /// ✓ applies, ✗ does not (Jose: "buttons next to the field with like a
  /// tick or x ... clicking the tick re enables it"). The chosen one is
  /// filled; ✗ voids the term and the terms grouped with it.
  Widget _applies(OnboardingQuestion question, bool voided) {
    final enabled = _mayVoid(question) && !_saving;
    // The chosen side stays lit and does nothing; the other one changes it.
    Widget button(bool on, IconData icon, String tip, String key, bool to) {
      final keyed = Key('$key:${question.key}');
      final child = Icon(icon, size: 18);
      return on
          ? IconButton.filled(
              key: keyed,
              tooltip: tip,
              visualDensity: VisualDensity.compact,
              onPressed: enabled ? () {} : null,
              icon: child,
            )
          : IconButton.outlined(
              key: keyed,
              tooltip: tip,
              visualDensity: VisualDensity.compact,
              onPressed: enabled
                  ? () => _answerOf(question.key).trim() == toBeDetermined
                        ? _setTbd(question, false)
                        : _setVoided(question, to)
                  : null,
              icon: child,
            );
    }

    // "To be determined": the term stays and prints as those words (Jose,
    // 2026-10-06). Only the employer's own terms, while they may write them.
    final tbd = _answerOf(question.key).trim() == toBeDetermined;
    final mayTbd =
        question.writable && question.field.filledBy != filledByEmployee;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (mayTbd) ...[
          tbd
              ? FilledButton.tonal(
                  key: Key('tbd:${question.key}'),
                  style: FilledButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                  ),
                  onPressed: enabled ? () => _setTbd(question, false) : null,
                  child: const Text('TBD'),
                )
              : OutlinedButton(
                  key: Key('tbd:${question.key}'),
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                  ),
                  onPressed: enabled ? () => _setTbd(question, true) : null,
                  child: const Text('TBD'),
                ),
          const SizedBox(width: 4),
        ],
        button(!voided && !tbd, Icons.check, 'This applies', 'apply', false),
        const SizedBox(width: 4),
        button(voided, Icons.close, "Doesn't apply", 'void', true),
      ],
    );
  }

  void _setTbd(OnboardingQuestion question, bool on) {
    _box(question).text = on ? toBeDetermined : '';
    _answer(question.key, on ? toBeDetermined : '');
  }

  TextEditingController _box(OnboardingQuestion question) => _boxes.putIfAbsent(
    question.key,
    () => TextEditingController(text: _startingText(question)),
  );

  // ── saving ───────────────────────────────────────────────────────

  Future<void> _save() async {
    if (_pending.isEmpty) return;

    setState(() {
      _saving = true;
      _actionError = null;
    });

    final response = await widget.api.patch(
      '/hr/onboardings/${widget.onboardingId}',
      {'answers': _answersOut()},
    );

    if (!mounted) return;

    setState(() {
      _saving = false;

      // Kept, not cleared. A refusal that emptied the pending map would throw
      // away what somebody typed while leaving the boxes showing it, so the
      // next Save would send nothing and report success over a form that was
      // never written.
      if (!response.ok) {
        _actionError = response.reason;
        return;
      }

      _pending.clear();
      _savedAt = DateTime.now();

      // The answer to the save IS the new form: provenance, masks, and what is
      // still missing.
      _adopt(response);
    });

    // The answers moved, so a preview rendered from them is out of date — and a
    // signature carries the fingerprint of what was on screen. Refetched rather
    // than left, because the stale one would be refused at the moment somebody
    // pressed Sign, which is the worst possible time to find out.
    await _loadDocument();

    widget.onChanged?.call();
  }

  // ── revealing one sensitive answer ───────────────────────────────

  /// One field, one request, one audit entry.
  ///
  /// There is deliberately no "show everything" here, mirroring the server:
  /// `SensitiveValues` refuses to offer a bulk reveal because one audit entry
  /// covering an unknown number of disclosures cannot answer which field
  /// somebody actually looked at, and that is the only question the log is
  /// being kept for.
  Future<void> _reveal(OnboardingQuestion question) async {
    setState(() {
      _revealing = question.key;
      _actionError = null;
    });

    // A GET, because that is the endpoint — and it is a read with a side
    // effect on purpose, the same shape as `/invitations/lookup`: ASKING is the
    // reveal. Every other read of this record answers the mask, so each entry
    // in the log means somebody deliberately looked rather than that a screen
    // was opened.
    final response = await widget.api.get(
      '/hr/onboardings/${widget.onboardingId}/answers/${question.key}',
    );

    if (!mounted) return;

    setState(() {
      _revealing = null;

      if (!response.ok) {
        _actionError = response.reason;
        return;
      }

      final value = response.data is Map
          ? (response.data as Map)['value']?.toString() ?? ''
          : '';

      _revealed[question.key] = value;

      // Put it in the box so it can be corrected, and leave it OUT of
      // [_pending]: reading a value is not changing it, and a reveal that
      // queued a write would rewrite the provenance of an answer somebody only
      // looked at.
      if (question.writable) _box(question).text = value;
    });
  }

  // ── signing ──────────────────────────────────────────────────────

  Future<void> _sign(
    String fieldKey,
    String affirmation,
    String drawing,
    String documentHash,
  ) async {
    setState(() {
      _signingKey = fieldKey;
      _signErrorKey = null;
      _signError = null;
    });

    final response = await widget.api
        .post('/hr/onboardings/${widget.onboardingId}/sign', {
          'field_key': fieldKey,
          // Both halves ESIGN asks for, sent as two facts rather than one.
          // Consent to transact electronically is a separate agreement from the
          // contract, which is why the signature record timestamps it
          // separately — so a server that received an affirmation with no
          // consent must be able to tell, and refuse.
          'consent': true,
          'affirmation': affirmation,
          // The signature drawn by hand, as A1 Tools took it: a PNG data URL,
          // drawn into the signature block of the PDF.
          'drawing': drawing,
          // The fingerprint of the document this screen actually displayed,
          // sent back rather than recomputed anywhere. The server refuses
          // without it: it is what makes the signature evidence of what the
          // person read rather than of what the server assumed they read, and
          // a document that has moved since it was fetched is a refusal instead
          // of a signature over text nobody saw.
          'document_hash': documentHash,
        });

    if (!mounted) return;

    setState(() {
      _signingKey = null;

      if (!response.ok) {
        _signErrorKey = fieldKey;
        _signError = response.reason;
        return;
      }

      _adopt(response);
    });

    // Signing freezes the document, so the preview this screen was holding is
    // now the stored artefact and says so.
    await _loadDocument();

    widget.onChanged?.call();
  }

  /// Both sides signed; the sender's final approval finishes it and opens
  /// the app to the new starter (Jose, 2026-10-05).
  Future<void> _approve() async {
    setState(() {
      _approving = true;
      _actionError = null;
    });

    final response = await widget.api.post(
      '/hr/onboardings/${widget.onboardingId}/approve',
      const {},
    );

    if (!mounted) return;
    setState(() {
      _approving = false;
      if (!response.ok) {
        _actionError = response.reason;
        return;
      }
      _adopt(response);
    });

    widget.onChanged?.call();
  }

  Widget _approvalNote(BuildContext context, Onboarding form) {
    final theme = Theme.of(context);
    final who = form.personName.isEmpty ? 'the new starter' : form.personName;
    final by = form.approvalBy ?? 'whoever sent it';

    return Container(
      key: const Key('onboarding-approval'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.tertiaryContainer.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            form.approvalMine
                ? 'Signed by both sides. Approve it to finish, and the rest of '
                      'the app opens to $who.'
                : 'Signed by both sides. Waiting for $by to approve it.',
            style: theme.textTheme.bodyMedium,
          ),
          if (form.approvalMine) ...[
            const SizedBox(height: 12),
            FilledButton.icon(
              key: const Key('onboarding-approve'),
              onPressed: _approving ? null : _approve,
              icon: const Icon(Icons.verified_outlined, size: 18),
              label: const Text('Approve'),
            ),
          ],
          if (_actionError != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_actionError!),
          ],
        ],
      ),
    );
  }

  // ── drawing it ───────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: () {
        setState(() {
          _loading = true;
          _error = null;
        });
        _load();
      },
      child: _form == null ? const SizedBox.shrink() : _page(context, _form!),
    );
  }

  Widget _page(BuildContext context, Onboarding form) {
    final theme = Theme.of(context);

    // Drawn by the ceremony below rather than as ordinary boxes. Which fields
    // those are comes from the server naming them, not from this end deciding
    // that a kind called "signature" is special.
    final signatureKeys = {
      for (final signature in form.signatures) signature.fieldKey,
    };

    // A retired question is asked of nobody; it shows only where an earlier
    // answer is on record (pay basis, retired by the standard pay terms).
    final questions = [
      for (final question in form.questions)
        if (!signatureKeys.contains(question.key) &&
            _isShown(form, question) &&
            !(question.field.retired && !question.answered))
          question,
    ];

    // The schedule answers both of these with one control, drawn on the
    // hours; the days row would be the same control twice.
    final rows = [
      for (final question in questions)
        if (question.key != 'work_days' || _question(form, 'work_hours') == null)
          question,
    ];

    // The state comes first, so the county can be searched in it (Jose,
    // 2026-10-01: "state should be first, before country for disputes").
    final state = rows.indexWhere((q) => q.key == 'state');
    final county = rows.indexWhere((q) => q.key == 'county');
    if (state > county && county >= 0) {
      rows.insert(county, rows.removeAt(state));
    }

    final mine = [
      for (final question in rows)
        if (question.writable) question,
    ];

    final theirs = [
      for (final question in rows)
        if (!question.writable) question,
    ];

    return LayoutBuilder(
      builder: (context, box) {
        // The form and, beside it, the contract it is filling in. Under this
        // width the two take turns behind a Form / Contract switch.
        final split = box.maxWidth >= 980;

        final head = <Widget>[
          if (widget.onBack != null)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: widget.onBack,
                icon: const Icon(Icons.arrow_back, size: 18),
                label: Text(widget.backLabel),
              ),
            ),
          _heading(context, form),
          const SizedBox(height: 18),
        ];

        final body = <Widget>[
          _resumeNote(context, form, questions),
          if (form.awaitingApproval) ...[
            const SizedBox(height: 16),
            _approvalNote(context, form),
          ],
          // Only when there is no Save bar to carry it. A refused save
          // belongs beside the button that was pressed; a refused reveal
          // on a record nobody may write has no button to sit beside, and
          // used to have nowhere at all.
          if (_actionError != null && mine.isEmpty) ...[
            const SizedBox(height: 16),
            ErrorNote(_actionError!),
          ],
          if (mine.isNotEmpty) ...[
            const SizedBox(height: 28),
            _sectionTitle(context, 'Yours to fill in'),
            if (form.employerAddressEditable) _employerAddress(context, form),
            for (final question in mine) _row(context, question),
            const SizedBox(height: 8),
            _saveBar(context),
          ],
          if (theirs.isNotEmpty) ...[
            const SizedBox(height: 28),
            _sectionTitle(
              context,
              mine.isEmpty ? 'The answers' : 'Not yours to answer',
            ),
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                mine.isEmpty
                    ? 'Nothing here can be changed from this screen.'
                    : 'Here so you can see what is still outstanding on '
                          'the other side. You cannot change these.',
                style: theme.textTheme.bodySmall,
              ),
            ),
            for (final question in theirs) _row(context, question),
          ],
          // A contract can genuinely ask nothing of its own — a one-page
          // agreement with a signature and no fields is a real thing — so
          // this is stated rather than left as a page that stops after
          // the heading and reads as a screen that failed to load.
          if (questions.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 28),
              child: Text(
                form.signatures.isEmpty
                    ? 'This contract asks no questions and has nothing to '
                          'sign. There is nothing to fill in here.'
                    : 'This contract asks no questions. There is only the '
                          'document below to agree to.',
                style: theme.textTheme.bodyMedium,
              ),
            ),
          if (form.signatures.isNotEmpty) ...[
            const SizedBox(height: 28),
            _signingSection(context, form, split: split),
          ],
          if (widget.aside case final aside?) ...[
            const SizedBox(height: 28),
            aside,
          ],
        ];

        Widget column(List<Widget> children, double width) => Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: width),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: children,
            ),
          ),
        );

        if (split) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                flex: 11,
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(24, 20, 24, 40),
                  children: [
                    column([...head, ...body], 680),
                  ],
                ),
              ),
              Expanded(
                flex: 10,
                child: ColoredBox(
                  color: theme.colorScheme.surfaceContainerLow,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(24, 20, 24, 40),
                    children: [
                      column([
                        _contractHeader(context),
                        const SizedBox(height: 12),
                        _contract(context),
                      ], 820),
                    ],
                  ),
                ),
              ),
            ],
          );
        }

        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
          children: [
            column([
              ...head,
              TabBarLike(
                level: TabLevel.tertiary,
                key: const Key('onboarding-view'),
                labels: const [
                  ('Form', Icons.edit_note_outlined),
                  ('Contract', Icons.description_outlined),
                ],
                selected: _showContract ? 1 : 0,
                onSelected: (i) => setState(() => _showContract = i == 1),
              ),
              const SizedBox(height: 16),
              if (_showContract) ...[
                _contractHeader(context),
                const SizedBox(height: 12),
                _contract(context),
              ] else
                ...body,
            ], 760),
          ],
        );
      },
    );
  }

  OnboardingQuestion? _question(Onboarding form, String key) {
    for (final question in form.questions) {
      if (question.key == key) return question;
    }
    return null;
  }

  // ── the contract beside the form ─────────────────────────────────

  /// What the contract pane shows: the stored document once one is frozen;
  /// otherwise the live preview, or the document until a preview arrives.
  String? get _contractHtml {
    final paper = _paper;
    if (paper != null && !paper.isPreview) return paper.content;
    return _previewHtml ?? paper?.content;
  }

  Widget _contractHeader(BuildContext context) {
    final theme = Theme.of(context);
    final frozen =
        (_paper != null && !_paper!.isPreview) ||
        (_form?.downloadable ?? false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: _sectionTitle(context, 'The contract')),
            // The signed copy as a PDF: the contract, every signature drawn
            // in, and the pictures they uploaded at the back (Mia,
            // 2026-10-02: Eve could not download a finished one).
            if (frozen)
              Padding(
                padding: const EdgeInsets.only(bottom: 10, right: 8),
                child: TextButton.icon(
                  key: const Key('onboarding-download-pdf'),
                  onPressed: _gettingPdf ? null : _signedPdf,
                  icon: const Icon(Icons.download_outlined, size: 18),
                  label: Text(_gettingPdf ? 'Preparing…' : 'Download PDF'),
                ),
              ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text(
                  frozen
                      ? 'As it was signed'
                      : _previewing
                      ? 'Updating…'
                      : 'Changes as the answers do',
                  textAlign: TextAlign.end,
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ),
            if (!frozen) ...[
              const SizedBox(width: 12),
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: SegmentedButton<bool>(
                  key: const Key('contract-view'),
                  segments: const [
                    ButtonSegment(value: false, label: Text('Live')),
                    ButtonSegment(value: true, label: Text('PDF')),
                  ],
                  selected: {_asPdf},
                  showSelectedIcon: false,
                  style: const ButtonStyle(
                    visualDensity: VisualDensity.compact,
                  ),
                  onSelectionChanged: (picked) {
                    setState(() => _asPdf = picked.first);
                    _preview();
                  },
                ),
              ),
            ],
          ],
        ),
        if (_previewError != null && !frozen)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: ErrorNote(
              'The contract could not be redrawn with the latest answers: '
              '$_previewError',
            ),
          ),
      ],
    );
  }

  Widget _contract(BuildContext context) {
    final theme = Theme.of(context);
    final content = _contractHtml;
    final frozen = _paper != null && !_paper!.isPreview;
    final pdf = _asPdf && !frozen;
    final note = theme.textTheme.bodyMedium?.copyWith(
      color: EzBiziTheme.paperMuted,
    );

    return Container(
      key: const Key('onboarding-contract'),
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        // The live text is paper in both themes, as the PDF's pages are: its
        // stylesheet is black ink on white. The PDF brings its own paper.
        color: pdf ? theme.colorScheme.surface : EzBiziTheme.paper,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: pdf
          ? (_pdf == null
                ? Text(
                    _previewing ? 'Drawing the PDF…' : 'The PDF will show here.',
                    style: theme.textTheme.bodyMedium,
                  )
                : PdfView(bytes: _pdf!))
          : content == null || content.isEmpty
          ? Text(
              _paperError ??
                  (_previewing
                      ? 'Drawing the contract…'
                      : (_form?.downloadable ?? false)
                      ? 'Signed before EzBizi. Download PDF to read it.'
                      : 'The contract will show here.'),
              style: note,
            )
          // Rendered, as it prints. The exact bytes a signature is over are
          // under "Show the exact text" by the signing step.
          : Html(
              data: contractOnScreen(content),
              style: {
                // The page's own margins are for paper; this box has its own.
                'body': Style(
                  margin: Margins.zero,
                  padding: HtmlPaddings.zero,
                  color: EzBiziTheme.paperInk,
                  fontSize: FontSize(15),
                ),
              },
            ),
    );
  }

  Widget _heading(BuildContext context, Onboarding form) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Flexible(
              child: Text(
                form.contractName.isEmpty ? 'Your paperwork' : form.contractName,
                style: theme.textTheme.headlineMedium,
              ),
            ),
            // The version is PINNED. Saying which one is not trivia: publishing
            // a new version of the template does not move this form, so the
            // number here is the answer to "why am I not being asked the
            // question my colleague was asked".
            if (form.version != null) Tag('Version ${form.version}'),
            if (form.status.isNotEmpty) Tag(onboardingStatusLabel(form.status)),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          [
            if (form.personName.isNotEmpty) form.personName,
            if (form.personEmail.isNotEmpty) form.personEmail,
            _when(form),
          ].where((part) => part.isNotEmpty).join(' · '),
          style: theme.textTheme.bodyMedium,
        ),
      ],
    );
  }

  /// The furthest thing that has happened to it, with its date.
  ///
  /// The status tag says which state it is in; this says when it got there,
  /// which is the part a record read months later needs and a tag cannot carry.
  String _when(Onboarding form) {
    String at(String? iso) => formatStamp(iso, '', formats: widget.formats);

    if (form.completedAt != null) return 'Completed ${at(form.completedAt)}';
    if (form.submittedAt != null) return 'Submitted ${at(form.submittedAt)}';
    if (form.startedAt != null) return 'Started ${at(form.startedAt)}';

    return '';
  }

  /// The promise that makes a half-finished form worth starting.
  ///
  /// Somebody filling in a bank account and a licence number will stop halfway,
  /// and the thing that decides whether they come back is whether they believe
  /// the first half survived. So it says so, says how far they got, and says
  /// when the form was last written to — all three, because "your progress is
  /// saved" with no number behind it is what every form says and nobody trusts.
  /// [questions] is the list WITHOUT the signatures, which is what the count
  /// has to be over: a signature is not answered, it is signed, and counting it
  /// as an outstanding question would leave a completed form reading "6 of 7".
  Widget _resumeNote(
    BuildContext context,
    Onboarding form,
    List<OnboardingQuestion> questions,
  ) {
    final theme = Theme.of(context);
    final total = questions.length;
    final answered = questions.where((q) => q.answered).length;

    final last = _savedAt != null
        ? 'You saved at '
              '${widget.formats.formatTime(_savedAt!.hour, _savedAt!.minute)}.'
        : form.lastAnsweredAt != null
        ? 'Last answered '
              '${formatStamp(form.lastAnsweredAt, '', formats: widget.formats)}.'
        : 'Nothing answered yet.';

    return Container(
      key: const ValueKey('tour-hr-onboarding-progress'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.bookmark_outline,
            size: 20,
            color: theme.colorScheme.onSurfaceVariant,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '$answered of $total answered. $last',
                  style: theme.textTheme.titleSmall,
                ),
                const SizedBox(height: 4),
                Text(
                  form.anyWritable
                      ? 'You do not have to finish this in one go. Press Save '
                            'whenever you like and close the page — what you '
                            'have already answered stays, and this screen picks '
                            'up where you left off.'
                      : 'Nothing here is waiting on you. This is the record as '
                            'it stands.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _sectionTitle(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        letterSpacing: 1.1,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    ),
  );

  Widget _saveBar(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      key: const ValueKey('tour-hr-onboarding-save'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_actionError != null) ...[
          ErrorNote(_actionError!),
          const SizedBox(height: 12),
        ],
        Row(
          children: [
            FilledButton(
              onPressed: (_saving || _pending.isEmpty) ? null : _save,
              child: Text(_saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                _pending.isEmpty
                    ? 'Everything on this page is saved.'
                    : '${_pending.length} '
                          '${_pending.length == 1 ? 'answer' : 'answers'} not '
                          'saved yet.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ── one question ─────────────────────────────────────────────────

  Widget _row(BuildContext context, OnboardingQuestion question) {
    final theme = Theme.of(context);
    final field = question.field;
    final voided = _voided(question);

    // The schedule control answers the days too, so it carries both names.
    final days = field.key == 'work_hours'
        ? _question(_form!, 'work_days')
        : null;
    final label = days == null
        ? field.label
        : '${days.field.label} and ${field.label.toLowerCase()}';

    return Padding(
      padding: const EdgeInsets.only(bottom: 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Flexible(
                      child: Text(
                        field.required ? '$label *' : label,
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    // The one thing position cannot show: that the other side
                    // may have got there first, and that correcting them is
                    // expected.
                    if (field.filledBy == filledByEither)
                      const Tag('Either of you'),
                    if (field.sensitive) const Tag('Sensitive'),
                    // Whose question this is, in the server's word for it.
                    // Only where it is not this person's to answer — on their
                    // own half the heading has already said so.
                    if (!question.writable && field.filledBy.isNotEmpty)
                      Tag(_vocabulary.filledByLabel(field.filledBy)),
                  ],
                ),
              ),
              // At the box's right edge, level on every row.
              if (question.voidable && (_mayVoid(question) || voided))
                _applies(question, voided),
            ],
          ),
          const SizedBox(height: 6),
          // Greyed and still while the term does not apply: its clause is
          // out of the contract until ✓ brings it back.
          IgnorePointer(
            ignoring: voided,
            child: Opacity(
              opacity: voided ? 0.4 : 1,
              child: _control(context, question),
            ),
          ),
          // Why the box is dead, where the server said. A greyed control with
          // no reason beside it reads as a fault; this is the plan's one-home
          // rule showing itself — core identity belongs to the person, and an
          // employer does not get to edit somebody's date of birth here.
          if (!question.writable && question.lockedBecause != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                question.lockedBecause!,
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (question.writable && question.source == 'core')
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Part of your account rather than of this contract, so '
                'changing it here changes it everywhere.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (field.sensitive) _sensitiveNote(context, question),
          _provenance(context, question),
        ],
      ),
    );
  }

  /// Who answered it and when.
  ///
  /// Drawn for every answered contract question rather than only for `either`
  /// ones. On an `either` field it is the point — the owner's case is a date of
  /// birth the employer typed wrong, and the case that matters is a bank
  /// account, where "they typed it" and "I corrected it" are different facts.
  /// On the rest it costs one line and answers "has anybody dealt with this".
  ///
  /// The core questions carry none, and that is correct rather than missing:
  /// they live on the person's profile, and the profile's own history is where
  /// changes to them are recorded.
  Widget _provenance(BuildContext context, OnboardingQuestion question) {
    if (!question.answered) return const SizedBox.shrink();

    final when = formatStamp(question.setAt, '', formats: widget.formats);

    // The name, or the side they acted as when the account behind it is gone.
    // Never nothing: an answer that appeared from nowhere is the state this
    // whole mechanism exists to make impossible.
    final who =
        question.setByName ??
        (question.setByParty == null
            ? null
            : _vocabulary.filledByLabel(question.setByParty!));

    if (who == null && when.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Text(
        [
          if (who != null) 'Filled in by $who' else 'Filled in',
          if (when.isNotEmpty) when,
        ].join(' · '),
        style: Theme.of(context).textTheme.bodySmall,
      ),
    );
  }

  /// What "sensitive" means, said where somebody is deciding whether to type.
  ///
  /// This paragraph is the feature. A bank account gets typed into a form
  /// because the form said what would happen to it, and the four behaviours
  /// here are the same four the contract designer promises when the box is
  /// ticked — deliberately the same words, because a promise made on one screen
  /// and contradicted on the other is worse than neither.
  Widget _sensitiveNote(BuildContext context, OnboardingQuestion question) {
    final theme = Theme.of(context);
    final revealed = _revealed.containsKey(question.key);

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest.withValues(
            alpha: 0.5,
          ),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  Icons.lock_outline,
                  size: 16,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'This answer is encrypted where it is stored. Everybody who '
                    'opens this record sees it hidden — including the person '
                    'who set up the contract — until they ask to see it, and '
                    'every time somebody asks, it is recorded against their '
                    'name. It is left out of exports unless somebody asks for '
                    'it on purpose.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ),
            // Only where there is something stored to look at. A field nobody
            // has answered has nothing to reveal, and a Reveal button over an
            // empty answer is a control that can only produce an error.
            if (question.masked) ...[
              const SizedBox(height: 10),
              if (revealed)
                // Only where the box above cannot hold it. A writable field
                // gets the revealed value put straight into its control so it
                // can be corrected, and printing it a second time here would be
                // the same secret on screen twice for no reason.
                question.writable
                    ? Text(
                        'Shown in the box above. That you looked has been '
                        'recorded against your name.',
                        style: theme.textTheme.bodySmall,
                      )
                    : SelectableText(
                        _revealed[question.key]!,
                        style: theme.textTheme.bodyMedium,
                      )
              else ...[
                Row(
                  children: [
                    Text(question.value, style: theme.textTheme.bodyMedium),
                    const SizedBox(width: 12),
                    // An action, drawn as one, with what it costs beside it.
                    // Not an eye icon on the end of a text box: that reads as a
                    // display toggle, and this writes a row to the audit log
                    // with somebody's name on it.
                    OutlinedButton.icon(
                      onPressed: _revealing == null
                          ? () => _reveal(question)
                          : null,
                      icon: const Icon(Icons.visibility_outlined, size: 16),
                      label: Text(
                        _revealing == question.key ? 'Revealing…' : 'Reveal',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  'Revealing it is recorded against your name.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  /// The live control for a kind of question.
  ///
  /// The kind, its label and its options are all the server's; which widget
  /// draws it is code, for the reason contract_form_preview.dart states — a
  /// server cannot send a widget. A kind this build has never met gets a plain
  /// box and says so, which keeps a newer server's question answerable instead
  /// of silently missing from a form somebody is being asked to complete.
  Widget _control(BuildContext context, OnboardingQuestion question) {
    final theme = Theme.of(context);
    final field = question.field;
    final enabled = question.writable && !_saving;

    if (question.voidable && _answerOf(question.key).trim() == toBeDetermined) {
      return InputDecorator(
        key: answerKey(field.key),
        decoration: const InputDecoration(border: OutlineInputBorder()),
        child: Text(toBeDetermined, style: theme.textTheme.bodyMedium),
      );
    }

    // Only claimed when there IS a vocabulary to check against. Without one
    // this end cannot tell an unrecognised kind of question from an ordinary
    // one, and captioning every text box with a warning would be asserting an
    // ignorance it has not established.
    switch (field.key) {
      case 'state':
        return _statePicker(question, enabled: enabled);
      // Eve was unsure what it means (Jose, 2026-10-06): the usual answers
      // to tap, and the box still takes any words.
      case 'comp_method':
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _text(question, enabled: enabled),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final method in compensationMethods)
                  ActionChip(
                    key: Key('comp-method-$method'),
                    label: Text(method),
                    onPressed: enabled
                        ? () {
                            _box(question).text = method;
                            _answer(question.key, method);
                          }
                        : null,
                  ),
              ],
            ),
          ],
        );
      case 'county':
        final state = stateName(_answerOf('state'));
        return _CountySearch(
          key: answerKey(field.key),
          api: widget.api,
          state: state,
          controller: _box(question),
          answer: _pending[question.key] ?? question.value,
          enabled: enabled,
          onPicked: (county) => _answer(question.key, county),
        );
      // The home address in its parts once the server sends them (Jose: "like
      // in the rest of the app should have separate fields").
      case 'employer_address' || 'address'
          when field.key == 'employer_address' || question.parts != null:
        return AddressFields(
          key: answerKey(field.key),
          api: widget.api,
          enabled: enabled,
          dense: false,
          line1: _address(question)['line1']!,
          line2: _address(question)['line2']!,
          city: _address(question)['city']!,
          state: _address(question)['state']!,
          postal: _address(question)['postal']!,
          country: _address(question)['country'],
        );
      case 'work_hours':
        return _schedule(context, question, enabled: enabled);
      case 'tax_form' || 'direct_deposit_form' || 'i9_form'
          when question.signsTaxFormHere:
        return _taxForm(context, question);
      case 'salary_period' || 'raise_period':
        final current = _chosen(question);
        final period = current == null
            ? null
            : _payPeriods.firstWhere(
                (p) => current.toLowerCase().contains(p.toLowerCase()),
                orElse: () => current,
              );
        return SearchPickerField<String>(
          key: answerKey(field.key),
          value: period,
          border: const OutlineInputBorder(),
          hint: 'Choose',
          options: [
            for (final p in _payPeriods) SearchOption(value: p, label: p),
            if (period != null && !_payPeriods.contains(period))
              SearchOption(value: period, label: '$period — not one of these'),
          ],
          onChanged: enabled
              ? (value) => _answer(question.key, value ?? '')
              : null,
        );
    }

    if (_vocabulary.isUsable && !_vocabulary.knowsType(field.type)) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _text(question, enabled: enabled),
          const SizedBox(height: 6),
          Text(
            'This build does not know how “${field.type}” is meant to be '
            'answered, so it is asked as plain text. Whatever the server '
            'expects, that is what it will check.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      );
    }

    return switch (field.type) {
      'checklist' => _checklist(question, enabled: enabled),
      'choice' when field.options.length <= 4 => Wrap(
        key: answerKey(field.key),
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final option in field.options)
            ChoiceChip(
              label: Text(option),
              selected: _chosen(question) == option,
              onSelected: enabled
                  ? (on) => _answer(question.key, on ? option : '')
                  : null,
            ),
        ],
      ),
      'choice' => SearchPickerField<String>(
                    key: answerKey(field.key),
                    value: _chosen(question),
                    border: OutlineInputBorder(),
                    options: [
          for (final option in field.options)
            SearchOption(value: option, label: option),
          if (_chosen(question) case final current?)
            if (!field.options.contains(current))
              SearchOption(value: current, label: '$current — no longer offered'),
        ],
                    onChanged: enabled
            ? (value) => _answer(question.key, value ?? '')
            : null,
                  ),
      'file' => _fileControl(context, question),
      'date' => CalendarDayField(
        key: answerKey(field.key),
        controller: _box(question),
        formats: widget.formats,
        enabled: enabled,
        onChanged: enabled ? (value) => _answer(question.key, value) : null,
      ),
      // A signature reaching this point is one the ceremony below does not
      // cover: [_page] lifts out every question the server named in `signing`,
      // so anything of this kind still here has no document behind it. Drawing
      // a text box would invite somebody to type their name into a field that
      // signs nothing.
      'signature' => Text(
        'This question is a signature, and there is nothing on this page to '
        'sign — the document, and the wording it would be signed under, did '
        'not arrive with the form.',
        style: theme.textTheme.bodyMedium,
      ),
      _ => _text(
        question,
        enabled: enabled,
        numeric: field.type == 'number',
      ),
    };
  }

  /// Tools and equipment the way A1 Tools asked the technician (Jose,
  /// 2026-10-05: "look at how a1tools did it", "less extensive"): tick what
  /// you received, a quantity appears beside it, and anything not on the list
  /// can be added. Received is the day it was ticked.
  Widget _checklist(OnboardingQuestion question, {required bool enabled}) {
    final theme = Theme.of(context);
    final rows = _checklistRows(question);
    final options = question.field.options;
    final items = [
      ...options,
      for (final item in rows.keys)
        if (!options.contains(item)) item,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final item in items)
          SizedBox(
            height: 40,
            child: Row(
              children: [
                Checkbox(
                  key: Key('checklist-${question.key}-$item'),
                  value: rows.containsKey(item),
                  onChanged: enabled
                      ? (on) => _setChecklistQuantity(
                          question,
                          item,
                          on == true ? 1 : 0,
                        )
                      : null,
                ),
                Expanded(child: Text(item, overflow: TextOverflow.ellipsis)),
                if (rows[item] case final row?) ...[
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    tooltip: 'One fewer',
                    icon: const Icon(Icons.remove, size: 18),
                    onPressed: enabled
                        ? () => _setChecklistQuantity(
                            question,
                            item,
                            (_quantityOf(row) - 1).clamp(1, 999),
                          )
                        : null,
                  ),
                  SizedBox(
                    width: 28,
                    child: Text(
                      '${_quantityOf(row)}',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    tooltip: 'One more',
                    icon: const Icon(Icons.add, size: 18),
                    onPressed: enabled
                        ? () => _setChecklistQuantity(
                            question,
                            item,
                            (_quantityOf(row) + 1).clamp(1, 999),
                          )
                        : null,
                  ),
                ],
              ],
            ),
          ),
        if (enabled && _addingTo == question.key)
          Row(
            children: [
              Expanded(
                child: TextField(
                  key: const Key('checklist-add-name'),
                  controller: _newItem,
                  autofocus: true,
                  maxLength: 190,
                  decoration: const InputDecoration(
                    labelText: 'Name',
                    isDense: true,
                    counterText: '',
                  ),
                  onSubmitted: (_) => _addChecklistItem(question),
                ),
              ),
              TextButton(
                onPressed: () => _addChecklistItem(question),
                child: const Text('Add'),
              ),
            ],
          )
        else if (enabled)
          TextButton.icon(
            key: Key('checklist-add-${question.key}'),
            onPressed: () => setState(() => _addingTo = question.key),
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add one not listed'),
          ),
      ],
    );
  }

  int _quantityOf(Map<String, String> row) =>
      int.tryParse(row['quantity'] ?? '') ?? 1;

  /// The checklist whose "not listed" name box is open, inline (no
  /// dialogs: theme_conventions_test).
  String? _addingTo;
  final TextEditingController _newItem = TextEditingController();

  void _addChecklistItem(OnboardingQuestion question) {
    final item = _newItem.text.trim();
    setState(() => _addingTo = null);
    _newItem.clear();
    if (item.isEmpty) return;
    _setChecklistQuantity(question, item, 1);
  }

  /// Only received items are stored; the document prints every listed item,
  /// the unreceived ones blank.
  Map<String, Map<String, String>> _checklistRows(OnboardingQuestion question) {
    final raw = _pending[question.key] ?? question.value;
    if (raw.isEmpty) return {};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return {
          for (final row in decoded.whereType<Map>())
            if (row['item'] is String &&
                ('${row['quantity'] ?? ''}'.isNotEmpty ||
                    '${row['received'] ?? ''}'.isNotEmpty))
              row['item'] as String: {
                'quantity': '${row['quantity'] ?? ''}',
                'received': '${row['received'] ?? ''}',
              },
        };
      }
    } catch (_) {}
    return {};
  }

  /// Zero un-ticks it.
  void _setChecklistQuantity(
    OnboardingQuestion question,
    String item,
    int quantity,
  ) {
    final rows = _checklistRows(question);
    if (quantity <= 0) {
      rows.remove(item);
    } else {
      final now = DateTime.now();
      final today =
          '${now.year.toString().padLeft(4, '0')}-'
          '${now.month.toString().padLeft(2, '0')}-'
          '${now.day.toString().padLeft(2, '0')}';
      final received = rows[item]?['received'] ?? '';
      rows[item] = {
        'quantity': '$quantity',
        'received': received.isEmpty ? today : received,
      };
    }
    _answer(
      question.key,
      jsonEncode([
        for (final MapEntry(key: item, value: row) in rows.entries)
          {'item': item, 'quantity': row['quantity'], 'received': row['received']},
      ]),
    );
  }

  bool _isShown(Onboarding form, OnboardingQuestion question) {
    final when = question.field.shownWhen;
    if (when == null) return true;
    final raw = _pending[when.key] ?? _valueOf(form, when.key);
    // The choice does not apply or is undecided: what hangs on it stays in
    // sight, each to answer or turn off on its own (Jose, 2026-10-06).
    if (raw.trim() == notApplicable || raw.trim() == toBeDetermined) {
      return true;
    }
    return optionSlug(raw) == when.option;
  }

  String _valueOf(Onboarding form, String key) {
    for (final question in form.questions) {
      if (question.key == key) return question.value;
    }
    return '';
  }

  String? _chosen(OnboardingQuestion question) {
    if (_voided(question)) return null;
    if (_pending[question.key] case final pending?) {
      return pending.isEmpty ? null : pending;
    }

    return question.value.isEmpty ? null : question.value;
  }

  Widget _text(
    OnboardingQuestion question, {
    required bool enabled,
    String? helper,
    bool numeric = false,
  }) {
    return TextField(
      key: answerKey(question.key),
      controller: _box(question),
      enabled: enabled,
      keyboardType: numeric
          ? const TextInputType.numberWithOptions(decimal: true)
          : null,
      inputFormatters: numeric ? _decimal : null,
      decoration: InputDecoration(
        border: const OutlineInputBorder(),
        // What an empty box MEANS when there is a stored answer behind it.
        // Without this sentence a masked field reads as unanswered, and the
        // obvious response to that is to type it again.
        //
        // The mask itself is NOT put in here as a hint. It is already printed
        // once, in the panel below, next to the button that reveals it — and
        // the same eight dots twice on one question invites the reader to work
        // out which of the two is the real box.
        helperMaxLines: 3,
        helperText: question.masked
            ? 'Already answered — it is below. Leave this blank to keep it as '
                  'it is, or type a new answer to replace it.'
            : helper,
        // An answer stored before numbers were filtered ("20sdsadsa").
        errorText:
            numeric &&
                !_voided(question) &&
                !RegExp(r'^\d*\.?\d*$').hasMatch(_box(question).text.trim())
            ? 'Not a number: digits and one decimal point only.'
            : null,
      ),
      onChanged: (value) => _answer(question.key, value),
    );
  }

  /// The answer as it stands on screen: unsaved first, then saved.
  String _answerOf(String key) {
    if (_pending[key] case final pending?) return pending;
    final form = _form;
    return form == null ? '' : _valueOf(form, key);
  }

  /// US states by full name; an older "FL" reads as Florida. Changing it
  /// empties the county, which belongs to the state it was found in.
  Widget _statePicker(OnboardingQuestion question, {required bool enabled}) {
    final raw = _chosen(question);
    final name = raw == null ? null : stateName(raw) ?? raw;

    return SearchPickerField<String>(
      key: answerKey(question.key),
      value: name,
      border: const OutlineInputBorder(),
      hint: 'Choose a state',
      options: [
        for (final state in usStates.values)
          SearchOption(value: state, label: state),
        if (name != null && !usStates.containsValue(name))
          SearchOption(value: name, label: '$name — not a US state'),
      ],
      onChanged: enabled
          ? (value) {
              if (value == null || value == name) return;
              _answer(question.key, value);
              final county = _question(_form!, 'county');
              if (county != null &&
                  county.writable &&
                  _answerOf('county').isNotEmpty) {
                _box(county).clear();
                _answer(county.key, '');
              }
            }
          : null,
    );
  }

  /// The boxes of an address answer, made once. One the server sends in
  /// parts fills each box from its part, and adds the country; a one-line
  /// answer starts in the street box.
  Map<String, TextEditingController> _address(OnboardingQuestion question) =>
      _addresses.putIfAbsent(question.key, () {
        final given = question.parts;
        final parts = {
          for (final MapEntry(key: box, value: part) in _addressParts.entries)
            if (box != 'country' || given != null)
              box: TextEditingController(text: given?[part] ?? ''),
        };
        if (given == null) {
          parts['line1']!.text = question.masked ? '' : question.value;
        }

        String line() {
          String part(String key) => parts[key]?.text.trim() ?? '';
          final region = [
            part('state'),
            part('postal'),
          ].where((s) => s.isNotEmpty).join(' ');
          return [
            part('line1'),
            part('line2'),
            part('city'),
            region,
            part('country'),
          ].where((s) => s.isNotEmpty).join(', ');
        }

        // A controller also notifies for the cursor; only a new line is an
        // answer. Compared with the last line drawn, not with the stored
        // value: the server composes that one its own way.
        var last = line();
        for (final box in parts.values) {
          box.addListener(() {
            if (line() == last) return;
            last = line();
            _answer(question.key, last);
          });
        }
        return parts;
      });

  /// The company address the contract prints, typed when the company's own
  /// is not set or not right (Jose, 2026-10-05: "if it's typed it overrides
  /// whatever is in place"). Optional: empty, the company's own is used.
  Widget _employerAddress(BuildContext context, Onboarding form) {
    final theme = Theme.of(context);
    final fallback = form.employerAddressDefault?.trim() ?? '';
    final box = _boxes.putIfAbsent(
      employerAddressKey,
      () => TextEditingController(text: form.employerAddress ?? ''),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Company address (optional)', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          TextField(
            key: const Key('onboarding-employer-address'),
            controller: box,
            enabled: !_saving,
            decoration: InputDecoration(
              border: const OutlineInputBorder(),
              hintText: fallback.isEmpty ? 'No company address is set' : fallback,
              helperText:
                  'Typed here, it replaces the company address on this contract.',
            ),
            onChanged: (value) => _answer(employerAddressKey, value),
          ),
        ],
      ),
    );
  }

  /// Work days and work hours, one control: the week, set in the schedule
  /// panel and written back as the two answers [scheduleText] describes.
  Widget _schedule(
    BuildContext context,
    OnboardingQuestion hours, {
    required bool enabled,
  }) {
    final theme = Theme.of(context);
    final days = _question(_form!, 'work_days');
    final text = _voided(hours) ? '' : _answerOf(hours.key).trim();
    final week = text.isEmpty ? null : scheduleFrom(text);
    final typed = _typedSchedule ?? (text.isNotEmpty && week == null);

    // Switching empties both answers, so a contract never carries a set
    // schedule and typed availability at once. Typed, the days answer is
    // "doesn't apply" and its line leaves the contract.
    void choose(bool toTyped) {
      if (toTyped == typed) return;
      setState(() => _typedSchedule = toTyped);
      _box(hours).text = '';
      _answer(hours.key, '');
      if (days != null && days.writable) {
        _answer(days.key, toTyped ? notApplicable : '');
      }
    }

    return Container(
      key: answerKey(hours.key),
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SegmentedButton<bool>(
            key: const Key('onboarding-schedule-mode'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: false, label: Text('Set days and hours')),
              ButtonSegment(value: true, label: Text('Type availability')),
            ],
            selected: {typed},
            onSelectionChanged: enabled ? (v) => choose(v.first) : null,
          ),
          const SizedBox(height: 12),
          if (typed)
            TextField(
              key: const Key('onboarding-schedule-typed'),
              controller: _box(hours),
              enabled: enabled,
              minLines: 2,
              maxLines: null,
              decoration: const InputDecoration(
                border: OutlineInputBorder(),
                hintText: 'e.g. Available Monday to Thursday, mornings',
              ),
              onChanged: (value) {
                _answer(hours.key, value);
                if (days != null &&
                    days.writable &&
                    _answerOf(days.key).trim() != notApplicable) {
                  _answer(days.key, notApplicable);
                }
              },
            )
          else if (text.isEmpty)
            Text('No schedule yet.', style: theme.textTheme.bodyMedium)
          else if (week != null)
            for (final group in text.split(';'))
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text(group.trim(), style: theme.textTheme.bodyMedium),
              )
          else
            Text(
              'Written as “$text”, which is not a schedule. Set it to '
              'replace it.',
              style: theme.textTheme.bodyMedium,
            ),
          if (!typed) const SizedBox(height: 10),
          if (!typed)
            OutlinedButton.icon(
              key: const Key('onboarding-schedule'),
              onPressed: enabled
                  ? () async {
                      final picked = await showWorkSchedulePanel(
                        context,
                        title: 'Work schedule',
                        days: week,
                        formats: widget.formats,
                      );
                      if (picked == null || !mounted) return;
                      final (dayText, hourText) = scheduleText(picked);
                      _answer(hours.key, hourText);
                      if (days != null && days.writable) {
                        _answer(days.key, dayText);
                      }
                    }
                  : null,
              icon: const Icon(Icons.schedule_outlined, size: 18),
              label: Text(text.isEmpty ? 'Set the schedule' : 'Change it'),
            ),
        ],
      ),
    );
  }

  Widget _fileControl(BuildContext context, OnboardingQuestion question) {
    final theme = Theme.of(context);
    final enabled = question.writable && !_saving && _uploading == null;
    final pending = _pending[question.key];
    final voided = _voided(question);
    final hasFile =
        !voided &&
        ((pending != null && pending.isNotEmpty) || question.answered);

    return Column(
      key: answerKey(question.key),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            OutlinedButton.icon(
              onPressed: enabled ? () => _pickFile(question) : null,
              icon: _uploading == question.key
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.upload_file_outlined, size: 18),
              label: Text(
                _uploading == question.key
                    ? 'Uploading…'
                    : hasFile
                    ? 'Replace file'
                    : 'Choose a file',
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                voided
                    ? "Doesn't apply."
                    : hasFile
                    ? 'A file is attached.'
                    : 'Nothing uploaded yet.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ],
    );
  }

  /// The W-4 or W-9, filled in and signed here rather than uploaded (Jose,
  /// 2026-10-01: "this is not something he should upload, it should be
  /// signed within the app as well").
  Widget _taxForm(BuildContext context, OnboardingQuestion question) {
    final theme = Theme.of(context);
    final kind = question.taxFormKind;
    final label = switch (question.key) {
      'direct_deposit_form' => 'direct deposit form',
      'i9_form' => 'I-9',
      _ => kind == 'w9' ? 'W-9' : 'W-4',
    };
    final form = _form!;
    final section2Open =
        question.key == 'i9_form' &&
        question.answered &&
        !form.i9Section2Done &&
        form.i9Section2Mine;
    final voided = _voided(question);
    final signed = !voided && question.answered;
    final file = _revealed[question.key] ?? (question.masked ? null : question.value);

    return Wrap(
      key: answerKey(question.key),
      spacing: 10,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (question.writable && kind != null && !voided)
          FilledButton.icon(
            key: Key(question.key == 'tax_form' ? 'tax-form-open' : 'tax-form-open-${question.key}'),
            onPressed: _saving ? null : () => _openTaxForm(question, kind),
            icon: const Icon(Icons.draw_outlined, size: 18),
            label: Text(signed ? 'Fill in again' : 'Fill in and sign your $label'),
          ),
        if (!voided && section2Open)
          FilledButton.icon(
            key: const Key('i9-section2-open'),
            onPressed: _saving ? null : () => _openTaxForm(question, 'i9_section2'),
            icon: const Icon(Icons.fact_check_outlined, size: 18),
            label: const Text('Complete Section 2'),
          ),
        if (signed && file != null && file.isNotEmpty)
          OutlinedButton(
            key: Key(question.key == 'tax_form' ? 'tax-form-view' : 'tax-form-view-${question.key}'),
            onPressed: () => _viewTaxForm(file, label),
            child: const Text('View'),
          ),
        Text(
          voided
              ? "Doesn't apply."
              : signed
              ? (question.key == 'i9_form'
                    ? (form.i9Section2Done
                          ? 'Signed I-9 attached, both sections.'
                          : 'Section 1 signed. Section 2 is the employer’s.')
                    : 'Signed $label attached.')
              : kind == null
              ? (question.key == 'i9_form'
                    ? 'A contractor has no I-9.'
                    : 'Answer your tax ID question first.')
              : 'Not signed yet.',
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }

  Future<void> _openTaxForm(OnboardingQuestion question, String kind) {
    final form = _form!;
    bool answered(String key) => _question(form, key)?.answered ?? false;

    return showSidePanel<void>(
      context,
      builder: (panel) => TaxFormPanel(
        api: widget.api,
        onboardingId: widget.onboardingId,
        kind: kind,
        ssnOnFile: answered('ssn'),
        einOnFile: answered('ein'),
        onSigned: (response) async {
          Navigator.of(panel).pop();
          setState(() => _adopt(response));
          await _loadDocument();
          widget.onChanged?.call();
        },
      ),
    );
  }

  bool _gettingPdf = false;

  /// Fetch the signed copy, show it, and offer to save it. Its own endpoint
  /// (`…/document/pdf`) audits the copy and refuses one whose stored document
  /// no longer matches its fingerprint, in a sentence shown here.
  Future<void> _signedPdf() async {
    setState(() {
      _gettingPdf = true;
      _actionError = null;
    });

    final response = await widget.api.getBytesResponse(
      '/hr/onboardings/${widget.onboardingId}/document/pdf',
    );
    if (!mounted) return;
    setState(() => _gettingPdf = false);

    final bytes = response.bytes;
    if (!response.ok || bytes == null) {
      setState(() => _actionError = response.message ?? 'The PDF could not be made.');
      return;
    }

    final form = _form;
    final name = [
      if (form != null && form.contractName.isNotEmpty) form.contractName,
      if (form != null && form.personName.isNotEmpty) form.personName,
    ].join(' - ');
    final filename = '${name.isEmpty ? 'Signed contract' : name}.pdf';

    await showSidePanel<void>(
      context,
      builder: (panel) => SidePanel(
        title: 'Signed contract',
        width: 640,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(panel).pop(),
            child: const Text('Close'),
          ),
          FilledButton.icon(
            key: const Key('onboarding-save-pdf'),
            onPressed: () => Files.download(filename, bytes),
            icon: const Icon(Icons.download_outlined, size: 18),
            label: const Text('Download'),
          ),
        ],
        child: PdfView(bytes: Uint8List.fromList(bytes)),
      ),
    );
  }

  Future<void> _viewTaxForm(String file, String label) async {
    final response = await widget.api.getBytesResponse('/attachments/$file');
    if (!mounted) return;
    final bytes = response.bytes;
    if (bytes == null) {
      setState(() => _actionError = response.message);
      return;
    }
    final signed = Uint8List.fromList(bytes);
    // The signed form is a picture of the page since HR caae3171 (so the
    // contract's PDF can print it); one signed before that is a PDF.
    final pdf = bytes.length > 4 && String.fromCharCodes(bytes.take(4)) == '%PDF';
    await showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Signed $label',
        width: 600,
        child: pdf
            ? PdfView(bytes: signed)
            : DecoratedBox(
                key: const Key('tax-form-signed-page'),
                decoration: const BoxDecoration(color: EzBiziTheme.paper),
                child: Image.memory(signed, fit: BoxFit.fitWidth),
              ),
      ),
    );
  }

  Future<void> _pickFile(OnboardingQuestion question) async {
    final picked = await Files.pickBytes();
    if (picked == null || !mounted) return;

    setState(() {
      _uploading = question.key;
      _actionError = null;
    });

    final response = await widget.api.postFile(
      '/attachments',
      'file',
      picked.name,
      picked.bytes,
      fields: {
        'attachable_type': 'onboardings',
        'attachable_id': widget.onboardingId,
      },
    );

    if (!mounted) return;

    setState(() => _uploading = null);

    if (!response.ok) {
      setState(() => _actionError = response.reason);
      return;
    }

    final id = (response.body['data'] as Map?)?['id']?.toString();
    if (id == null || id.isEmpty) {
      setState(() => _actionError = 'The file uploaded but no id came back.');
      return;
    }

    _answer(question.key, id);
  }

  // ── the signing step ─────────────────────────────────────────────

  Widget _signingSection(
    BuildContext context,
    Onboarding form, {
    required bool split,
  }) {
    final theme = Theme.of(context);

    // The server's list covers both sides. The person about to sign is owed
    // THEIR outstanding questions: the employer signs first now, while the
    // new hire's half is still open (HR, 7ed8111d).
    final parties = {
      for (final signature in form.signatures)
        if (signature.mine && !signature.signed) signature.party,
    };
    final missing = [
      for (final entry in form.missing.entries)
        if (parties.isEmpty ||
            parties.contains(_question(form, entry.key)?.field.filledBy) ||
            _question(form, entry.key)?.field.filledBy == filledByEither)
          entry.value,
    ];

    return Column(
      key: const ValueKey('tour-hr-onboarding-signing'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionTitle(context, 'The document'),
        Text(
          '${split ? 'The contract is on the right.' : 'The contract is under Contract, at the top.'} '
          'The record of a signature holds a fingerprint of its exact bytes, '
          'so a copy that has been changed afterwards will not match it.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        _documentBox(context),
        if (missing.isNotEmpty) ...[
          const SizedBox(height: 14),
          Text(
            // The server's own list, in its own words for each question.
            // Stated rather than acted on: whether an unanswered question stops
            // a signature is the server's rule and its refusal says so. What
            // this owes somebody about to press Sign is the list of what they
            // have not done.
            'Still to answer: ${missing.join(', ')}.',
            style: theme.textTheme.bodyMedium,
          ),
        ],
        for (final signature in form.signatures) ...[
          const SizedBox(height: 18),
          _signature(context, form, signature),
        ],
      ],
    );
  }

  Widget _signature(
    BuildContext context,
    Onboarding form,
    OnboardingSignature signature,
  ) {
    final theme = Theme.of(context);

    final title = signature.label.isEmpty
        ? _vocabulary.filledByLabel(signature.party)
        : signature.label;

    if (signature.signed) {
      return Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest.withValues(
            alpha: 0.4,
          ),
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Flexible(
                  child: Text(title, style: theme.textTheme.titleMedium),
                ),
                const Tag('Signed'),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              [
                // The label captured AT the moment of signing, not a name
                // looked up now: it is what the record says, and it still
                // answers the question after the account is gone.
                if (signature.signerLabel != null) signature.signerLabel!,
                formatStamp(signature.signedAt, '', formats: widget.formats),
              ].where((part) => part.isNotEmpty).join(' · '),
              style: theme.textTheme.bodyMedium,
            ),
            if (signature.affirmation != null) ...[
              const SizedBox(height: 4),
              Text(
                'Signed by typing: ${signature.affirmation}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      );
    }

    // Somebody else's to perform. Shown rather than hidden, because "what is
    // this document still waiting for" is the question an unsigned contract
    // raises, and an employee who cannot see that the employer has not
    // countersigned assumes the whole thing failed.
    //
    // Which side it belongs to is named only where there are words for the
    // sides. Without the vocabulary the honest thing is to say it is not theirs
    // and stop, rather than print a wire value at somebody.
    if (!signature.mine) {
      return _waiting(
        context,
        title,
        _vocabulary.isUsable
            ? 'Not signed yet, and not yours to sign — this one is '
                  '${_vocabulary.filledByLabel(signature.party)}.'
            : 'Not signed yet, and not yours to sign.',
      );
    }

    // Every word of the ceremony is the server's, and it is stored verbatim as
    // the evidence of what somebody was shown. With no document to fingerprint,
    // or no wording to agree to, this end has nothing it may substitute:
    // improvising a consent sentence would put words in a legal record that
    // nobody wrote.
    final document = _signable(form);

    if (document == null) {
      return _waiting(
        context,
        title,
        'The document to sign, and the wording you would be agreeing to, did '
        'not arrive. Signing is not possible until they do — this app will not '
        'compose them itself, because what you are shown is what the signature '
        'record says you were shown.',
      );
    }

    return _SigningStep(
      // Rebuilt from scratch when the field changes, so a half-given consent
      // can never be carried from one signature to another.
      key: ValueKey(signature.fieldKey),
      api: widget.api,
      title: title,
      consentStatement: form.consentStatement,
      intentStatement: form.intentStatement,
      busy: _signingKey == signature.fieldKey,
      // The signature is over the SAVED document. With answers unsaved the
      // contract on screen is not the one it would be taken against.
      blocked: _pending.isEmpty ? null : 'Save your answers first.',
      error: _signErrorKey == signature.fieldKey ? _signError : null,
      onSign: (affirmation, drawing) => _sign(
        signature.fieldKey,
        affirmation,
        drawing,
        document.contentHash,
      ),
    );
  }

  /// The document, but only when there is enough of it to sign against.
  ///
  /// Null when the bytes or their fingerprint did not arrive, or when the
  /// server sent no wording. All three are required: the signature the server
  /// accepts carries the hash of what this screen displayed, and it refuses
  /// without one — "send the fingerprint of the document you were shown."
  OnboardingDocument? _signable(Onboarding form) {
    final document = _paper;

    if (document == null || !document.isUsable) return null;
    if (form.consentStatement.isEmpty || form.intentStatement.isEmpty) {
      return null;
    }

    return document;
  }

  Widget _waiting(BuildContext context, String title, String why) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 6),
          Text(why, style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }

  /// Whether the document is something other than plain text.
  ///
  /// A judgement about what this build can LAY OUT, which is a client fact and
  /// not a rule about the document. An empty mime says nothing either way and
  /// gets no note: guessing from the bytes would be the sort of sniffing that
  /// is wrong exactly when a contract happens to mention a tag.
  bool _notProse(String mime) =>
      mime.isNotEmpty && !mime.startsWith('text/plain');

  Widget _documentBox(BuildContext context) {
    final theme = Theme.of(context);
    final document = _paper;

    if (_paperError != null) return ErrorNote(_paperError!);

    if (document == null) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(16),
          child: SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('onboarding-exact-text'),
            onPressed: () => setState(() => _showBytes = !_showBytes),
            icon: Icon(
              _showBytes ? Icons.expand_less : Icons.expand_more,
              size: 18,
            ),
            label: Text(
              _showBytes ? 'Hide the exact text' : 'Show the exact text',
            ),
          ),
        ),
        if (_showBytes)
          Container(
            constraints: const BoxConstraints(maxHeight: 320),
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: theme.colorScheme.surface,
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(8),
            ),
            child: SingleChildScrollView(
              // Selectable, because somebody is entitled to keep a copy of
              // what they signed — retrievability is one of the five things
              // ESIGN asks for, and a document you can only look at is not
              // retrievable.
              //
              // Shown exactly as it arrived, whatever [OnboardingDocument.mime]
              // says it is. That is not a nicety: the hash sent back with the
              // signature is over these bytes, so a prettified version would
              // be evidence of a document nobody was shown. The contract pane
              // renders it to READ; this is the thing itself.
              child: SelectableText(
                document.content,
                style: theme.textTheme.bodyMedium?.copyWith(height: 1.5),
              ),
            ),
          ),
        // Said out loud when the document is not plain prose.
        //
        // The renderer produces markup, and the exact text shows it exactly as
        // it arrived — because the fingerprint that goes back with the
        // signature is over these bytes, and a tidied-up version would be a
        // picture of a document nobody signed. Somebody reading tags deserves
        // to be told why rather than left thinking the page is broken.
        if (_notProse(document.mime)) ...[
          const SizedBox(height: 8),
          Text(
            'The exact text is the document as it is stored — kept as '
            '${document.mime.split(';').first}, markup and all. It is shown '
            'unaltered because what is signed is these bytes: a neatened '
            'version would not be the thing the fingerprint is over.',
            style: theme.textTheme.bodySmall,
          ),
        ],
        if (document.isPreview) ...[
          const SizedBox(height: 8),
          Text(
            'Nothing has been signed yet, so this is rendered from the answers '
            'as they stand — answering another question changes it. The moment '
            'somebody signs, it is frozen and kept.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }
}


/// The ceremony: agree to sign electronically, then sign.
///
/// ── Consent is a thing they DO ───────────────────────────────────
///
/// ESIGN requires consent to transact electronically to be GIVEN, not assumed.
/// It is a separate agreement from the contract — which is why the signature
/// record timestamps it separately — so it is a box somebody ticks, above a
/// sentence the server wrote, and not a line of small print under the button.
///
/// ── And the signature is an affirmative ACT ──────────────────────
///
/// Typing their own name, in their own keystrokes. A button on its own is a
/// click, and a click is what every cookie banner collects; `affirmation` on
/// the signature record exists to hold something a person did deliberately.
/// So the button is dead until both have happened, and this widget owns that
/// state rather than the form around it: nothing about a half-given consent
/// should survive the page being rebuilt for some other reason.
class _SigningStep extends StatefulWidget {
  const _SigningStep({
    super.key,
    required this.api,
    required this.title,
    required this.consentStatement,
    required this.intentStatement,
    required this.busy,
    this.blocked,
    required this.error,
    required this.onSign,
  });

  final ApiClient api;
  final String title;

  /// Why signing cannot happen yet, whatever is ticked and typed. Null when
  /// nothing outside the ceremony stands in the way.
  final String? blocked;

  /// Both the server's words, verbatim. They are stored on the signature record
  /// as `consent_statement` and `intent_statement`, so a word changed here
  /// would be a record of somebody agreeing to something they were not shown.
  final String consentStatement;
  final String intentStatement;
  final bool busy;
  final String? error;
  /// The typed name, and the drawn signature as a PNG data URL.
  final void Function(String affirmation, String drawing) onSign;

  @override
  State<_SigningStep> createState() => _SigningStepState();
}

class _SigningStepState extends State<_SigningStep> {
  final _affirmation = TextEditingController();

  /// Drawn by hand, as in A1 Tools (Jose, 2026-10-01), or the signer's saved
  /// signature in its place.
  final _signature = SignatureFieldController();

  bool _consented = false;

  @override
  void initState() {
    super.initState();
    _signature.addListener(_redraw);
  }

  void _redraw() => setState(() {});

  @override
  void dispose() {
    _affirmation.dispose();
    _signature.dispose();
    super.dispose();
  }

  bool get _canSign =>
      !widget.busy &&
      widget.blocked == null &&
      _consented &&
      _affirmation.text.trim().isNotEmpty &&
      _signature.ready;

  Future<void> _signNow() async {
    final drawing = await _signature.dataUrl();
    if (drawing == null || !mounted) return;
    widget.onSign(_affirmation.text.trim(), drawing);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(widget.title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 14),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _consented,
            // The server's sentence, verbatim. It is stored on the signature
            // record as `consent_statement`, so a word changed here would be a
            // record of somebody agreeing to something they were not shown.
            title: Text(widget.consentStatement),
            onChanged: widget.busy
                ? null
                : (value) => setState(() => _consented = value ?? false),
          ),
          const SizedBox(height: 14),
          Text(
            widget.intentStatement,
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _affirmation,
            enabled: !widget.busy,
            decoration: const InputDecoration(
              labelText: 'Type your full name to sign',
              border: OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 14),
          Text('Draw your signature', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          SignatureField(
            api: widget.api,
            controller: _signature,
            enabled: !widget.busy,
          ),
          if (widget.error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(widget.error!),
          ],
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: _canSign ? _signNow : null,
              child: Text(widget.busy ? 'Signing…' : 'Sign'),
            ),
          ),
          if (!_canSign && !widget.busy) ...[
            const SizedBox(height: 8),
            Text(
              widget.blocked ??
                  (!_consented
                      ? 'Agree to sign electronically, type your name and '
                            'draw your signature before this can be signed.'
                      : _affirmation.text.trim().isEmpty
                      ? 'Type your name above to sign.'
                      : 'Draw your signature above to sign.'),
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}

/// "County for disputes": a search of the chosen state's counties
/// (`GET /places/counties`, HR d834214e). What is typed is the answer and the
/// list only suggests (Jose, 2026-10-05: Eve typed Cook, the search found
/// nothing, and "she should be able to just save").
class _CountySearch extends StatefulWidget {
  const _CountySearch({
    super.key,
    required this.api,
    required this.state,
    required this.controller,
    required this.answer,
    required this.enabled,
    required this.onPicked,
  });

  final ApiClient api;

  /// The governing state's full name; null until one is chosen.
  final String? state;
  final TextEditingController controller;
  final String answer;
  final bool enabled;
  final ValueChanged<String> onPicked;

  @override
  State<_CountySearch> createState() => _CountySearchState();
}

class _CountySearchState extends State<_CountySearch> {
  Timer? _debounce;
  int _run = 0;
  bool _looking = false;
  List<String> _found = [];

  /// The text the last finished search was for, so "nothing found" is said
  /// only about what is in the box.
  String? _searched;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _search(String text) {
    _debounce?.cancel();
    final query = text.trim();
    setState(() {
      if (query.isEmpty) _found = [];
    });
    if (query.isEmpty) return;
    _debounce = Timer(const Duration(milliseconds: 300), () async {
      final run = ++_run;
      setState(() => _looking = true);
      final response = await widget.api.get('/places/counties', {
        'state': widget.state!,
        'q': query,
      });
      if (!mounted || run != _run) return;
      setState(() {
        _looking = false;
        _searched = query;
        _found = response.ok && response.data is List
            ? [for (final name in response.data as List) '$name']
            : [];
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    final typed = widget.controller.text.trim();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: widget.controller,
          enabled: widget.enabled && state != null,
          decoration: InputDecoration(
            border: const OutlineInputBorder(),
            hintText: state == null
                ? 'Choose the governing state first'
                : 'Search the counties of $state',
            prefixIcon: const Icon(Icons.search, size: 20),
            suffixIcon: _looking
                ? const Padding(
                    padding: EdgeInsets.all(12),
                    child: SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : null,
            helperText:
                typed.isNotEmpty &&
                    !_looking &&
                    _searched == typed &&
                    _found.isEmpty
                ? 'Not in the list. What you typed is saved as the county.'
                : null,
          ),
          onChanged: (text) {
            _search(text);
            widget.onPicked(text.trim());
          },
        ),
        if (_found.isNotEmpty) ...[
          const SizedBox(height: 4),
          Material(
            elevation: 1,
            borderRadius: BorderRadius.circular(8),
            child: Column(
              children: [
                for (final name in _found)
                  ListTile(
                    key: Key('county-$name'),
                    dense: true,
                    title: Text(name),
                    onTap: () {
                      widget.controller.text = name;
                      setState(() => _found = []);
                      widget.onPicked(name);
                    },
                  ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

/// Save an onboarding's signed PDF straight away, from a list (Jose,
/// 2026-10-05: "make a button on onboarding to download them faster").
/// Null when it was saved, otherwise the reason it could not be.
Future<String?> downloadSignedPdf(
  ApiClient api,
  OnboardingSummary row,
) async {
  final response = await api.getBytesResponse(
    '/hr/onboardings/${row.id}/document/pdf',
  );
  final bytes = response.bytes;
  if (!response.ok || bytes == null) {
    return response.message ?? 'The PDF could not be made.';
  }
  final name = [
    if (row.contractName.isNotEmpty) row.contractName,
    if (row.personName.isNotEmpty) row.personName,
  ].join(' - ');
  Files.download('${name.isEmpty ? 'Signed contract' : name}.pdf', bytes);
  return null;
}
