import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/zone.dart';
import '../../providers/cso_order_provider.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import '../../widgets/cso/cso_payment_sheet.dart';
import '../../widgets/cso/cso_select_driver_sheet.dart';
import '../../widgets/cso/cso_receipt_sheet.dart';

/// Tab "Pemesanan Baru": pilih zona → Input Pembayaran → pilih supir → struk.
/// UI diperhalus: stepper berikon, kartu zona dengan state terpilih bergradien,
/// dan tombol aksi gradien dengan glow.
class CsoOrderScreen extends StatefulWidget {
  const CsoOrderScreen({super.key});

  @override
  State<CsoOrderScreen> createState() => _CsoOrderScreenState();
}

class _CsoOrderScreenState extends State<CsoOrderScreen> {
  final _searchController = TextEditingController();
  String _query = '';
  String _catFilter = 'semua'; // 'semua' | 'dalam' | 'luar'

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final p = context.read<CsoOrderProvider>();
      p.loadZones();
      p.loadCompanyQris();
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _startPaymentFlow() async {
    final provider = context.read<CsoOrderProvider>();
    final verified = await CsoPaymentSheet.show(context);
    if (verified != true || !mounted) return;

    final result = await CsoSelectDriverSheet.show(context);
    if (result == null || !mounted) return;

    await CsoReceiptSheet.show(context, result);
    if (!mounted) return;
    provider.reset();
    _searchController.clear();
    setState(() {
      _query = '';
      _catFilter = 'semua';
    });
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<CsoOrderProvider>();
    final q = _query.toLowerCase();
    // Cari berdasarkan nama ATAU keterangan area (mis. "juanda" → ZONA 3).
    final filtered = q.isEmpty
        ? provider.zones
        : provider.zones
              .where(
                (z) =>
                    z.name.toLowerCase().contains(q) ||
                    (z.description ?? '').toLowerCase().contains(q),
              )
              .toList();
    final dalam = filtered.where((z) => !z.isLuarKota).toList();
    final luar = filtered.where((z) => z.isLuarKota).toList();

    return Column(
      children: [
        _stepper(provider.completedStep),
        _searchField(),
        _categoryChips(dalam.length, luar.length),
        Expanded(child: _zoneArea(provider, dalam, luar)),
        _bottomBar(provider),
      ],
    );
  }

