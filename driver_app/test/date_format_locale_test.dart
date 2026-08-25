import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';

/// Penjaga terhadap LocaleDataException.
///
/// `DateFormat('…', 'id_ID')` MELEMPAR saat dijalankan bila
/// `initializeDateFormatting()` belum pernah dipanggil — dan aplikasi ini tidak
/// pernah memanggilnya. Kesalahannya tidak terlihat oleh `flutter analyze`
/// maupun saat kompilasi: layarnya baru meledak ketika dibuka pengguna, yang
/// persis terjadi pada lembar rincian dompet.
///
/// Dua test di bawah mengunci keduanya: bukti bahwa pengecualiannya nyata, dan
/// pemindaian sumber agar tidak ada yang menambahkannya kembali.
void main() {
  test('DateFormat tanpa locale aman dipakai', () {
    // Inilah pola yang dipakai seluruh aplikasi.
    expect(
      () => DateFormat('dd MMM yyyy, HH:mm').format(DateTime(2026, 8, 25, 10, 30)),
      returnsNormally,
    );
  });

  test('DateFormat berlocale melempar tanpa initializeDateFormatting', () {
    // Membuktikan bahaya itu nyata, bukan kehati-hatian berlebihan. Bila suatu
    // saat initializeDateFormatting() ditambahkan di main(), test ini gagal —
    // dan itu sinyal yang benar untuk melonggarkan aturan di bawah.
    expect(
      () => DateFormat('dd MMM yyyy', 'id_ID').format(DateTime(2026, 8, 25)),
      throwsA(anything),
    );
  });

  test('tidak ada DateFormat berlocale di lib/', () {
    final pelanggar = <String>[];
    // DateFormat( '<pola>' , '<locale>' )  ← argumen kedua inilah yang dilarang
    final berlocale = RegExp(r"""DateFormat\(\s*(?:'[^']*'|"[^"]*")\s*,\s*(?:'|")""");

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;

      final isi = f.readAsStringSync();
      if (isi.contains('initializeDateFormatting')) continue; // sudah aman

      for (final baris in isi.split('\n').asMap().entries) {
        if (berlocale.hasMatch(baris.value)) {
          pelanggar.add('${f.path}:${baris.key + 1}');
        }
      }
    }

    expect(
      pelanggar,
      isEmpty,
      reason: 'DateFormat dengan argumen locale akan melempar '
          'LocaleDataException saat layar dibuka, karena aplikasi ini tidak '
          'memanggil initializeDateFormatting(). Hapus argumen locale-nya, '
          'atau panggil initializeDateFormatting() lebih dulu di main().',
    );
  });
}
