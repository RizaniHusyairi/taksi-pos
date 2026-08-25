import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:driver_app/widgets/wallet_source_sheet.dart';

/// Lembar rincian dompet harus bisa DIBANGUN tanpa meledak.
///
/// Bug yang ditangkap test ini: formatter tanggalnya dibuat sebagai field
/// initializer State, sehingga `DateFormat('…', 'id_ID')` melempar
/// LocaleDataException sebelum satu piksel pun sempat digambar. `flutter
/// analyze` bersih dan kompilasinya sukses — kegagalannya hanya muncul saat
/// dijalankan, tepat ketika pengguna membuka lembarnya.
///
/// Pemanggilan API di initState memang gagal di lingkungan test (tanpa
/// jaringan), dan itu justru bagian dari yang diuji: lembar harus jatuh ke
/// keadaan galat dengan rapi, bukan melempar ke atas.
void main() {
  Future<void> pump(WidgetTester tester, String bucket) async {
    await tester.pumpWidget(
      MaterialApp(home: Scaffold(body: WalletSourceSheet(bucket: bucket))),
    );
    await tester.pump(const Duration(milliseconds: 400));
  }

  for (final bucket in ['income', 'debt_standard', 'debt_manual']) {
    testWidgets('lembar "$bucket" terbangun tanpa pengecualian', (tester) async {
      await pump(tester, bucket);

      expect(
        tester.takeException(),
        isNull,
        reason: 'Membangun lembar tidak boleh melempar — inilah yang dulu '
            'gagal karena DateFormat berlocale.',
      );
    });
  }

  testWidgets('menampilkan judul & keadaan memuat saat data belum tiba',
      (tester) async {
    await pump(tester, 'income');

    expect(tester.takeException(), isNull);
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.text('Memuat…'), findsOneWidget);
  });

  // CATATAN: jalur GAGAL dan BERHASIL sengaja tidak diuji di sini. Widget ini
  // membuat ApiService() sendiri, jadi tidak ada cara menyuntik jawaban palsu;
  // di lingkungan test panggilannya menggantung tanpa jaringan maupun platform
  // channel. Menguji kedua jalur itu memerlukan ApiService yang bisa disuntik —
  // perubahan tersendiri, bukan sesuatu yang pantas diselundupkan ke sini.
}
