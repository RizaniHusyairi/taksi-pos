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
    setState(() => _query = '');
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<CsoOrderProvider>();
    final zones = _query.isEmpty
        ? provider.zones
        : provider.zones
              .where((z) => z.name.toLowerCase().contains(_query.toLowerCase()))
              .toList();

    return Column(
      children: [
        _stepper(provider.completedStep),
        _searchField(),
        Expanded(child: _zoneArea(provider, zones)),
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

  // ---- Zona ----
  Widget _zoneArea(CsoOrderProvider provider, List<Zone> zones) {
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
    if (zones.isEmpty) {
      return const Center(
        child: Text(
          'Tidak ada tujuan ditemukan.',
          style: TextStyle(
            color: AppColors.inkSoft,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
    }
    return GridView.builder(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisExtent: 128,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
      ),
      itemCount: zones.length,
      itemBuilder: (context, i) => _zoneCard(provider, zones[i]),
    );
  }

  Widget _zoneCard(CsoOrderProvider provider, Zone zone) {
    final selected = provider.selectedZone?.id == zone.id;
    return GestureDetector(
      onTap: () => provider.selectZone(zone),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          gradient: selected ? AppColors.skyGradient : null,
          color: selected ? null : AppColors.surface,
          borderRadius: BorderRadius.circular(18),
          boxShadow: [
            BoxShadow(
              color: selected
                  ? AppColors.cyan.withValues(alpha: 0.35)
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
                        : AppColors.paleBlue,
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: Icon(
                    Icons.place_rounded,
                    size: 19,
                    color: selected ? Colors.white : AppColors.skyBlue,
                  ),
                ),
                if (selected)
                  Container(
                    width: 24,
                    height: 24,
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.check_rounded,
                      size: 16,
                      color: AppColors.deepBlue,
                    ),
                  ),
              ],
            ),
            const Spacer(),
            Text(
              zone.name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: selected ? Colors.white : AppColors.ink,
                fontWeight: FontWeight.w700,
                fontSize: 13.5,
                height: 1.2,
              ),
            ),
            const SizedBox(height: 3),
            Text(
              formatRupiah(zone.price),
              style: TextStyle(
                color: selected ? Colors.white : AppColors.deepBlue,
                fontWeight: FontWeight.w800,
                fontSize: 14.5,
              ),
            ),
          ],
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
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(
                        Icons.place_rounded,
                        color: AppColors.skyBlue,
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
