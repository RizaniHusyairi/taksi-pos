import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:driver_app/widgets/withdraw_sheet.dart';

/// Lembar Tarik Dana: pilih BTN / e-wallet, nomor tersimpan tampil di kolom
/// yang bisa diedit, tombol nonaktif sampai isian valid, lalu layar sukses.
void main() {
  Future<List<PayoutTujuan>> pump(
    WidgetTester tester,
    PayoutTujuan saved, {
    Object? gagal,
  }) async {
    final terkirim = <PayoutTujuan>[];
    tester.view.physicalSize = const Size(360 * 3, 1600 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Align(
            alignment: Alignment.bottomCenter,
            child: WithdrawSheet(
              amount: 245000,
              debt: 15000,
              saved: saved,
              onSubmit: (t) async {
                if (gagal != null) throw gagal;
                terkirim.add(t);
              },
            ),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 400));
    return terkirim;
  }

  Finder tombolTarik() => find.textContaining('TARIK Rp');

  /// Font uji (Ahem) jauh lebih lebar dari font asli, jadi isi lembar bisa
  /// melewati layar — gulirkan dulu sebelum mengetuk.
  Future<void> ketuk(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await tester.pumpAndSettle();
    await tester.tap(f);
  }

  testWidgets('nomor tersimpan tampil dan bisa ditarik langsung', (
    tester,
  ) async {
    final terkirim = await pump(
      tester,
      const PayoutTujuan(method: 'bank', accountNumber: '0011223344'),
    );
    expect(tester.takeException(), isNull);
    expect(find.text('0011223344'), findsOneWidget);

    await ketuk(tester, tombolTarik());
    await tester.pump(const Duration(milliseconds: 400));

    expect(terkirim.single.method, 'bank');
    expect(terkirim.single.accountNumber, '0011223344');
    expect(find.text('Penarikan diajukan!'), findsOneWidget);
    expect(find.textContaining('•••• 3344'), findsOneWidget);
  });

  testWidgets('pindah ke e-wallet menampilkan akun tersimpan & bisa diedit', (
    tester,
  ) async {
    final terkirim = await pump(
      tester,
      const PayoutTujuan(
        method: 'bank',
        accountNumber: '0011223344',
        provider: 'dana',
        number: '081234567890',
        holder: 'Budi',
      ),
    );

    await ketuk(tester, find.text('E-Wallet'));
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('081234567890'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await ketuk(tester, find.text('GoPay'));
    await tester.enterText(
      find.widgetWithText(TextField, '081234567890'),
      '089900001111',
    );
    await tester.pump();
    expect(find.textContaining('akan disimpan'), findsOneWidget);

    await ketuk(tester, tombolTarik());
    await tester.pump(const Duration(milliseconds: 400));

    final t = terkirim.single;
    expect(t.method, 'ewallet');
    expect(t.provider, 'gopay');
    expect(t.number, '089900001111');
    expect(t.holder, 'Budi');
  });

  testWidgets('tujuan kosong: tombol nonaktif sampai nomor diisi', (
    tester,
  ) async {
    final terkirim = await pump(tester, const PayoutTujuan(method: 'bank'));
    expect(find.text('Belum diisi — isi dulu'), findsOneWidget);

    await ketuk(tester, tombolTarik());
    await tester.pump();
    expect(terkirim, isEmpty);

    await tester.enterText(find.byType(TextField), '1234567890');
    await tester.pump();
    await ketuk(tester, tombolTarik());
    await tester.pump(const Duration(milliseconds: 400));
    expect(terkirim.single.accountNumber, '1234567890');
  });

  testWidgets('galat server ditampilkan, lembar tetap terbuka', (tester) async {
    await pump(
      tester,
      const PayoutTujuan(method: 'bank', accountNumber: '0011223344'),
      gagal: Exception('Saldo kurang'),
    );
    await ketuk(tester, tombolTarik());
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('Penarikan diajukan!'), findsNothing);
    expect(find.byIcon(Icons.error_outline_rounded), findsOneWidget);
  });
}
