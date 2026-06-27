/// Format angka ke Rupiah tanpa bergantung pada data locale intl.
/// Contoh: 150000 -> "Rp 150.000".
String formatRupiah(num value) {
  final digits = value.round().abs().toString();
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buffer.write('.');
    buffer.write(digits[i]);
  }
  final sign = value < 0 ? '-' : '';
  return 'Rp $sign${buffer.toString()}';
}
