import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:latlong2/latlong.dart';

import '../services/api_service.dart';
import '../theme/app_colors.dart';
import 'app_card.dart';

/// Peta kecil di beranda supir: posisi supir sendiri + lingkaran area bandara.
///
/// Supir bisa langsung melihat seberapa jauh dia dari batas geofence — angka
/// jarak saja tidak memberi tahu ARAH mana yang mendekatkannya ke bandara.
///
/// Titik pusat & radius diambil sekali dari `/driver/airport-area` (nyaris tak
/// pernah berubah), sementara posisi supir datang dari [lat]/[lng] yang sudah
/// dilacak beranda — widget ini sengaja TIDAK meminta GPS sendiri agar tidak
/// menambah beban baterai.
class DriverAreaMap extends StatefulWidget {
  final double? lat;
  final double? lng;

  /// Status area dari server (`in_area`). Dipakai sebagai sumber kebenaran
  /// untuk warna penanda; hitungan jarak di sini hanya untuk tampilan.
  final bool inArea;

  /// Ditampilkan saat GPS belum memberi titik apa pun.
  final String locationStatus;

  const DriverAreaMap({
    super.key,
    required this.lat,
    required this.lng,
    required this.inArea,
    required this.locationStatus,
  });

  @override
  State<DriverAreaMap> createState() => _DriverAreaMapState();
}

class _DriverAreaMapState extends State<DriverAreaMap> {
  static const LatLng _defaultBase = LatLng(-0.371975, 117.257919);

  final ApiService _api = ApiService();
  final MapController _map = MapController();

  LatLng _base = _defaultBase;
  double _radiusKm = 2.6;
  bool _loading = true;
  bool _followDriver = true;

  /// Gagal mengambil geofence dari server → lingkaran di peta digambar dari
  /// nilai bawaan dan bisa BERBEDA dari radius yang dipakai server. Wajib
  /// dijujurkan ke supir: kalau tidak, ia bisa melihat dirinya di dalam
  /// lingkaran sementara server menyatakan "di luar area" dan antriannya jalan.
  bool _areaFailed = false;

  @override
  void initState() {
    super.initState();
    _loadArea();
  }

  Future<void> _loadArea() async {
    try {
      final res = await _api.getAirportArea();
      final base = (res.data?['base'] ?? {}) as Map;
      final lat = base['latitude'], lng = base['longitude'];
      if (!mounted) return;
      setState(() {
        if (lat is num && lng is num) _base = LatLng(lat.toDouble(), lng.toDouble());
        if (base['radius_km'] is num) _radiusKm = (base['radius_km'] as num).toDouble();
        _loading = false;
        _areaFailed = false;
      });
      _fit();
    } catch (_) {
      // Gagal ambil geofence bukan alasan menyembunyikan peta — pakai nilai
      // bawaan, tetap tampilkan posisi supir, tapi TANDAI bahwa lingkarannya
      // hanya perkiraan (lihat [_areaFailed]).
      if (mounted) {
        setState(() {
          _loading = false;
          _areaFailed = true;
        });
      }
    }
  }

  LatLng? get _driver =>
      (widget.lat != null && widget.lng != null) ? LatLng(widget.lat!, widget.lng!) : null;

  /// Jarak supir ke titik pusat (km), null bila GPS belum siap.
  double? get _distanceKm {
    final d = _driver;
    if (d == null) return null;
    return const Distance().as(LengthUnit.Kilometer, d, _base).toDouble();
  }

  @override
  void didUpdateWidget(covariant DriverAreaMap old) {
    super.didUpdateWidget(old);
    // Ikuti supir hanya bila dia belum menggeser peta sendiri.
    if (_followDriver && _driver != null &&
        (old.lat != widget.lat || old.lng != widget.lng)) {
      _fit();
    }
  }

