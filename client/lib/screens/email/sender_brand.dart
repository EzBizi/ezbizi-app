/// Which mark to draw for a From address.
///
/// Gmail shows a brand logo and, for senders with a Verified Mark
/// Certificate, a blue check. We cannot read Google's BIMI store, so the
/// logo is the site's icon for the registrable domain (`email.apple.com`
/// → `apple.com`) and the check is for a known verified brand when the
/// message was not reported as failing authentication.
class SenderBrand {
  SenderBrand._();

  static const _compoundTlds = {
    'ac.uk',
    'co.uk',
    'gov.uk',
    'org.uk',
    'com.au',
    'net.au',
    'co.nz',
    'co.jp',
    'co.in',
    'com.br',
    'com.mx',
    'co.za',
    'com.tr',
  };

  /// Domains Gmail shows with a verified check (VMC / well-known brand).
  static const verifiedDomains = {
    'adobe.com',
    'amazon.com',
    'apple.com',
    'facebook.com',
    'facebookmail.com',
    'github.com',
    'google.com',
    'icloud.com',
    'instagram.com',
    'linkedin.com',
    'meta.com',
    'microsoft.com',
    'netflix.com',
    'paypal.com',
    'pinterest.com',
    'spotify.com',
    'tiktok.com',
    'whatsapp.com',
    'youtube.com',
  };

  static String? domainOf(String email) {
    final at = email.lastIndexOf('@');
    if (at < 0 || at == email.length - 1) return null;
    final host = email.substring(at + 1).trim().toLowerCase();
    if (host.isEmpty || !host.contains('.')) return null;
    return host;
  }

  /// The domain a favicon belongs to: `mail.google.com` → `google.com`.
  static String? registrableDomain(String email) {
    final host = domainOf(email);
    if (host == null) return null;

    for (final tld in _compoundTlds) {
      if (host == tld || host.endsWith('.$tld')) {
        final rest = host == tld
            ? ''
            : host.substring(0, host.length - tld.length - 1);
        if (rest.isEmpty) return host;
        return '${rest.split('.').last}.$tld';
      }
    }

    final parts = host.split('.');
    if (parts.length < 2) return host;
    return '${parts[parts.length - 2]}.${parts[parts.length - 1]}';
  }

  static String? faviconUrl(String email) {
    final domain = registrableDomain(email);
    if (domain == null) return null;
    return 'https://www.google.com/s2/favicons?sz=64&domain=$domain';
  }

  /// A blue check, like Gmail's VMC mark.
  ///
  /// [authenticated] is what the provider said about this message: true
  /// when DMARC passed, false when it failed, null when nobody said.
  /// Only a pass gets the check: a brand name in the From line is the one
  /// thing a spoof always has (audit 11-27).
  static bool verified(String email, {bool? authenticated}) {
    if (authenticated != true) return false;
    final domain = registrableDomain(email);
    return domain != null && verifiedDomains.contains(domain);
  }
}
