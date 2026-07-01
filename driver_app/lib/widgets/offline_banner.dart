import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../services/connectivity_service.dart';

/// Membungkus seluruh aplikasi (via `MaterialApp.builder`). Saat offline,
/// menampilkan bar merah "Tidak ada koneksi internet" di paling atas, mendorong
/// konten turun, dan mengembalikan inset status-bar ke konten supaya rapi.
///
/// PENTING: struktur widget dibuat IDENTIK pada kondisi online & offline (hanya
/// tinggi bar + data MediaQuery yang berubah), sehingga [child] (Navigator)
/// tidak ikut dibangun ulang / kehilangan state saat status berubah.
class GlobalOfflineWrapper extends StatelessWidget {
  final Widget child;
  const GlobalOfflineWrapper({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: AppConnectivity.instance,
      builder: (context, _) {
        final offline = !AppConnectivity.instance.isOnline;
        final mq = MediaQuery.of(context);
        return Column(
          children: [
            _OfflineBar(offline: offline, topInset: mq.padding.top),
            Expanded(
              child: MediaQuery(
                // Saat bar tampil, ia sudah memakan inset atas → buang dari konten.
                data: offline ? mq.removePadding(removeTop: true) : mq,
                child: child,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _OfflineBar extends StatelessWidget {
  final bool offline;
  final double topInset;
  const _OfflineBar({required this.offline, required this.topInset});

  @override
  Widget build(BuildContext context) {
    if (!offline) return const SizedBox.shrink();
    return Container(
      width: double.infinity,
      color: const Color(0xFFE53935),
      padding: EdgeInsets.fromLTRB(16, topInset + 7, 16, 9),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.cloud_off_rounded, color: Colors.white, size: 15),
          const SizedBox(width: 8),
          Text(
            'Tidak ada koneksi internet',
            style: GoogleFonts.outfit(
              color: Colors.white,
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}
