import 'package:ezbizi_client/core/contracts.dart';
import 'package:flutter_test/flutter_test.dart';

/// The two shapes `reserved_keys` can arrive in, and the one thing that must
/// not differ between them.
///
/// `ContractVocabulary`'s docblock says the common core is read both ways
/// because `CommonCore::RESERVED` is genuinely a map of key to `{label, is}` on
/// the server, and the endpoint flattening it into a list is a presentation
/// choice one refactor away from changing. That sentence is only worth having
/// if the map branch reads the same entry the list branch does — and it did not
/// read `is` at all, so the day that refactor happened every note would have
/// gone quiet: the chips would still have drawn, with nothing under them, and
/// nothing would have failed.
///
/// `is` is the sentence completing "it is …", so the assertions below are the
/// pair that matter together: it lands in [CoreField.note] and it is never
/// allowed to become [CoreField.label]. Promoting it is what once captioned a
/// locked field "their middle name".
void main() {
  /// `is` and nothing else — the shape RESERVED has on the server today.
  Map<String, dynamic> mapShaped() => {
    'reserved_keys': {
      'middle_name': {'label': 'Middle name', 'is': 'their middle name'},
    },
    'field_types': [
      {'key': 'text', 'label': 'Free text'},
    ],
    'filled_by': [
      {'key': 'employer', 'label': 'The employer'},
    ],
  };

  CoreField only(ContractVocabulary vocabulary) {
    expect(vocabulary.core, hasLength(1));

    return vocabulary.core.single;
  }

  test('a map-shaped reserved_keys keeps `is` as the note', () {
    final field = only(ContractVocabulary.fromJson(mapShaped()));

    expect(field.key, 'middle_name');
    expect(field.note, 'their middle name');
  });

  test('a map-shaped reserved_keys never promotes `is` into the label', () {
    final field = only(ContractVocabulary.fromJson(mapShaped()));

    expect(field.label, 'Middle name');
  });

  test('`note` still wins over `is` when both are sent', () {
    final json = mapShaped();
    (json['reserved_keys'] as Map)['middle_name'] = {
      'label': 'Middle name',
      'note': 'it lives on their profile',
      'is': 'their middle name',
    };

    expect(only(ContractVocabulary.fromJson(json)).note,
        'it lives on their profile');
  });

  test('the list shape reads the same entry the same way', () {
    final field = only(ContractVocabulary.fromJson({
      'reserved_keys': [
        {'key': 'middle_name', 'label': 'Middle name', 'is': 'their middle name'},
      ],
      'field_types': [
        {'key': 'text', 'label': 'Free text'},
      ],
      'filled_by': [
        {'key': 'employer', 'label': 'The employer'},
      ],
    }));

    expect(field.label, 'Middle name');
    expect(field.note, 'their middle name');
  });
}
