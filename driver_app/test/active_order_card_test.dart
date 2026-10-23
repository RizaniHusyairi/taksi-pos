import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';

import 'package:driver_app/widgets/active_order_card.dart';

/// Kartu order aktif harus terbangun tanpa overflow di layar ponsel sempit,
/// untuk tiap fase (Assigned / OnTrip) dan tiap metode bayar. Banner "TAGIH
/// TUNAI" hanya boleh muncul untuk Tunai ke Supir.
void main() {
  final currency = NumberFormat.currency(locale: 'en', symbol: 'Rp ', decimalDigits: 0);

  Map<String, dynamic> booking(String status, String method) => {
        'id': 1234,
        'status': status,
        'price': '1250000',
        'passenger_phone': '081234567890',
        'created_at': '2026-10-23T07:05:00Z',
        'pickup_confirmed_at': status == 'OnTrip' ? '2026-10-23T07:08:00Z' : null,
        'zone_to': {'name': 'Kecamatan Samarinda Seberang Ujung Paling Jauh'},
        'cso': {'name': 'Rina Kusumawardhani'},
        'transaction': {'method': method},
      };

  Future<void> pump(WidgetTester tester, Map<String, dynamic> b, {int? pickup}) async {
    tester.view.physicalSize = const Size(360 * 3, 1400 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ActiveOrderCard(
            booking: b,
            currency: currency,
            pickupSecondsLeft: pickup,
            onConfirmPickup: () {},
            onComplete: () {},
          ),
        ),
      ),
    ));
    await tester.pump(const Duration(milliseconds: 100));
  }

  for (final status in ['Assigned', 'OnTrip']) {
    for (final method in ['CashDriver', 'CashCSO', 'QRIS']) {
      testWidgets('$status · $method terbangun tanpa overflow', (tester) async {
        await pump(tester, booking(status, method), pickup: 75);
        expect(tester.takeException(), isNull);
        expect(
          find.text('TAGIH TUNAI KE PENUMPANG'),
          method == 'CashDriver' ? findsOneWidget : findsNothing,
        );
      });
    }
  }

  testWidgets('tunai ke supir minta konfirmasi sebelum selesai', (tester) async {
    var selesai = false;
    tester.view.physicalSize = const Size(360 * 3, 1400 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: ActiveOrderCard(
            booking: booking('OnTrip', 'CashDriver'),
            currency: currency,
            pickupSecondsLeft: null,
            onConfirmPickup: () {},
            onComplete: () => selesai = true,
          ),
        ),
      ),
    ));

    await tester.tap(find.text('TUNAI DITERIMA · SELESAI'));
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('Belum'));
    await tester.pump(const Duration(milliseconds: 300));
    expect(selesai, isFalse);

    await tester.tap(find.text('TUNAI DITERIMA · SELESAI'));
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('Sudah, selesaikan'));
    await tester.pump(const Duration(milliseconds: 300));
    expect(selesai, isTrue);
  });
}
