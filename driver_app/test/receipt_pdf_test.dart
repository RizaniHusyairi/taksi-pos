import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:driver_app/services/receipt_service.dart';

/// Karcis PDF harus terbangun untuk tiap metode bayar, termasuk data yang
/// bolong (supir tanpa plat, tanpa nomor penumpang). Set env RECEIPT_PDF_OUT
/// ke sebuah folder untuk menyimpan contoh PDF-nya dan memeriksanya manual.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Map<String, dynamic> booking(String method, {bool lengkap = true}) => {
        'id': 1234,
        'price': 150000,
        'created_at': '2026-10-23T05:25:00Z',
        'passenger_phone': lengkap ? '081234567890' : null,
        'zone_to': {'name': 'Samarinda Seberang'},
        'cso': {'name': 'Rina Kusuma'},
        'driver': {
          'name': 'Budi Santoso',
          'driver_profile': lengkap
              ? {'line_number': 12, 'plate_number': 'kt 1234 ab', 'car_model': 'Toyota Avanza'}
              : {},
        },
        'transaction': {
          'id': 5678,
          'method': method,
          'created_at': '2026-10-23T05:25:00Z',
          'receipt_token': 'abc123',
        },
      };

  final out = Platform.environment['RECEIPT_PDF_OUT'];

  for (final method in ['CashDriver', 'CashCSO', 'QRIS']) {
    for (final lengkap in [true, false]) {
      test('karcis $method (${lengkap ? 'lengkap' : 'data bolong'}) terbangun', () async {
        final bytes = await ReceiptService.buildPdf(booking(method, lengkap: lengkap));
        expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
        if (out != null && lengkap) {
          File('$out/karcis_$method.pdf').writeAsBytesSync(bytes);
        }
      });
    }
  }
}
