import 'dart:async';

import 'package:flutter/widgets.dart';

/// Menyegarkan data layar secara diam-diam tiap [autoRefreshEvery], tetapi
/// HANYA selama layar itu benar-benar dilihat: aplikasi di depan, route-nya
/// paling atas, dan tab-nya yang aktif di IndexedStack (tab tersembunyi
/// dimatikan TickerMode-nya). Begitu tab dibuka lagi, langsung disegarkan.
///
/// [autoRefresh] wajib tidak menampilkan spinner layar penuh dan tidak
/// menimpa data lama dengan pesan error — penyegaran ini tidak diminta
/// pengguna, jadi kegagalannya cukup diam.
mixin AutoRefresh<T extends StatefulWidget> on State<T> {
  Timer? _autoTimer;
  ModalRoute<dynamic>? _route;
  bool? _tabAktif;

  Duration get autoRefreshEvery => const Duration(seconds: 30);

  /// Lewati satu putaran (mis. saat pengguna sedang mengirim formulir).
  bool get autoRefreshPaused => false;

  Future<void> autoRefresh();

  @override
  void initState() {
    super.initState();
    _autoTimer = Timer.periodic(autoRefreshEvery, (_) => _tick());
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _route = ModalRoute.of(context);
    final aktif = TickerMode.valuesOf(context).enabled;
    // Tab baru saja kembali terlihat → segarkan sekarang, bukan nanti.
    final baruTerlihat = _tabAktif == false && aktif;
    _tabAktif = aktif;
    if (baruTerlihat) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _tick());
    }
  }

  void _tick() {
    if (!mounted || _tabAktif != true || autoRefreshPaused) return;
    if (!(_route?.isCurrent ?? true)) return;
    final app = WidgetsBinding.instance.lifecycleState;
    if (app != null && app != AppLifecycleState.resumed) return;
    autoRefresh();
  }

  @override
  void dispose() {
    _autoTimer?.cancel();
    super.dispose();
  }
}
