import 'package:ezbizi_client/core/deep_links.dart';
import 'package:flutter_test/flutter_test.dart';

/// /jobs/{id}, /leads/{id} and /customers/{id} name one record (monday port,
/// 2026-11-09); anything else under those modules names none.
void main() {
  test('a record path becomes a link to that record', () {
    for (final (path, module, type) in [
      ('/jobs/j-1', 'jobs', 'job'),
      ('/leads/j-1', 'leads', 'lead'),
      ('/customers/j-1', 'customers', 'customer'),
    ]) {
      final link = DeepLinks.fromUri(Uri.parse('https://app.test$path'))!;
      expect((link.module, link.type, link.id), (module, type, 'j-1'));
    }
  });

  test('a bare module path or a third segment is not a record', () {
    expect(DeepLinks.fromUri(Uri.parse('https://app.test/jobs')), isNull);
    expect(DeepLinks.fromUri(Uri.parse('https://app.test/jobs/1/x')), isNull);
    expect(DeepLinks.fromUri(Uri.parse('https://app.test/tasks/1')), isNull);
  });
}
