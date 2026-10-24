import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../models/cso_transaction.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import '../../utils/api_error.dart';
import '../../services/notification_service.dart';
import '../../widgets/cso/cso_receipt_sheet.dart';
import '../../widgets/cso/cso_ticket_sheet.dart';
import '../../widgets/map_tiles.dart';
import '../../widgets/fade_in.dart';
import '../../widgets/gradient_button.dart';
import '../../widgets/profile_avatar.dart';

/// Dashboard CSO: ringkasan yang dikelola CSO — pendapatan & transaksi hari ini,
/// rincian Tunai/QRIS, peta posisi supir, dan transaksi terakhir.
/// Peta memakai OpenStreetMap (gratis, tanpa API key). Auto-refresh tiap 20 detik.
class CsoDashboardScreen extends StatefulWidget {
  /// Pindah tab di CsoMainScreen (2 = Pesan, 3 = Setoran).
  final void Function(int index)? onBukaTab;

  const CsoDashboardScreen({super.key, this.onBukaTab});

  @override
  State<CsoDashboardScreen> createState() => _CsoDashboardScreenState();
}

class _DriverLoc {
  final int id;
  final String name;
  final int? line;
  final String? car;
  final String? plate;
  final String status;
  final LatLng pos;
  final DateTime? updatedAt;

  _DriverLoc({
    required this.id,
    required this.name,
    required this.status,
    required this.pos,
    this.line,
    this.car,
    this.plate,
    this.updatedAt,
  });

  bool get isReady => status == 'standby' || status == 'available';

  // Driver kirim lokasi tiap ~30 dtk. Kalau tak update > 2 menit, lokasi dianggap
  // "basi" (HP mati / sinyal hilang / GPS off) → jangan diandalkan untuk dispatch.
  bool get isStale {
    if (updatedAt == null) return true;
    return DateTime.now().difference(updatedAt!).inSeconds > 120;
  }

  String get lastSeen {
    if (updatedAt == null) return 'tidak diketahui';
    final s = DateTime.now().difference(updatedAt!).inSeconds;
    if (s < 5) return 'baru saja';
    if (s < 60) return '$s dtk lalu';
    final m = s ~/ 60;
    if (m < 60) return '$m mnt lalu';
    return '${m ~/ 60} jam lalu';
  }

  factory _DriverLoc.fromJson(Map<String, dynamic> j) {
    num? n(dynamic v) => v is num ? v : num.tryParse(v?.toString() ?? '');
    int? i(dynamic v) => v == null ? null : int.tryParse(v.toString());
    return _DriverLoc(
      id: j['id'] as int,
      name: (j['name'] ?? '-').toString(),
      line: i(j['line_number']),
      car: j['car_model']?.toString(),
      plate: j['plate_number']?.toString(),
      status: (j['status'] ?? 'offline').toString(),
      pos: LatLng(
        (n(j['latitude']) ?? 0).toDouble(),
        (n(j['longitude']) ?? 0).toDouble(),
      ),
      updatedAt: DateTime.tryParse(j['updated_at']?.toString() ?? '')?.toLocal(),
    );
  }
}

