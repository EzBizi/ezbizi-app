/// What the automation builder offers for a trigger, worked out from the
/// catalogue alone, so it can be tested without a widget tree (UI queue 131
/// and 133).
library;

import '../../core/automation_catalogue.dart';

// ── Only if: which comparisons fit a detail ─────────────────────────

/// Triggers that carry a "before", which is what the change operators read.
bool triggerCarriesChanges(String? triggerKey) =>
    triggerKey != null &&
    (triggerKey.endsWith('_changed') || triggerKey.endsWith('updated'));

const _changeOperators = {'changed', 'changed_to', 'changed_from', 'tag_added'};

const _byType = <String, List<String>>{
  'text': [
    'eq',
    'neq',
    'in',
    'not_in',
    'contains',
    'not_contains',
    'has_tag',
    'not_has_tag',
    'tag_added',
    'is_empty',
    'is_not_empty',
  ],
  'list': ['has_tag', 'not_has_tag', 'tag_added', 'is_empty', 'is_not_empty'],
  'enum': ['eq', 'neq', 'in', 'not_in', 'is_empty', 'is_not_empty'],
  'id': ['eq', 'neq', 'in', 'not_in', 'is_empty', 'is_not_empty'],
  'number': ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'],
  'date': ['eq', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'],
  'boolean': ['eq', 'is_empty'],
};

/// The catalogue's operators that fit a detail of [type] on [triggerKey], in
/// the catalogue's order.
///
/// A type this build does not know, or none at all (a server older than
/// types), offers every operator: filtering on a guess would hide the one that
/// was needed. [current] is always kept, so a saved rule still reads back.
List<CatalogueChoice> operatorsFor(
  List<CatalogueChoice> all, {
  required String? type,
  required String? triggerKey,
  String? current,
}) {
  final fits = _byType[type];
  final changes = triggerCarriesChanges(triggerKey);

  return [
    for (final op in all)
      if (op.key == current ||
          (_changeOperators.contains(op.key)
              ? changes
              : (fits == null || fits.contains(op.key))))
        op,
  ];
}

/// One line saying what an operator means, on this detail.
String operatorExample(
  String op, {
  required String label,
  String? sample,
  String? type,
}) {
  final v = (sample == null || sample.trim().isEmpty) ? 'something' : sample;
  final lower = label.toLowerCase();
  final dated = type == 'date';

  return switch (op) {
    'eq' => '$label is $v',
    'neq' => '$label is not $v',
    'in' => '$label is $v, or another one you list',
    'not_in' => '$label is none of the ones you list',
    'gt' => dated ? '$label is after $v' : '$label is more than $v',
    'gte' => dated ? '$label is $v or later' : '$label is $v or more',
    'lt' => dated ? '$label is before $v' : '$label is less than $v',
    'lte' => dated ? '$label is $v or earlier' : '$label is $v or less',
    'contains' => '$label has “$v” in it',
    'not_contains' => '$label does not have “$v” in it',
    'is_empty' => 'No $lower was given',
    'is_not_empty' => 'Some $lower was given',
    'changed' => '$label changed, to anything',
    'changed_to' => '$label changed to $v',
    'changed_from' => '$label changed from $v to something else',
    'has_tag' => '$label include “$v”',
    'not_has_tag' => '$label do not include “$v”',
    'tag_added' => '“$v” was added to $lower',
    _ => '$label $op $v',
  };
}

// ── Then: which steps fit a trigger ─────────────────────────────────

/// The four groups of the Then step's list (Jose's 1).
enum StepGroup { record, message, team, elsewhere }

StepGroup groupOf(String type) => switch (type) {
  'sms.send' ||
  'email.send' ||
  'whatsapp.send' ||
  'lead.text' ||
  'message.reply' ||
  'reply' ||
  'draft_reply' ||
  'publish_reply' => StepGroup.message,
  'notify' ||
  'alert.send' ||
  'whatsapp.group_send' ||
  'notify_administrators' ||
  'report.text' ||
  'report.send' ||
  'send_report' => StepGroup.team,
  'webhook' ||
  'workiz.job_update' ||
  'inspection_report.workiz' ||
  'add_to_audience' ||
  'task.create' => StepGroup.elsewhere,
  _ => StepGroup.record,
};

//// The record a rule on [triggerKey] can set fields on and name, or null when
/// the event is about something its module's fields do not describe.
CatalogueSubject? effectiveSubject(
  AutomationCatalogue catalogue,
  String? triggerKey,
) => catalogue.subjectOf(triggerKey);

/// What a rule on [triggerKey] is about, in words: "job", "phone call".
/// Null when it is about nothing in particular.
String? recordWords(AutomationCatalogue catalogue, String? triggerKey) {
  final subject = effectiveSubject(catalogue, triggerKey);
  if (subject != null) return subject.label.toLowerCase();
  final named = triggerKey == null
      ? null
      : catalogue.locate(triggerKey)?.$2.subjects;
  if (named == null || named.isEmpty) return null;
  // "PhoneCall" -> "phone call".
  return named.first
      .replaceAllMapped(RegExp(r'(?<=[a-z])([A-Z])'), (m) => ' ${m[1]}')
      .toLowerCase();
}

/// Does a step of payload [type] work on a rule reacting to [triggerKey]?
///
/// Read from the server: the step's `subjects` against the trigger's records
/// (`triggers[].subjects`, else its module's subject). A step that names no
/// records works on any. Setting a field needs the module's record, with
/// something on it a rule may set, and changing the status needs a status.
bool stepFits(
  AutomationCatalogue catalogue, {
  required String type,
  required String? triggerKey,
  CatalogueActionType? definition,
}) {
  // A server older than subjects cannot say, so nothing is hidden.
  if (!catalogue.publishesSubjects || triggerKey == null) return true;

  final records = catalogue.recordsOf(triggerKey);
  final listed = definition?.subjects;
  if (listed != null) {
    return records.any((record) => definition!.worksOn(record));
  }

  final subject = effectiveSubject(catalogue, triggerKey);

  return switch (type) {
    'update_field' => subject != null && subject.writableFields.isNotEmpty,
    'change_status' =>
      subject != null && subject.writableFields.any((f) => f.key == 'status'),
    _ => true,
  };
}

/// Does a module's declared action fit [triggerKey]? A subject-bound action of
/// a module that names its record works only on that record.
bool moduleActionFits(
  AutomationCatalogue catalogue,
  CatalogueModule module,
  CatalogueAction action, {
  required String? triggerKey,
  required String type,
}) {
  if (!catalogue.publishesSubjects || triggerKey == null) return true;

  final mine = module.subject?.key;
  if (action.subjectBound &&
      mine != null &&
      !catalogue.recordsOf(triggerKey).contains(plainRecord(mine))) {
    return false;
  }

  return stepFits(
    catalogue,
    type: type,
    triggerKey: triggerKey,
    definition: catalogue.actionType(type),
  );
}

/// A notification's "also tell" switches mean something on one record only.
bool parameterFits(String parameterKey, String? subjectKey) =>
    switch (parameterKey) {
      'to_assignee' => subjectKey == null || subjectKey == 'task',
      'to_job_technicians' => subjectKey == null || subjectKey == 'job',
      _ => true,
    };
