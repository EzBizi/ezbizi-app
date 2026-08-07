/// Reading a typed price (plan §7).
///
/// People type DOLLARS. The wire carries minor units — a whole number of cents,
/// never a float, because a price that can drift by a fraction of a cent is a
/// price nobody can reconcile against an invoice.
///
/// This is a named function rather than three lines inside a submit handler
/// because the conversion is the part that has been wrong twice, and neither
/// mistake was visible on screen:
///
///   - stripping every non-digit read "1,250.00" as 125000 cents, a hundredfold
///     error that still looks like a plausible price;
///   - and the field was labelled "Price in cents" while this parser read
///     dollars, so somebody following the label and typing 19999 for $199.99
///     created a job for $19,999.00.
///
/// Dollars won because dollars are what people type and what the label now
/// says. There is one parser and one label, and this is what they agree on.
library;

/// Reads [input] as an amount in dollars and returns it in minor units.
///
/// Returns null for an empty field, which is a real answer rather than a
/// failure: a job can be booked before anyone has quoted it, and the caller
/// omits the amount entirely in that case.
///
/// Throws [FormatException] for anything else that is not a number. Refusing is
/// the whole point — the previous code guessed, and a guess that lands on a
/// plausible number is a bug nobody notices until a client queries an invoice.
int? parseAmountMinor(String input) {
  final trimmed = input.trim();

  if (trimmed.isEmpty) {
    return null;
  }

  // Thousands separators are punctuation somebody typed, not part of the
  // number. Removed here and nowhere else, so "1,250.00" is 1250 dollars.
  final major = double.tryParse(trimmed.replaceAll(',', ''));

  // isFinite rejects "NaN" and "Infinity", which double.tryParse accepts
  // happily and which would then throw out of .round() as an unhandled error
  // rather than as something the form can show.
  if (major == null || !major.isFinite) {
    throw FormatException(
      'Enter the price in dollars, like 199.99 — or leave it empty.',
      input,
    );
  }

  // Rounded, not truncated: a third of a dollar is 33 cents rather than 33
  // cents minus whatever the binary representation lost.
  return (major * 100).round();
}
