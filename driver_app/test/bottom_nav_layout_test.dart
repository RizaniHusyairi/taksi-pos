import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:driver_app/widgets/app_bottom_nav.dart';

void main() {
  const items = [
    NavItemData(Icons.dashboard_rounded, 'Home'),
    NavItemData(Icons.history_rounded, 'Riwayat'),
    NavItemData(Icons.add_rounded, 'Pesan'),
    NavItemData(Icons.account_balance_wallet_rounded, 'Setoran'),
    NavItemData(Icons.person_rounded, 'Profil'),
  ];

  // Dua bentuk nav: tanpa tombol tengah (layar supir) dan dengan tombol tengah
  // yang menyembul (CSO — "Pesan" jadi aksi utama).
  for (final centerIndex in <int?>[null, 2]) {
    final label = centerIndex == null ? 'rata' : 'tombol tengah';

    for (final width in [320.0, 360.0, 412.0]) {
      testWidgets('nav $label 5 item tanpa overflow di ${width.toInt()}dp', (
        tester,
      ) async {
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);

        for (var i = 0; i < items.length; i++) {
          await tester.pumpWidget(
            MaterialApp(
              home: Scaffold(
                bottomNavigationBar: AppBottomNav(
                  currentIndex: i,
                  onTap: (_) {},
                  items: items,
                  centerIndex: centerIndex,
                ),
              ),
            ),
          );
          // Tombol tengah beranimasi terus (cincin berputar + denyut), jadi
          // pumpAndSettle tidak akan pernah selesai — maju beberapa frame saja.
          await tester.pump(const Duration(milliseconds: 350));
          await tester.pump(const Duration(milliseconds: 350));
          expect(
            tester.takeException(),
            isNull,
            reason: 'overflow saat tab $i aktif di ${width}dp ($label)',
          );
        }
      });
    }
  }

  testWidgets('menekan tombol tengah melaporkan indeks yang benar', (
    tester,
  ) async {
    var tapped = -1;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          bottomNavigationBar: AppBottomNav(
            currentIndex: 0,
            onTap: (i) => tapped = i,
            items: items,
            centerIndex: 2,
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 350));

    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pump(const Duration(milliseconds: 350));

    expect(tapped, 2);
  });

  // Halo tombol tengah digambar jauh melampaui lingkarannya. Kalau area
  // sentuhnya ikut selebar halo, tepi item tetangga jadi tidak bisa ditekan.
  testWidgets('tombol tengah tidak mencuri sentuhan item tetangga', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    var tapped = -1;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          bottomNavigationBar: AppBottomNav(
            currentIndex: 0,
            onTap: (i) => tapped = i,
            items: items,
            centerIndex: 2,
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 350));

    // Tepi dalam item "Riwayat" — sisi yang paling dekat ke tombol tengah.
    final riwayat = tester.getRect(find.byIcon(Icons.history_rounded));
    final tepiDalam = Offset(riwayat.right + 8, riwayat.center.dy);

    await tester.tapAt(tepiDalam);
    await tester.pump(const Duration(milliseconds: 350));

    expect(tapped, 1, reason: 'sentuhan di tepi item tetangga harus tetap miliknya');
  });
}