class _CsoDashboardScreenState extends State<CsoDashboardScreen>
    with SingleTickerProviderStateMixin {
  final _api = ApiService();
  final _mapController = MapController();

  // Map
  List<_DriverLoc> _drivers = [];
  LatLng _base = const LatLng(-0.371975, 117.257919);
  double _radiusKm = 2.6;

  // Animasi marker: glide mulus dari posisi lama ke posisi baru tiap refresh,
  // bukan lompat. Durasi = interval poll agar gerakannya kontinu.
  static const _pollEvery = Duration(seconds: 12);
  late final AnimationController _moveCtrl;
  final Map<int, LatLng> _from = {}; // titik awal tween per driver (id -> pos)
  final Map<int, LatLng> _to = {}; // titik tujuan (posisi server terbaru)

  // Stats
  num _revenue = 0, _cash = 0, _cashDriver = 0, _qris = 0;
  int _count = 0, _cancelled = 0, _queueReady = 0;
  List<Map<String, dynamic>> _antrian = [];
  num _setorTotal = 0;
  String? _setorTertua;

  static const _bulanPendek = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  List<CsoTransaction> _recent = [];

  /// Order yang karcisnya masih menunggu supir / siap diserahkan.
  List<Map<String, dynamic>> _tickets = [];
  StreamSubscription? _push;

  bool _loading = true;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _moveCtrl = AnimationController(vsync: this, duration: _pollEvery);
    _load(initial: true);
    _timer = Timer.periodic(_pollEvery, (_) => _load());
    // Karcis terbit (supir SAYA JEMPUT): segarkan tanpa menunggu polling.
    _push = NotificationService().events.listen((e) {
      if (e['type'] == 'ticket_ready') _load();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _push?.cancel();
    _moveCtrl.dispose();
    super.dispose();
  }

  // Lerp manual (LatLng latlong2 tidak punya lerp statis).
  LatLng _lerpLatLng(LatLng a, LatLng b, double t) => LatLng(
        a.latitude + (b.latitude - a.latitude) * t,
        a.longitude + (b.longitude - a.longitude) * t,
      );

  // Posisi marker yang sedang ditampilkan (hasil interpolasi) untuk driver id.
  LatLng _animatedPos(int id, LatLng fallback) {
    final from = _from[id] ?? fallback;
    final to = _to[id] ?? fallback;
    return _lerpLatLng(from, to, _moveCtrl.value);
  }

  // Saat data baru tiba: jadikan posisi marker SAAT INI sebagai titik awal,
  // posisi server terbaru sebagai tujuan, lalu mulai animasi glide dari 0.
  // Map dibangun ulang dari daftar terbaru, jadi driver yang sudah offline
  // (hilang dari daftar) otomatis terbuang dari _from/_to.
  void _retargetMarkers(List<_DriverLoc> drivers) {
    final newFrom = <int, LatLng>{};
    final newTo = <int, LatLng>{};
    for (final d in drivers) {
      newFrom[d.id] = (_from.containsKey(d.id) && _to.containsKey(d.id))
          ? _animatedPos(d.id, d.pos) // lanjut mulus dari posisi tampil kini
          : d.pos; // driver baru: langsung di tempat (tanpa glide dari 0,0)
      newTo[d.id] = d.pos;
    }
    _from
      ..clear()
      ..addAll(newFrom);
    _to
      ..clear()
      ..addAll(newTo);
    _moveCtrl.forward(from: 0);
  }

  Future<void> _load({bool initial = false}) async {
    if (initial) setState(() => _loading = true);
    try {
      final results = await Future.wait([
        _api.getCsoDashboardStats(),
        _api.getCsoDriverLocations(),
      ]);
      if (!mounted) return;

      final stats = (results[0].data as Map).cast<String, dynamic>();
      final today =
          (stats['today'] as Map?)?.cast<String, dynamic>() ?? const {};
      final queue =
          (stats['queue'] as Map?)?.cast<String, dynamic>() ?? const {};
      final deposit =
          (stats['deposit'] as Map?)?.cast<String, dynamic>() ?? const {};
      final recent = ((stats['recent'] as List?) ?? [])
          .map(
            (e) => CsoTransaction.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList();

      final locs = (results[1].data as Map).cast<String, dynamic>();
      final base = (locs['base'] as Map?)?.cast<String, dynamic>() ?? const {};
      final drivers = ((locs['drivers'] as List?) ?? [])
          .map((e) => _DriverLoc.fromJson((e as Map).cast<String, dynamic>()))
          .toList();

      num toNum(dynamic v) => v is num ? v : num.tryParse('$v') ?? 0;
      int toInt(dynamic v) => int.tryParse('$v') ?? 0;

      setState(() {
        _revenue = toNum(today['revenue']);
        _cash = toNum(today['cash']);
        _cashDriver = toNum(today['cash_driver']);
        _qris = toNum(today['qris']);
        _count = toInt(today['count']);
        _cancelled = toInt(today['cancelled']);
        _queueReady = toInt(queue['ready']);
        _antrian = ((queue['next'] as List?) ?? [])
            .map((e) => (e as Map).cast<String, dynamic>())
            .toList();
        _setorTotal = toNum(deposit['outstanding_total']);
        _setorTertua = deposit['oldest_date']?.toString();
        _recent = recent;
        _drivers = drivers;
        if (base['latitude'] != null && base['longitude'] != null) {
          _base = LatLng(
            (base['latitude'] as num).toDouble(),
            (base['longitude'] as num).toDouble(),
          );
        }
        if (base['radius_km'] != null) {
          _radiusKm = (base['radius_km'] as num).toDouble();
        }
        _loading = false;
        _error = null;
      });

      // Mulai animasi glide marker ke posisi terbaru.
      _retargetMarkers(drivers);

      // Terpisah dari Future.wait: kartu karcis tidak boleh menjatuhkan
      // seluruh dashboard bila endpoint-nya sesaat gagal.
      _loadTickets();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = apiErrorMessage(e);
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () => _load(initial: false),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        children: [
          if (_error != null) _errorBanner(),
          if (_loading)
            _kerangka()
          else ...[
            if (_tickets.isNotEmpty) ...[
              FadeInUp(child: _ticketsCard()),
              const SizedBox(height: 12),
            ],
            if (_setorTotal > 0) ...[
              FadeInUp(delayMs: 60, child: _pengingatSetor()),
              const SizedBox(height: 12),
            ],
            FadeInUp(delayMs: 120, child: _ringkasanHariIni()),
            const SizedBox(height: 14),
            FadeInUp(delayMs: 180, child: _antrianCard()),
            const SizedBox(height: 14),
            FadeInUp(delayMs: 240, child: _mapCard()),
            const SizedBox(height: 16),
            FadeInUp(delayMs: 300, child: _recentCard()),
          ],
        ],
      ),
    );
  }

  Widget _errorBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.cloud_off_rounded,
            color: AppColors.warning,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              _error!,
              style: const TextStyle(color: AppColors.inkSoft, fontSize: 12.5),
            ),
          ),
          TextButton(
            onPressed: () => _load(initial: true),
            child: const Text('Coba lagi'),
          ),
        ],
      ),
    );
  }

  // === Pengingat setoran ===================================================

  int get _umurSetor {
    final d = DateTime.tryParse(_setorTertua ?? '');
    if (d == null) return 0;
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day).difference(DateTime(d.year, d.month, d.day)).inDays;
  }

  /// Muncul hanya bila ada tunai kasir yang belum disetor. Warnanya ikut
  /// umur tunai tertua (sama dengan tab Setoran): biru < 2 hari, oranye
  /// 2–6 hari, merah ≥ 7 hari.
  Widget _pengingatSetor() {
    final umur = _umurSetor;
    final warna = umur >= 7
        ? AppColors.danger
        : umur >= 2
            ? const Color(0xFFF57C00)
            : AppColors.skyBlue;
    final d = DateTime.tryParse(_setorTertua ?? '');
    final tertua = d == null ? '' : '${d.day} ${_bulanPendek[d.month - 1]}';
    final umurTeks = umur <= 0 ? 'hari ini' : (umur == 1 ? 'kemarin' : '$umur hari');

    return Material(
      color: warna.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => widget.onBukaTab?.call(3),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: warna.withValues(alpha: 0.35)),
          ),
          child: Row(
            children: [
              _Denyut(
                aktif: umur >= 2,
                child: Container(
                  padding: const EdgeInsets.all(9),
                  decoration: BoxDecoration(color: warna, shape: BoxShape.circle),
                  child: const Icon(Icons.savings_rounded, color: Colors.white, size: 20),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Tunai belum disetor',
                        style: TextStyle(color: warna, fontSize: 12, fontWeight: FontWeight.w700)),
                    _AngkaNaik(
                      nilai: _setorTotal,
                      style: const TextStyle(color: AppColors.ink, fontSize: 18, fontWeight: FontWeight.w900),
                    ),
                    Text('Tertua $tertua · $umurTeks',
                        style: const TextStyle(color: AppColors.inkSoft, fontSize: 11.5)),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(color: warna, borderRadius: BorderRadius.circular(20)),
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('Setor', style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)),
                    Icon(Icons.chevron_right_rounded, color: Colors.white, size: 16),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // === Ringkasan hari ini ==================================================

  Widget _ringkasanHariIni() {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const [BoxShadow(color: Color(0x330B4DA2), blurRadius: 18, offset: Offset(0, 9))],
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            right: -30,
            top: -40,
            child: Container(
              width: 120,
              height: 120,
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.08)),
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.payments_rounded, color: Colors.white, size: 18),
                  const SizedBox(width: 6),
                  const Text('Tunai di kasir hari ini',
                      style: TextStyle(color: Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600)),
                  const Spacer(),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.18),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text('$_count order${_cancelled > 0 ? ' · $_cancelled batal' : ''}',
                        style: const TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w700)),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              _AngkaNaik(
                nilai: _cash,
                style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 14),
              Row(
                children: [
                  _ubinMini(Icons.local_taxi_rounded, 'Tunai ke supir', _cashDriver),
                  const SizedBox(width: 8),
                  _ubinMini(Icons.qr_code_rounded, 'QRIS', _qris),
                  const SizedBox(width: 8),
                  _ubinMini(Icons.functions_rounded, 'Total', _revenue),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _ubinMini(IconData icon, String label, num nilai) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: Colors.white, size: 16),
            const SizedBox(height: 4),
            _AngkaNaik(
              nilai: nilai,
              style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w800),
            ),
            Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Colors.white70, fontSize: 10.5)),
          ],
        ),
      ),
    );
  }

  // === Antrean berikutnya ==================================================

  Widget _antrianCard() {
    final pertama = _antrian.isEmpty ? null : _antrian.first;
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [BoxShadow(color: Color(0x0F0B4DA2), blurRadius: 12, offset: Offset(0, 5))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.format_list_numbered_rounded, color: AppColors.deepBlue, size: 20),
              const SizedBox(width: 8),
              const Text('Antrean Berikutnya',
                  style: TextStyle(color: AppColors.ink, fontSize: 15, fontWeight: FontWeight.w800)),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: AppColors.success.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text('$_queueReady siap',
                    style: const TextStyle(color: AppColors.success, fontSize: 11.5, fontWeight: FontWeight.w800)),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (pertama == null)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 18),
              child: Column(
                children: [
                  Icon(Icons.hourglass_empty_rounded, color: AppColors.inkFaint, size: 30),
                  SizedBox(height: 6),
                  Text('Belum ada supir siap di antrean',
                      style: TextStyle(color: AppColors.inkSoft, fontSize: 13)),
                ],
              ),
            )
          else ...[
            if (_isRejoin(pertama)) _pemisahRejoin(semuaRejoin: true),
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 350),
              transitionBuilder: (child, anim) => FadeTransition(
                opacity: anim,
                child: SlideTransition(
                  position: Tween(begin: const Offset(0.08, 0), end: Offset.zero).animate(anim),
                  child: child,
                ),
              ),
              child: KeyedSubtree(key: ValueKey(pertama['id']), child: _giliranUtama(pertama)),
            ),
            for (var i = 1; i < _antrian.length; i++) ...[
              if (_isRejoin(_antrian[i]) && !_isRejoin(_antrian[i - 1]))
                FadeInUp(delayMs: 60 * i, child: _pemisahRejoin()),
              FadeInUp(delayMs: 60 * i, child: _barisAntrian(_antrian[i], i + 1)),
            ],
            const SizedBox(height: 12),
            GradientButton(
              label: 'BUAT ORDER',
              icon: Icons.add_rounded,
              height: 50,
              onPressed: () => widget.onBukaTab?.call(2),
            ),
          ],
        ],
      ),
    );
  }

  Widget _avatarSupir(Map<String, dynamic> d, double size) {
    final nama = '${d['name'] ?? '-'}';
    return ProfileAvatar(
      photoPath: d['photo_path']?.toString(),
      inisial: nama.isEmpty ? 'S' : nama[0].toUpperCase(),
      size: size,
      editable: false,
    );
  }

  Widget _pilLambung(dynamic line, {bool besar = false}) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: besar ? 10 : 8, vertical: besar ? 4 : 3),
      decoration: BoxDecoration(color: AppColors.deepBlue, borderRadius: BorderRadius.circular(8)),
      child: Text(line == null ? '—' : 'L$line',
          style: TextStyle(color: Colors.white, fontSize: besar ? 13 : 11, fontWeight: FontWeight.w900)),
    );
  }

  Widget _giliranUtama(Map<String, dynamic> d) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        gradient: const LinearGradient(colors: [Color(0xFFE7F7F0), Color(0xFFEAF3FF)]),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.success.withValues(alpha: 0.4)),
      ),
      child: Row(
        children: [
          _avatarSupir(d, 50),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _pilLambung(d['line_number'], besar: true),
                    const SizedBox(width: 8),
                    _Denyut(
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(color: AppColors.success, borderRadius: BorderRadius.circular(20)),
                        child: const Text('GILIRAN',
                            style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w900, letterSpacing: 0.6)),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text('${d['name'] ?? '-'}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: AppColors.ink, fontSize: 15, fontWeight: FontWeight.w800)),
                if ((d['plate_number'] ?? '').toString().isNotEmpty)
                  Text('${d['plate_number']}', style: const TextStyle(color: AppColors.inkSoft, fontSize: 12)),
              ],
            ),
          ),
          const Text('#1', style: TextStyle(color: AppColors.success, fontSize: 22, fontWeight: FontWeight.w900)),
        ],
      ),
    );
  }

  bool _isRejoin(Map<String, dynamic> d) => d['is_rejoin'] == true;

  static const _oranye = Color(0xFFEA580C);

  /// Pemisah kelompok Rejoin (sort_order >= 1000). Ketuk ikon ⓘ untuk
  /// penjelasan singkat.
  Widget _pemisahRejoin({bool semuaRejoin = false}) {
    return Padding(
      padding: EdgeInsets.only(top: semuaRejoin ? 0 : 14, bottom: semuaRejoin ? 10 : 2),
      child: Row(
        children: [
          Expanded(child: Container(height: 1.5, color: _oranye.withValues(alpha: 0.25))),
          const SizedBox(width: 8),
          Tooltip(
            triggerMode: TooltipTriggerMode.tap,
            showDuration: const Duration(seconds: 4),
            message: semuaRejoin
                ? 'Antrian utama kosong — semua supir siap berasal dari Rejoin '
                    '(masuk lagi di hari yang sama, urut waktu masuk).'
                : 'Supir di bawah garis ini masuk lagi di hari yang sama. '
                    'Mereka di belakang antrian utama, urut waktu masuk.',
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                gradient: const LinearGradient(colors: [Color(0xFFFFF4E5), Color(0xFFFFE8D6)]),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: _oranye.withValues(alpha: 0.4)),
              ),
              child: const Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.replay_rounded, size: 13, color: _oranye),
                  SizedBox(width: 4),
                  Text('ANTRIAN REJOIN',
                      style: TextStyle(color: _oranye, fontSize: 10.5, fontWeight: FontWeight.w900, letterSpacing: 0.6)),
                  SizedBox(width: 4),
                  Icon(Icons.info_outline_rounded, size: 13, color: _oranye),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(child: Container(height: 1.5, color: _oranye.withValues(alpha: 0.25))),
        ],
      ),
    );
  }

  Widget _barisAntrian(Map<String, dynamic> d, int urut) {
    final rejoin = _isRejoin(d);
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          SizedBox(
            width: 26,
            child: Text('#$urut',
                style: TextStyle(
                    color: rejoin ? _oranye.withValues(alpha: 0.7) : AppColors.inkFaint,
                    fontSize: 12.5,
                    fontWeight: FontWeight.w800)),
          ),
          _avatarSupir(d, 34),
          const SizedBox(width: 10),
          _pilLambung(d['line_number']),
          const SizedBox(width: 8),
          Expanded(
            child: Text('${d['name'] ?? '-'}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: AppColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600)),
          ),
          if (rejoin) const Icon(Icons.replay_rounded, size: 16, color: _oranye),
        ],
      ),
    );
  }

  // === Kerangka loading ====================================================

  Widget _kerangka() {
    Widget kotak(double h, {double r = 20}) => Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: _Denyut(
            skala: false,
            child: Container(
              height: h,
              decoration: BoxDecoration(
                color: AppColors.inkFaint.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(r),
              ),
            ),
          ),
        );
    return Column(children: [kotak(170, r: 22), kotak(210), kotak(260, r: 18)]);
  }

  Widget _mapCard() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 12, 10),
            child: Row(
              children: [
                const Icon(
                  Icons.map_rounded,
                  color: AppColors.deepBlue,
                  size: 20,
                ),
                const SizedBox(width: 8),
                const Text(
                  'Lokasi Supir',
                  style: TextStyle(
                    color: AppColors.ink,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 4,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.paleBlue,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    '${_drivers.length} supir di peta',
                    style: const TextStyle(
                      color: AppColors.deepBlue,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            ),
          ),
          SizedBox(
            height: 260,
            child: Stack(
              children: [
                FlutterMap(
                  mapController: _mapController,
                  options: MapOptions(initialCenter: _base, initialZoom: 13.2),
                  children: [
                    kajTileLayer(),
                    CircleLayer(
                      circles: [
                        CircleMarker(
                          point: _base,
                          radius: _radiusKm * 1000,
                          useRadiusInMeter: true,
                          color: AppColors.skyBlue.withValues(alpha: 0.08),
                          borderColor: AppColors.skyBlue.withValues(
                            alpha: 0.45,
                          ),
                          borderStrokeWidth: 1.5,
                        ),
                      ],
                    ),
                    // Hanya MarkerLayer yang rebuild tiap frame animasi —
                    // tile, lingkaran, & dashboard lain tidak ikut rebuild.
                    AnimatedBuilder(
                      animation: _moveCtrl,
                      builder: (context, _) => MarkerLayer(
                        markers: [
                          Marker(
                            point: _base,
                            width: 40,
                            height: 40,
                            child: _baseMarker(),
                          ),
                          ..._drivers.map(
                            (d) => Marker(
                              point: _animatedPos(d.id, d.pos),
                              width: 36,
                              height: 36,
                              child: GestureDetector(
                                onTap: () => _showDriverInfo(d),
                                child: _driverMarker(d),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const RichAttributionWidget(
                      attributions: [TextSourceAttribution('© OpenStreetMap contributors')],
                    ),
                  ],
                ),
                Positioned(
                  right: 10,
                  bottom: 10,
                  child: _circleBtn(
                    Icons.my_location_rounded,
                    () => _mapController.move(_base, 13.2),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _loadTickets() async {
    try {
      final res = await _api.getCsoPendingTickets();
      final list = ((res.data['data'] as List?) ?? const [])
          .map((e) => (e as Map).cast<String, dynamic>())
          // Hanya yang masih perlu perhatian CSO: supir belum berangkat, atau
          // sudah berangkat tapi penumpang belum naik.
          .where((b) => b['status'] == 'Assigned')
          .toList()
        ..sort((a, b) {
          final aWait = a['pickup_confirmed_at'] == null ? 0 : 1;
          final bWait = b['pickup_confirmed_at'] == null ? 0 : 1;
          return aWait.compareTo(bWait);
        });
      if (mounted) setState(() => _tickets = list);
    } catch (_) {
      // Biarkan kartu terakhir tetap tampil; dicoba lagi di polling berikutnya.
    }
  }

  Widget _ticketsCard() {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 6),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.skyBlue.withValues(alpha: 0.25)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x1A0B4DA2),
            blurRadius: 16,
            offset: Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.confirmation_number_rounded,
                  color: AppColors.deepBlue, size: 20),
              const SizedBox(width: 8),
              Text(
                'Karcis Tertunda',
                style: GoogleFonts.outfit(
                  color: AppColors.ink,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: AppColors.deepBlue,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  '${_tickets.length}',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          for (final b in _tickets) _ticketRow(b),
        ],
      ),
    );
  }

  Widget _ticketRow(Map<String, dynamic> b) {
    Map<String, dynamic> m(dynamic v) =>
        (v as Map?)?.cast<String, dynamic>() ?? const {};
    final driver = m(b['driver']);
    final profile = m(driver['driver_profile']);
    final ready = b['pickup_confirmed_at'] != null;
    final created = DateTime.tryParse('${b['created_at'] ?? ''}')?.toLocal();
    final waited =
        created == null ? Duration.zero : DateTime.now().difference(created);
    final late = !ready && waited >= CsoTicketSheet.lateAfter;
    final color = ready
        ? AppColors.success
        : (late ? AppColors.warning : AppColors.skyBlue);
    final line = profile['line_number'];

    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () async {
        await CsoTicketSheet.show(context, b);
        if (mounted) _loadTickets();
      },
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(
                ready
                    ? Icons.print_rounded
                    : (late
                        ? Icons.priority_high_rounded
                        : Icons.hourglass_top_rounded),
                color: color,
                size: 21,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${line == null ? '' : '#L$line · '}${driver['name'] ?? '-'}',
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: AppColors.ink,
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  Text(
                    'Bandara → ${m(b['zone_to'])['name'] ?? '-'}',
                    overflow: TextOverflow.ellipsis,
                    style:
                        const TextStyle(color: AppColors.inkSoft, fontSize: 12.5),
                  ),
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                ready ? 'Karcis siap' : (late ? 'Terlambat' : 'Menunggu'),
                style: TextStyle(
                  color: color == AppColors.warning
                      ? const Color(0xFFB86A00)
                      : color,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            const SizedBox(width: 4),
            const Icon(Icons.chevron_right_rounded, color: AppColors.inkFaint),
          ],
        ),
      ),
    );
  }

  Widget _recentCard() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.history_rounded, color: AppColors.deepBlue, size: 20),
              SizedBox(width: 8),
              Text(
                'Transaksi Terakhir',
                style: TextStyle(
                  color: AppColors.ink,
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          if (_recent.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Center(
                child: Text(
                  'Belum ada transaksi.',
                  style: TextStyle(
                    color: AppColors.inkSoft,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            )
          else
            ..._recent.map(_recentTile),
        ],
      ),
    );
  }

  Widget _recentTile(CsoTransaction tx) {
    final time = tx.createdAt?.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    final timeStr = time == null
        ? ''
        : '${two(time.day)}/${two(time.month)} ${two(time.hour)}:${two(time.minute)}';
    final methodLabel = tx.method == 'CashDriver'
        ? 'Tunai (Supir)'
        : (tx.method == 'CashCSO' ? 'Tunai (Kasir)' : 'QRIS');

    return InkWell(
      onTap: () => CsoReceiptSheet.show(context, tx.receiptData),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: AppColors.paleBlue,
                borderRadius: BorderRadius.circular(11),
              ),
              child: const Icon(
                Icons.local_taxi_rounded,
                color: AppColors.deepBlue,
                size: 19,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Bandara → ${tx.zoneName}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: AppColors.ink,
                      fontWeight: FontWeight.w700,
                      fontSize: 13.5,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '$methodLabel · $timeStr',
                    style: const TextStyle(
                      color: AppColors.inkSoft,
                      fontSize: 11.5,
                    ),
                  ),
                ],
              ),
            ),
            Text(
              formatRupiah(tx.amount),
              style: const TextStyle(
                color: AppColors.deepBlue,
                fontWeight: FontWeight.w800,
                fontSize: 13.5,
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _showDriverInfo(_DriverLoc d) {
    final bool ontrip = d.status == 'ontrip';
    final Color accent = ontrip
        ? AppColors.skyBlue
        : (d.isReady ? AppColors.success : AppColors.inkFaint);
    final String statusLabel = ontrip
        ? 'Sedang Mengantar'
        : (d.isReady ? 'Siap Menerima Order' : d.status);
    final IconData statusIcon = ontrip
        ? Icons.directions_car_filled_rounded
        : (d.isReady
            ? Icons.check_circle_rounded
            : Icons.pause_circle_filled_rounded);

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (sheetCtx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 44,
              height: 5,
              margin: const EdgeInsets.only(top: 12, bottom: 4),
              decoration: BoxDecoration(
                color: AppColors.inkFaint.withValues(alpha: 0.35),
                borderRadius: BorderRadius.circular(10),
              ),
            ),
            // === HERO: warna & ikon mengikuti status supir ===
            Container(
              width: double.infinity,
              margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [accent, accent.withValues(alpha: 0.72)],
                ),
                borderRadius: BorderRadius.circular(20),
                boxShadow: [
                  BoxShadow(
                    color: accent.withValues(alpha: 0.32),
                    blurRadius: 20,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Row(
                children: [
                  Container(
                    width: 60,
                    height: 60,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.12),
                          blurRadius: 8,
                          offset: const Offset(0, 3),
                        ),
                      ],
                    ),
                    child: d.line != null
                        ? Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                '#${d.line}',
                                style: GoogleFonts.outfit(
                                  color: accent,
                                  fontWeight: FontWeight.w900,
                                  fontSize: 18,
                                  height: 1,
                                ),
                              ),
                              Text(
                                'ANTRI',
                                style: GoogleFonts.outfit(
                                  color: accent.withValues(alpha: 0.7),
                                  fontSize: 7.5,
                                  fontWeight: FontWeight.w700,
                                  letterSpacing: 0.5,
                                ),
                              ),
                            ],
                          )
                        : Icon(Icons.person_rounded, color: accent, size: 28),
                  ),
                  const SizedBox(width: 15),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          d.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: GoogleFonts.outfit(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 19,
                          ),
                        ),
                        const SizedBox(height: 5),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.22),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(statusIcon, color: Colors.white, size: 13),
                              const SizedBox(width: 5),
                              Text(
                                statusLabel,
                                style: GoogleFonts.outfit(
                                  color: Colors.white,
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            // === DETAIL: tile berikon ===
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
              child: Column(
                children: [
                  _driverDetailTile(
                    Icons.directions_car_filled_rounded,
                    'Kendaraan',
                    d.car ?? '-',
                    AppColors.skyBlue,
                  ),
                  _driverDetailTile(
                    Icons.confirmation_number_rounded,
                    'Plat Nomor',
                    d.plate ?? '-',
                    AppColors.deepBlue,
                  ),
                  _driverDetailTile(
                    d.isStale
                        ? Icons.gps_off_rounded
                        : Icons.gps_fixed_rounded,
                    'Status Lokasi',
                    d.isStale
                        ? 'Basi • ${d.lastSeen}'
                        : 'Terkini • ${d.lastSeen}',
                    d.isStale ? AppColors.warning : AppColors.success,
                    highlight: d.isStale,
                  ),
                ],
              ),
            ),
            if (d.isStale)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 2, 20, 0),
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.warning.withValues(alpha: 0.10),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: AppColors.warning.withValues(alpha: 0.35),
                    ),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.warning_amber_rounded,
                          color: AppColors.warning, size: 18),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'Posisi supir belum diperbarui — jangan diandalkan untuk penjemputan.',
                          style: GoogleFonts.outfit(
                            color: AppColors.inkSoft,
                            fontSize: 11.5,
                            height: 1.35,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            SizedBox(height: MediaQuery.of(sheetCtx).padding.bottom + 20),
          ],
        ),
      ),
    );
  }

  Widget _driverDetailTile(
    IconData icon,
    String label,
    String value,
    Color color, {
    bool highlight = false,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: highlight ? color.withValues(alpha: 0.08) : AppColors.background,
        borderRadius: BorderRadius.circular(14),
        border: highlight
            ? Border.all(color: color.withValues(alpha: 0.25))
            : null,
      ),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(11),
            ),
            child: Icon(icon, color: color, size: 19),
          ),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.outfit(
                    color: AppColors.inkFaint,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  value,
                  style: GoogleFonts.outfit(
                    color: highlight ? color : AppColors.ink,
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _baseMarker() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.deepBlue,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2),
      ),
      child: const Icon(
        Icons.local_airport_rounded,
        color: Colors.white,
        size: 22,
      ),
    );
  }

  Widget _driverMarker(_DriverLoc d) {
    // ontrip = biru (sedang mengantar), standby = hijau (siap), lainnya = abu.
    final color = d.status == 'ontrip'
        ? AppColors.skyBlue
        : (d.isReady ? AppColors.success : AppColors.inkFaint);
    final label = d.line != null ? '${d.line}' : '•';
    final stale = d.isStale;
    // Lokasi basi (>2 mnt): pudarkan + cincin amber agar CSO tahu posisi tak andal.
    return Opacity(
      opacity: stale ? 0.5 : 1,
      child: Container(
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          border: Border.all(
            color: stale ? AppColors.warning : Colors.white,
            width: stale ? 2.5 : 2,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x33000000),
              blurRadius: 5,
              offset: Offset(0, 2),
            ),
          ],
        ),
        child: Text(
          label,
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w800,
            fontSize: 12,
          ),
        ),
      ),
    );
  }

  Widget _circleBtn(IconData icon, VoidCallback onTap) {
    return Material(
      color: AppColors.surface,
      shape: const CircleBorder(),
      elevation: 3,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Icon(icon, color: AppColors.deepBlue, size: 20),
        ),
      ),
    );
  }
}