  /// Bingkai peta agar pusat bandara DAN posisi supir sama-sama terlihat.
  void _fit() {
    final d = _driver;
    if (!mounted) return;
    try {
      if (d == null) {
        _map.move(_base, 12.5);
        return;
      }
      _map.fitCamera(
        CameraFit.bounds(
          bounds: LatLngBounds(_base, d),
          padding: const EdgeInsets.all(46),
          maxZoom: 15,
        ),
      );
    } catch (_) {
      // Peta belum terpasang saat data datang duluan — abaikan, frame
      // berikutnya akan membingkai ulang.
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _driver;
    final jarak = _distanceKm;

    return AppCard(
      padding: EdgeInsets.zero,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(jarak),
            SizedBox(
              height: 220,
              child: Stack(
                children: [
                  FlutterMap(
                    mapController: _map,
                    options: MapOptions(
                      initialCenter: d ?? _base,
                      initialZoom: 12.5,
                      // Supir cukup melihat; interaksi dibatasi geser + cubit
                      // agar tidak bentrok dengan scroll halaman beranda.
                      interactionOptions: const InteractionOptions(
                        flags: InteractiveFlag.pinchZoom | InteractiveFlag.drag,
                      ),
                      onPositionChanged: (_, hasGesture) {
                        if (hasGesture && _followDriver) {
                          setState(() => _followDriver = false);
                        }
                      },
                    ),
                    children: [
                      TileLayer(
                        // Gaya & sumber tile disamakan dengan peta CSO.
                        urlTemplate:
                            'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                        subdomains: const ['a', 'b', 'c', 'd'],
                        retinaMode: RetinaMode.isHighDensity(context),
                        userAgentPackageName: 'com.example.driver_app',
                      ),
                      CircleLayer(
                        circles: [
                          CircleMarker(
                            point: _base,
                            radius: _radiusKm * 1000,
                            useRadiusInMeter: true,
                            color: AppColors.skyBlue.withValues(alpha: 0.08),
                            borderColor: AppColors.skyBlue.withValues(alpha: 0.45),
                            borderStrokeWidth: 1.5,
                          ),
                        ],
                      ),
                      MarkerLayer(
                        markers: [
                          Marker(
                            point: _base,
                            width: 38,
                            height: 38,
                            child: _airportMarker(),
                          ),
                          if (d != null)
                            Marker(
                              point: d,
                              width: 34,
                              height: 34,
                              child: _driverMarker(),
                            ),
                        ],
                      ),
                      const RichAttributionWidget(
                        attributions: [
                          TextSourceAttribution('© OpenStreetMap, CARTO'),
                        ],
                      ),
                    ],
                  ),
                  if (_loading)
                    const Positioned(
                      top: 10,
                      left: 10,
                      child: SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    ),
                  if (d == null) _gpsOverlay(),
                  if (!_followDriver && d != null)
                    Positioned(
                      right: 10,
                      bottom: 10,
                      child: _recenterButton(),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(double? jarak) {
    final warna = widget.inArea ? AppColors.success : AppColors.warning;
    final label = widget.inArea ? 'Di dalam area' : 'Di luar area';

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 16, 14, 12),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Area Bandara',
                  style: GoogleFonts.outfit(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: AppColors.ink,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  jarak == null
                      ? 'Radius ${_fmt(_radiusKm)} km dari terminal'
                      : '${_fmt(jarak)} km dari titik pusat · radius ${_fmt(_radiusKm)} km',
                  style: GoogleFonts.outfit(
                    fontSize: 11.5,
                    color: AppColors.inkSoft,
                  ),
                ),
                // Lingkaran hanya perkiraan bila geofence gagal diambil —
                // status "Di dalam/luar area" di sebelah kanan tetap dari
                // server, jadi keduanya bisa terlihat bertentangan.
                if (_areaFailed) ...[
                  const SizedBox(height: 2),
                  Text(
                    'Perkiraan — area belum termuat, tarik ulang beranda',
                    style: GoogleFonts.outfit(
                      fontSize: 10.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.warning,
                    ),
                  ),
                ],
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: warna.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: warna.withValues(alpha: 0.35)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  widget.inArea
                      ? Icons.check_circle_rounded
                      : Icons.error_outline_rounded,
                  size: 13,
                  color: warna,
                ),
                const SizedBox(width: 5),
                Text(
                  label,
                  style: GoogleFonts.outfit(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: warna,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Satu desimal, koma sebagai pemisah desimal (kebiasaan Indonesia).
  String _fmt(double v) => v.toStringAsFixed(1).replaceAll('.', ',');

  Widget _airportMarker() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.deepBlue,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: const [
          BoxShadow(color: Color(0x33000000), blurRadius: 5, offset: Offset(0, 2)),
        ],
      ),
      child: const Icon(
        Icons.local_airport_rounded,
        color: Colors.white,
        size: 20,
      ),
    );
  }

  Widget _driverMarker() {
    final warna = widget.inArea ? AppColors.success : AppColors.warning;
    return Container(
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: warna,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2.5),
        boxShadow: const [
          BoxShadow(color: Color(0x33000000), blurRadius: 6, offset: Offset(0, 2)),
        ],
      ),
      child: const Icon(
        Icons.local_taxi_rounded,
        color: Colors.white,
        size: 17,
      ),
    );
  }

  Widget _gpsOverlay() {
    return Positioned.fill(
      child: Container(
        color: Colors.white.withValues(alpha: 0.72),
        alignment: Alignment.center,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.gps_not_fixed_rounded,
              color: AppColors.inkFaint,
              size: 26,
            ),
            const SizedBox(height: 6),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Text(
                widget.locationStatus,
                textAlign: TextAlign.center,
                style: GoogleFonts.outfit(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.inkSoft,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _recenterButton() {
    return Material(
      color: Colors.white,
      shape: const CircleBorder(),
      elevation: 3,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: () {
          setState(() => _followDriver = true);
          _fit();
        },
        child: const Padding(
          padding: EdgeInsets.all(8),
          child: Icon(
            Icons.my_location_rounded,
            size: 19,
            color: AppColors.skyBlue,
          ),
        ),
      ),
    );
  }
}
