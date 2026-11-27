/// Muse audit 10.4: personal emails and phone numbers are masked in tables,
/// logs and alert titles. The full value is shown once the row is opened.
String maskEmail(String email) {
  final at = email.indexOf('@');
  if (at < 1) return email;
  return '${email[0]}•••${email.substring(at)}';
}

String maskPhone(String phone) {
  final digits = phone.replaceAll(RegExp(r'\D'), '');
  if (digits.length < 7) return phone;
  return '•••-${digits.substring(digits.length - 4)}';
}

final _emailIn = RegExp(r'[\w.+-]+@[\w-]+\.[\w.-]+');
final _phoneIn = RegExp(r'[+(]?\d[\d\s().-]{8,}\d');

/// Masks every email and phone number inside free text (log lines, alert
/// titles). The opened row still shows the full text.
String maskContacts(String text) => text
    .replaceAllMapped(_emailIn, (m) => maskEmail(m[0]!))
    .replaceAllMapped(_phoneIn, (m) => maskPhone(m[0]!));
