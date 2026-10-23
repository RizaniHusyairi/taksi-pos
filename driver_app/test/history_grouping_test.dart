import 'package:flutter_test/flutter_test.dart';

import 'package:driver_app/screens/history_screen.dart';

/// Regresi: layar Riwayat crash ("_Map is not a subtype of _GrupTanggal")
/// begitu ada DUA trip di tanggal yang sama, karena pengelompokan meng-cast
/// entri terakhir — yang saat itu sudah berupa trip — ke grup.
void main() {
  Map<String, dynamic> trip(int id, String waktu) =>
      {'id': id, 'created_at': waktu, 'driver_share': 1000};

  test('beberapa trip di tanggal yang sama masuk satu grup', () {
    final entri = HistoryScreen.susunPerTanggal([
      trip(3, '2026-10-23T08:00:00'),
      trip(2, '2026-10-23T07:00:00'),
      trip(1, '2026-10-22T07:00:00'),
    ]);

    final grup = entri.whereType<GrupTanggal>().toList();
    expect(grup.length, 2);
    expect(grup[0].trips.length, 2);
    expect(grup[1].trips.length, 1);
    // Urutan datar: header, trip, trip, header, trip.
    expect(entri.length, 5);
    expect(entri[0], isA<GrupTanggal>());
    expect(entri[3], isA<GrupTanggal>());
  });

  test('daftar kosong tidak menghasilkan header', () {
    expect(HistoryScreen.susunPerTanggal([]), isEmpty);
  });
}
