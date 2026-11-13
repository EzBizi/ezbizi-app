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
