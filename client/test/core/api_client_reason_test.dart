import 'package:ezbizi_client/core/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

/// Coordinator follow-up A on the Tasks Phase 2 whole-branch review's own
/// fix wave: reshaping `TaskWriter::bulk()`'s `errors.tasks` from a map
/// keyed by task id into a flat LIST of `{task_id, field, message}` maps
/// (finding 6, fix round 2 — the keyed shape is silently flattened away by
/// Laravel's `ValidationException`/`MessageBag`) changed what
/// `ApiResponse.reason` extracts: it takes the first `errors` value, and
/// when that value is a non-empty List, returns `first.first.toString()`.
/// A List of MAPS makes `first.first` a Map, so `.toString()` produces
/// something like `{task_id: ..., field: due_on, message: ...}` — a
/// screen-reader user hears a serialized map instead of a sentence.
///
/// `ApiResponse.reason` itself is untouched here — it is shared by every
/// module, not the place to special-case one caller's shape. The fix is
/// server-side: `bulk()` now throws `tasks` as a plain-string sentence
/// FIRST (`['tasks' => [<sentence>], 'task_errors' => $errors]`), with the
/// structured per-task attribution moved to `task_errors`, a key `.reason`
/// never looks at. This test proves that shape is what `.reason` needs —
/// against the getter exactly as it stands, not a hypothetical.
void main() {
  test(
    'reason reads a plain sentence from a list-of-maps-shaped errors.tasks (the old bulk() shape) as a serialized map',
    () {
      final response = ApiResponse(
        statusCode: 422,
        body: <String, dynamic>{
          'message': 'The given data was invalid.',
          'errors': <String, dynamic>{
            'tasks': <dynamic>[
              <String, dynamic>{
                'task_id': '019a059f-b6cd-7140-a36d-d1343b58151f',
                'field': 'due_on',
                'message':
                    "A task's start date cannot be after its due date.",
              },
            ],
          },
        },
      );

      // This is the symptom the coordinator flagged: with the OLD shape,
      // `.reason` cannot tell a list of maps from a list of sentences, and
      // hands back the map's own Dart toString() — a screen-reader user
      // would hear "task_id, field, due_on, message" read out, not a
      // sentence.
      expect(
        response.reason,
        isNot("A task's start date cannot be after its due date."),
      );
      expect(response.reason, contains('task_id'));
    },
  );

  test(
    'reason reads the sentence from the fixed bulk() shape — a plain-string tasks entry ahead of structured task_errors',
    () {
      const sentence = "A task's start date cannot be after its due date.";
      final response = ApiResponse(
        statusCode: 422,
        body: <String, dynamic>{
          'message': 'The given data was invalid.',
          'errors': <String, dynamic>{
            'tasks': <dynamic>[sentence],
            'task_errors': <dynamic>[
              <String, dynamic>{
                'task_id': '019a059f-b6cd-7140-a36d-d1343b58151f',
                'field': 'due_on',
                'message': sentence,
              },
            ],
          },
        },
      );

      // The announced text (this is what _announce()/the live-region
      // Semantics text ultimately shows) is the real sentence, not a
      // serialized map.
      expect(response.reason, sentence);
    },
  );
}