  // ---- Stepper ----
  Widget _stepper(int completed) {
    const labels = ['Tujuan', 'Bayar', 'Supir'];
    const icons = [
      Icons.place_rounded,
      Icons.payments_rounded,
      Icons.person_rounded,
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
      child: Row(
        children: List.generate(5, (i) {
          if (i.isOdd) {
            final done = completed >= (i + 1) ~/ 2;
            return Expanded(
              child: Container(
                height: 3,
                margin: const EdgeInsets.only(bottom: 22),
                decoration: BoxDecoration(
                  gradient: done ? AppColors.buttonGradient : null,
                  color: done ? null : AppColors.paleBlue,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            );
          }
          final idx = i ~/ 2;
          final active = completed >= idx;
          return Column(
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 300),
                width: 38,
                height: 38,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  gradient: active ? AppColors.buttonGradient : null,
                  color: active ? null : AppColors.paleBlue,
                  shape: BoxShape.circle,
                  boxShadow: active
                      ? [
                          BoxShadow(
                            color: AppColors.cyan.withValues(alpha: 0.4),
                            blurRadius: 10,
                            offset: const Offset(0, 4),
                          ),
                        ]
                      : null,
                ),
                child: Icon(
                  icons[idx],
                  color: active ? Colors.white : AppColors.inkFaint,
                  size: 18,
                ),
              ),
              const SizedBox(height: 5),
              Text(
                labels[idx],
                style: TextStyle(
                  color: active ? AppColors.deepBlue : AppColors.inkFaint,
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          );
        }),
      ),
    );
  }

  // ---- Search ----
  Widget _searchField() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 10),
      child: TextField(
        controller: _searchController,
        onChanged: (v) => setState(() => _query = v),
        decoration: InputDecoration(
          hintText: 'Cari tujuan...',
          prefixIcon: const Icon(
            Icons.search_rounded,
            color: AppColors.inkFaint,
          ),
          suffixIcon: _query.isEmpty
              ? null
              : IconButton(
                  icon: const Icon(
                    Icons.close_rounded,
                    color: AppColors.inkFaint,
                    size: 20,
                  ),
                  onPressed: () {
                    _searchController.clear();
                    setState(() => _query = '');
                  },
                ),
          isDense: true,
          filled: true,
          fillColor: AppColors.surface,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(16),
            borderSide: BorderSide.none,
          ),
        ),
      ),
    );
  }

  // ---- Filter kategori (warna ala poster: biru = dalam, amber = luar) ----
  Widget _categoryChips(int nDalam, int nLuar) {
    Widget chip({
      required String value,
      required String label,
      required IconData icon,
      required int count,
      required List<Color> activeColors,
    }) {
      final active = _catFilter == value;
      return Expanded(
        child: GestureDetector(
          onTap: () => setState(() => _catFilter = value),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 240),
            curve: Curves.easeOut,
            padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 4),
            decoration: BoxDecoration(
              gradient: active ? LinearGradient(colors: activeColors) : null,
              color: active ? null : AppColors.surface,
              borderRadius: BorderRadius.circular(13),
              boxShadow: [
                BoxShadow(
                  color: active
                      ? activeColors.last.withValues(alpha: 0.38)
                      : const Color(0x0F0B4DA2),
                  blurRadius: active ? 12 : 8,
                  offset: Offset(0, active ? 4 : 3),
                ),
              ],
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  icon,
                  size: 14,
                  color: active ? Colors.white : AppColors.inkSoft,
                ),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: active ? Colors.white : AppColors.inkSoft,
                      fontWeight: FontWeight.w700,
                      fontSize: 11,
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 1.5,
                  ),
                  decoration: BoxDecoration(
                    color: active
                        ? Colors.white.withValues(alpha: 0.28)
                        : AppColors.paleBlue,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    '$count',
                    style: TextStyle(
                      fontSize: 9.5,
                      fontWeight: FontWeight.w800,
                      color: active ? Colors.white : AppColors.inkSoft,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Row(
        children: [
          chip(
            value: 'semua',
            label: 'Semua',
            icon: Icons.grid_view_rounded,
            count: nDalam + nLuar,
            activeColors: const [AppColors.deepBlue, AppColors.skyBlue],
          ),
          const SizedBox(width: 8),
          chip(
            value: 'dalam',
            label: 'Dalam Kota',
            icon: Icons.location_city_rounded,
            count: nDalam,
            activeColors: const [AppColors.skyBlue, AppColors.cyan],
          ),
          const SizedBox(width: 8),
          chip(
            value: 'luar',
            label: 'Luar Kota',
            icon: Icons.forest_rounded,
            count: nLuar,
            activeColors: const [Color(0xFFD97706), Color(0xFFF59E0B)],
          ),
        ],
      ),
    );
  }

  // Animasi masuk berjenjang (fade + naik) untuk header & kartu zona.
  Widget _animIn(int index, Widget child) {
    final start = (index * 0.055).clamp(0.0, 0.55);
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 640),
      curve: Interval(start, 1, curve: Curves.easeOutCubic),
      builder: (context, t, c) => Opacity(
        opacity: t.clamp(0.0, 1.0),
        child: Transform.translate(offset: Offset(0, 16 * (1 - t)), child: c),
      ),
      child: child,
    );
  }

  // ---- Zona (dikelompokkan Dalam/Luar Kota ala poster tarif) ----
  Widget _zoneArea(
    CsoOrderProvider provider,
    List<Zone> dalam,
    List<Zone> luar,
  ) {
    if (provider.loadingZones) {
      return const Center(child: CircularProgressIndicator());
    }
    if (provider.zonesError != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              provider.zonesError!,
              style: const TextStyle(
                color: AppColors.danger,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 10),
            TextButton(
              onPressed: provider.loadZones,
              child: const Text('Coba lagi'),
            ),
          ],
        ),
      );
    }
    final showDalam = _catFilter != 'luar' && dalam.isNotEmpty;
    final showLuar = _catFilter != 'dalam' && luar.isNotEmpty;
    if (!showDalam && !showLuar) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 64,
              height: 64,
              decoration: const BoxDecoration(
                color: AppColors.paleBlue,
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.search_off_rounded,
                size: 30,
                color: AppColors.inkFaint,
              ),
            ),
            const SizedBox(height: 12),
            const Text(
              'Tidak ada tujuan ditemukan.',
              style: TextStyle(
                color: AppColors.inkSoft,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 3),
            const Text(
              'Coba kata kunci atau kategori lain.',
              style: TextStyle(color: AppColors.inkFaint, fontSize: 12),
            ),
          ],
        ),
      );
    }

    final slivers = <Widget>[];
    var idx = 0;
    if (showDalam) {
      slivers.add(
        SliverToBoxAdapter(
          child: _animIn(
            idx++,
            _sectionHeader(
              'Dalam Kota Samarinda',
              dalam.length,
              Icons.location_city_rounded,
              AppColors.deepBlue,
              AppColors.paleBlue,
            ),
          ),
        ),
      );
      slivers.add(_zoneGrid(provider, dalam, baseIndex: idx));
      idx += dalam.length;
    }
    if (showLuar) {
      slivers.add(
        SliverToBoxAdapter(
          child: _animIn(
            idx++,
            _sectionHeader(
              'Luar Kota Samarinda',
              luar.length,
              Icons.forest_rounded,
              const Color(0xFFB45309),
              const Color(0xFFFDF1DC),
            ),
          ),
        ),
      );
      slivers.add(_zoneGrid(provider, luar, baseIndex: idx));
    }

    // Key per kategori → ganti filter memutar ulang animasi masuk.
    return CustomScrollView(key: ValueKey(_catFilter), slivers: slivers);
  }

  Widget _sectionHeader(
    String title,
    int count,
    IconData icon,
    Color color,
    Color bg,
  ) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 10),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: bg,
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, size: 16, color: color),
          ),
          const SizedBox(width: 8),
          Text(
            title,
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w800,
              fontSize: 12.5,
              letterSpacing: 0.2,
            ),
          ),
          const SizedBox(width: 7),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1.5),
            decoration: BoxDecoration(
              color: bg,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              '$count',
              style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                color: color,
              ),
            ),
          ),
          Expanded(
            child: Container(
              height: 2,
              margin: const EdgeInsets.only(left: 10),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(2),
                gradient: LinearGradient(
                  colors: [color.withValues(alpha: 0.3), Colors.transparent],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _zoneGrid(
    CsoOrderProvider provider,
    List<Zone> zones, {
    required int baseIndex,
  }) {
    return SliverPadding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      sliver: SliverGrid(
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisExtent: 134,
          crossAxisSpacing: 12,
          mainAxisSpacing: 12,
        ),
        delegate: SliverChildBuilderDelegate(
          (context, i) =>
              _animIn(baseIndex + i, _zoneCard(provider, zones[i])),
          childCount: zones.length,
        ),
      ),
    );
  }

  Widget _zoneCard(CsoOrderProvider provider, Zone zone) {
    final selected = provider.selectedZone?.id == zone.id;
    final luar = zone.isLuarKota;
    // Aksen per kategori — biru (dalam kota) / amber (luar kota) ala poster.
    final accent = luar ? const Color(0xFFD97706) : AppColors.skyBlue;
    final accentDark = luar ? const Color(0xFFB45309) : AppColors.deepBlue;
    final paleBg = luar ? const Color(0xFFFDF1DC) : AppColors.paleBlue;
    final gradient = luar
        ? const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFFB45309), Color(0xFFD97706), Color(0xFFF59E0B)],
          )
        : AppColors.skyGradient;

    return GestureDetector(
      onTap: () => provider.selectZone(zone),
      child: AnimatedScale(
        scale: selected ? 1.03 : 1.0,
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOutBack,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            gradient: selected ? gradient : null,
            color: selected ? null : AppColors.surface,
            borderRadius: BorderRadius.circular(18),
            boxShadow: [
              BoxShadow(
                color: selected
                    ? accent.withValues(alpha: 0.4)
                    : const Color(0x0F0B4DA2),
                blurRadius: selected ? 16 : 10,
                offset: Offset(0, selected ? 6 : 4),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(
                      color: selected
                          ? Colors.white.withValues(alpha: 0.25)
                          : paleBg,
                      borderRadius: BorderRadius.circular(11),
                    ),
                    child: Icon(
                      luar
                          ? Icons.forest_rounded
                          : Icons.location_city_rounded,
                      size: 19,
                      color: selected ? Colors.white : accent,
                    ),
                  ),
                  AnimatedScale(
                    scale: selected ? 1 : 0,
                    duration: const Duration(milliseconds: 240),
                    curve: Curves.easeOutBack,
                    child: Container(
                      width: 24,
                      height: 24,
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        Icons.check_rounded,
                        size: 16,
                        color: accentDark,
                      ),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Text(
                zone.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: selected ? Colors.white : AppColors.ink,
                  fontWeight: FontWeight.w800,
                  fontSize: 13.5,
                  height: 1.2,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                zone.description ??
                    (luar ? 'Luar Kota Samarinda' : 'Dalam Kota Samarinda'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: selected
                      ? Colors.white.withValues(alpha: 0.85)
                      : AppColors.inkFaint,
                  fontWeight: FontWeight.w600,
                  fontSize: 10.5,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                formatRupiah(zone.price),
                style: TextStyle(
                  color: selected ? Colors.white : accentDark,
                  fontWeight: FontWeight.w800,
                  fontSize: 14.5,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ---- Bottom bar ----
  Widget _bottomBar(CsoOrderProvider provider) {
    final zone = provider.selectedZone;
    final enabled = zone != null;
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        boxShadow: [
          BoxShadow(
            color: Color(0x140B4DA2),
            blurRadius: 18,
            offset: Offset(0, -5),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Tarif',
                    style: TextStyle(color: AppColors.inkSoft, fontSize: 12),
                  ),
                  const SizedBox(height: 2),
                  AnimatedSwitcher(
                    duration: const Duration(milliseconds: 220),
                    transitionBuilder: (child, anim) =>
                        ScaleTransition(scale: anim, child: child),
                    child: Text(
                      enabled ? formatRupiah(zone.price) : '-',
                      key: ValueKey(zone?.id ?? 0),
                      style: const TextStyle(
                        color: AppColors.deepBlue,
                        fontWeight: FontWeight.w800,
                        fontSize: 22,
                      ),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              if (enabled)
                Flexible(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            zone.isLuarKota
                                ? Icons.forest_rounded
                                : Icons.location_city_rounded,
                            color: zone.isLuarKota
                                ? const Color(0xFFD97706)
                                : AppColors.skyBlue,
                            size: 15,
                          ),
                          const SizedBox(width: 4),
                          Flexible(
                            child: Text(
                              zone.name,
                              textAlign: TextAlign.right,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: AppColors.inkSoft,
                                fontSize: 12.5,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                      ),
                      if (zone.description != null)
                        Text(
                          zone.description!,
                          textAlign: TextAlign.right,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: AppColors.inkFaint,
                            fontSize: 10.5,
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          _gradientButton(
            label: 'Input Pembayaran',
            enabled: enabled,
            onTap: _startPaymentFlow,
          ),
        ],
      ),
    );
  }

  Widget _gradientButton({
    required String label,
    required bool enabled,
    required VoidCallback onTap,
  }) {
    return SizedBox(
      width: double.infinity,
      height: 54,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: enabled ? AppColors.buttonGradient : null,
          color: enabled ? null : AppColors.inkFaint,
          borderRadius: BorderRadius.circular(16),
          boxShadow: enabled
              ? [
                  BoxShadow(
                    color: AppColors.cyan.withValues(alpha: 0.4),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ]
              : null,
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: enabled ? onTap : null,
            child: Center(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    label,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 16,
                    ),
                  ),
                  const SizedBox(width: 8),
                  const Icon(
                    Icons.arrow_forward_rounded,
                    color: Colors.white,
                    size: 20,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