/// Angka rupiah yang "menghitung naik" ke nilai baru setiap kali data
/// berubah (TweenAnimationBuilder melanjutkan dari nilai yang sedang tampil).
class _AngkaNaik extends StatelessWidget {
  final num nilai;
  final TextStyle style;
  const _AngkaNaik({required this.nilai, required this.style});

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: nilai.toDouble()),
      duration: const Duration(milliseconds: 750),
      curve: Curves.easeOutCubic,
      builder: (_, v, _) => FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: Text(formatRupiah(v), style: style),
      ),
    );
  }
}

/// Denyut halus (skala atau kedip) untuk hal yang perlu dilirik: badge
/// GILIRAN, pengingat setoran yang sudah lama, dan kerangka loading.
class _Denyut extends StatefulWidget {
  final Widget child;
  final bool aktif;
  final bool skala;
  const _Denyut({required this.child, this.aktif = true, this.skala = true});

  @override
  State<_Denyut> createState() => _DenyutState();
}

class _DenyutState extends State<_Denyut> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900));

  @override
  void initState() {
    super.initState();
    if (widget.aktif) _c.repeat(reverse: true);
  }

  @override
  void didUpdateWidget(_Denyut old) {
    super.didUpdateWidget(old);
    if (widget.aktif && !_c.isAnimating) {
      _c.repeat(reverse: true);
    } else if (!widget.aktif && _c.isAnimating) {
      _c.stop();
      _c.value = 0;
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final anim = CurvedAnimation(parent: _c, curve: Curves.easeInOut);
    return RepaintBoundary(
      child: widget.skala
          ? ScaleTransition(scale: Tween(begin: 1.0, end: 1.08).animate(anim), child: widget.child)
          : FadeTransition(opacity: Tween(begin: 1.0, end: 0.45).animate(anim), child: widget.child),
    );
  }
}
