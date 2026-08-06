import 'package:ezbizi_client/core/deployment.dart';
import 'package:flutter_test/flutter_test.dart';

/// Which face a hostname selects (plan §4.2).
///
/// Presentation, not a boundary — the bundle is the same file either way. What
/// is worth defending here is that a hostname somebody else controls cannot
/// choose the face, because that is how a convincing fake staff sign-in gets
/// built out of the real bundle.
void main() {
  test('the control-plane host shows the control plane', () {
    expect(Deployment.isStaffHost('dev.ezbizi.com'), isTrue);
    expect(Deployment.isStaffHost('DEV.EZBIZI.COM'), isTrue);
  });

  test('the customer host does not', () {
    expect(Deployment.isStaffHost('app.ezbizi.com'), isFalse);
    expect(Deployment.isStaffHost('ezbizi.com'), isFalse);
    expect(Deployment.isStaffHost('portal.ezbizi.com'), isFalse);
  });

  test('a hostname an attacker controls cannot select the staff face', () {
    // Matching on the first label only. A substring or suffix test would hand
    // anyone who can register a domain a way to serve the staff form.
    expect(Deployment.isStaffHost('devious.example.com'), isFalse);
    expect(Deployment.isStaffHost('app.ezbizi.com.dev.example.com'), isFalse);
    expect(Deployment.isStaffHost('mydev.example.com'), isFalse);
    expect(Deployment.isStaffHost('dev-ezbizi.example.com'), isFalse);
  });

  test('no host at all means the customer app', () {
    // Off-web, Uri.base is a file path. A desktop build must not open on the
    // control plane by accident.
    expect(Deployment.isStaffHost(''), isFalse);
  });

  test('the staff hosts are a build flag, not a constant', () {
    // Same reason as API_BASE: baking a hostname into the binary is what made
    // moving A1 Tools to a new domain a migration instead of an edit.
    expect(Deployment.staffHosts, 'dev',
        reason: 'Default only; override with --dart-define=STAFF_HOSTS=...');
  });
}
