import 'package:ezbizi_client/core/address.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('the root does not name a place', () {
    expect(Address.idOf('/'), isNull);
    expect(Address.idOf(''), isNull);
  });

  test('the first segment is the place', () {
    expect(Address.idOf('/marketing'), 'marketing');
    expect(Address.idOf('/marketing/'), 'marketing');
    expect(Address.idOf('/hr/contracts'), 'hr');
    expect(Address.pathOf('marketing'), '/marketing');
  });

  test('reset, invite and real files are not places', () {
    expect(Address.idOf('/reset'), isNull);
    expect(Address.idOf('/invite'), isNull);
    expect(Address.idOf('/api/v1/me'), isNull);
    expect(Address.idOf('/assets/FontManifest.json'), isNull);
    expect(Address.idOf('/main.dart.js'), isNull);
  });

  test('push writes a new path; the same path is a no-op', () {
    final written = <(String, bool)>[];
    final address = Address(
      path: '/dashboard',
      write: (path, {required replace}) => written.add((path, replace)),
    );

    address.push('/marketing');
    address.push('/marketing');
    address.replace('/dashboard');

    expect(written, [
      ('/marketing', false),
      ('/dashboard', true),
    ]);
  });
}
