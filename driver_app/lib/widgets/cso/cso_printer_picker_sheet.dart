import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import '../../services/thermal_printer_service.dart';
import '../../theme/app_colors.dart';

/// Bottom sheet memilih printer thermal Bluetooth dari perangkat yang SUDAH
/// di-pair di Pengaturan Bluetooth HP. Pop `true` bila berhasil dipilih+sambung.
class CsoPrinterPickerSheet extends StatefulWidget {
  const CsoPrinterPickerSheet({super.key});

  static Future<bool?> show(BuildContext context) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => const CsoPrinterPickerSheet(),
    );
  }

  @override
  State<CsoPrinterPickerSheet> createState() => _CsoPrinterPickerSheetState();
}

class _CsoPrinterPickerSheetState extends State<CsoPrinterPickerSheet> {
  bool _loading = true;
  bool _btOff = false;
  bool _permDenied = false;
  String? _connectingMac;
  String? _savedMac;
  List<BluetoothInfo> _devices = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _btOff = false;
      _permDenied = false;
    });

    // Izin Bluetooth (Android 12+).
    final statuses = await [
      Permission.bluetoothConnect,
      Permission.bluetoothScan,
    ].request();
    final granted = statuses.values.every((s) => s.isGranted);
    if (!granted) {
      if (mounted) {
        setState(() {
          _loading = false;
          _permDenied = true;
        });
      }
      return;
    }

    if (!await ThermalPrinterService.btEnabled()) {
      if (mounted) {
        setState(() {
          _loading = false;
          _btOff = true;
        });
      }
      return;
    }

    final devices = await ThermalPrinterService.pairedDevices();
    final saved = await ThermalPrinterService.savedMac();
    if (!mounted) return;
    setState(() {
      _devices = devices;
      _savedMac = saved;
      _loading = false;
    });
  }

  Future<void> _select(BluetoothInfo d) async {
    setState(() => _connectingMac = d.macAdress);
    await ThermalPrinterService.savePrinter(d.macAdress, d.name);
    final ok = await ThermalPrinterService.connect(d.macAdress);
    if (!mounted) return;
    setState(() => _connectingMac = null);
    if (ok) {
      Navigator.of(context).pop(true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Gagal menyambung. Pastikan printer menyala.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
        left: 20,
        right: 20,
        top: 12,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 44,
              height: 5,
              decoration: BoxDecoration(
                color: AppColors.inkFaint.withValues(alpha: 0.4),
                borderRadius: BorderRadius.circular(3),
              ),
            ),
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              const Icon(Icons.print_rounded, color: AppColors.skyBlue),
              const SizedBox(width: 10),
              const Text('Pilih Printer',
                  style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink)),
              const Spacer(),
              IconButton(
                onPressed: _loading ? null : _load,
                icon: const Icon(Icons.refresh_rounded, color: AppColors.inkSoft),
              ),
            ],
          ),
          const Text(
            'Pair printer di Pengaturan Bluetooth HP dulu, lalu pilih di sini.',
            style: TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
          ),
          const SizedBox(height: 16),
          _body(),
        ],
      ),
    );
  }

  Widget _body() {
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(30),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_permDenied) {
      return _msg('Izin Bluetooth ditolak. Aktifkan izin Bluetooth di pengaturan aplikasi.',
          Icons.block_rounded);
    }
    if (_btOff) {
      return _msg('Bluetooth mati. Nyalakan Bluetooth lalu tekan segarkan.',
          Icons.bluetooth_disabled_rounded);
    }
    if (_devices.isEmpty) {
      return _msg('Belum ada perangkat ter-pair. Pair printer di Pengaturan Bluetooth HP dulu.',
          Icons.bluetooth_searching_rounded);
    }
    return ConstrainedBox(
      constraints:
          BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.45),
      child: ListView.separated(
        shrinkWrap: true,
        itemCount: _devices.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (_, i) {
          final d = _devices[i];
          final isSaved = d.macAdress == _savedMac;
          final connecting = d.macAdress == _connectingMac;
          return Material(
            color: AppColors.background,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: connecting ? null : () => _select(d),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                child: Row(
                  children: [
                    Icon(Icons.print_rounded,
                        color: isSaved ? AppColors.success : AppColors.skyBlue),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(d.name.isEmpty ? '(tanpa nama)' : d.name,
                              style: const TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.ink,
                                  fontSize: 15)),
                          Text(d.macAdress,
                              style: const TextStyle(
                                  color: AppColors.inkFaint, fontSize: 11)),
                        ],
                      ),
                    ),
                    if (connecting)
                      const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                    else if (isSaved)
                      const Icon(Icons.check_circle_rounded,
                          color: AppColors.success, size: 20),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _msg(String text, IconData icon) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 24),
      child: Column(
        children: [
          Icon(icon, size: 44, color: AppColors.inkFaint),
          const SizedBox(height: 12),
          Text(text,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: AppColors.inkSoft, fontSize: 13.5, height: 1.4)),
        ],
      ),
    );
  }
}
