import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_colors.dart';

/// Kartu nomor antrian supir. Membedakan dua kelompok antrian:
///  - Antrian Utama  : giliran rotasi nomor lambung (masuk pertama hari ini).
///  - Antrian Rejoin : masuk lagi di hari yang sama (sort_order >= 1000),
///    selalu di belakang seluruh antrian utama, urut waktu masuk.
/// Ketuk kartu untuk membuka penjelasan jalur antrian.
class QueueTicketCard extends StatefulWidget {
  final dynamic position;
  final bool isRejoin;
  final int? mainAhead;

  const QueueTicketCard({
    super.key,
    required this.position,
    required this.isRejoin,
    this.mainAhead,
  });

  @override
  State<QueueTicketCard> createState() => _QueueTicketCardState();
}

class _QueueTicketCardState extends State<QueueTicketCard> {
  bool _expanded = false;

  static const _rejoinGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFF59E0B), Color(0xFFEA580C)],
  );

  @override
  Widget build(BuildContext context) {
    final rejoin = widget.isRejoin;
    final white85 = Colors.white.withValues(alpha: 0.85);

    return GestureDetector(
      onTap: () => setState(() => _expanded = !_expanded),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 450),
        curve: Curves.easeOutCubic,
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
        decoration: BoxDecoration(
          gradient: rejoin ? _rejoinGradient : AppColors.skyGradient,
          borderRadius: BorderRadius.circular(18),
          boxShadow: [
            BoxShadow(
              color: (rejoin ? const Color(0xFFEA580C) : AppColors.royalBlue)
                  .withValues(alpha: 0.28),
              blurRadius: 16,
              offset: const Offset(0, 6),
            ),
          ],
        ),
        child: Column(
          children: [
            Row(
              children: [
                _GroupChip(rejoin: rejoin),
                const Spacer(),
                AnimatedRotation(
                  turns: _expanded ? 0.5 : 0,
                  duration: const Duration(milliseconds: 300),
                  child: Icon(Icons.expand_more_rounded, color: white85),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              "NOMOR ANTRIAN",
              style: GoogleFonts.outfit(
                color: white85,
                fontSize: 12,
                letterSpacing: 1.5,
              ),
            ),
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 350),
              transitionBuilder: (child, anim) => ScaleTransition(
                scale: Tween(begin: 0.6, end: 1.0).animate(
                  CurvedAnimation(parent: anim, curve: Curves.easeOutBack),
                ),
                child: FadeTransition(opacity: anim, child: child),
              ),
              child: Text(
                "#${widget.position ?? '-'}",
                key: ValueKey('${widget.position}-$rejoin'),
                style: GoogleFonts.outfit(
                  color: Colors.white,
                  fontSize: 34,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
            if (rejoin && (widget.mainAhead ?? 0) > 0)
              Text(
                "${widget.mainAhead} supir antrian utama didahulukan",
                textAlign: TextAlign.center,
                style: GoogleFonts.outfit(color: white85, fontSize: 12.5),
              ),
            AnimatedSize(
              duration: const Duration(milliseconds: 320),
              curve: Curves.easeOutCubic,
              alignment: Alignment.topCenter,
              child: _expanded
                  ? _QueueLanes(rejoin: rejoin, mainAhead: widget.mainAhead)
                  : const SizedBox(width: double.infinity),
            ),
            if (!_expanded) ...[
              const SizedBox(height: 6),
              Text(
                "Ketuk untuk lihat jalur antrian",
                style: GoogleFonts.outfit(
                  color: Colors.white.withValues(alpha: 0.65),
                  fontSize: 11,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _GroupChip extends StatelessWidget {
  final bool rejoin;
  const _GroupChip({required this.rejoin});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.2),
        borderRadius: BorderRadius.circular(30),
        border: Border.all(color: Colors.white.withValues(alpha: 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Ikon ↺ berputar sekali tiap kartu berganti ke Rejoin.
          TweenAnimationBuilder<double>(
            key: ValueKey(rejoin),
            tween: Tween(begin: rejoin ? -1.0 : 0.0, end: 0.0),
            duration: const Duration(milliseconds: 700),
            curve: Curves.easeOutCubic,
            builder: (_, t, child) =>
                Transform.rotate(angle: t * 6.283, child: child),
            child: Icon(
              rejoin ? Icons.replay_rounded : Icons.workspace_premium_rounded,
              size: 14,
              color: Colors.white,
            ),
          ),
          const SizedBox(width: 5),
          Text(
            rejoin ? "ANTRIAN REJOIN" : "ANTRIAN UTAMA",
            style: GoogleFonts.outfit(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.8,
            ),
          ),
        ],
      ),
    );
  }
}

/// Dua jalur antrian bertingkat; jalur tempat supir berada disorot.
class _QueueLanes extends StatelessWidget {
  final bool rejoin;
  final int? mainAhead;
  const _QueueLanes({required this.rejoin, this.mainAhead});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        children: [
          _lane(
            active: !rejoin,
            color: AppColors.royalBlue,
            icon: Icons.workspace_premium_rounded,
            title: "1. Antrian Utama",
            desc: rejoin && (mainAhead ?? 0) > 0
                ? "Giliran nomor lambung hari ini · $mainAhead supir"
                : "Giliran nomor lambung hari ini (masuk pertama)",
          ),
          Padding(
            padding: const EdgeInsets.only(left: 15),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Container(
                width: 2,
                height: 14,
                color: AppColors.inkFaint.withValues(alpha: 0.5),
              ),
            ),
          ),
          _lane(
            active: rejoin,
            color: const Color(0xFFEA580C),
            icon: Icons.replay_rounded,
            title: "2. Antrian Rejoin",
            desc: "Masuk lagi di hari yang sama, urut waktu masuk",
          ),
          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.lightbulb_outline_rounded,
                  size: 15, color: AppColors.inkSoft),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  rejoin
                      ? "Order dibagikan ke antrian utama dulu. Besok Anda kembali ke antrian utama sesuai rotasi nomor lambung."
                      : "Setelah selesai mengantar, masuk lagi akan menempatkan Anda di Antrian Rejoin.",
                  style: GoogleFonts.outfit(
                    fontSize: 11.5,
                    color: AppColors.inkSoft,
                    height: 1.35,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _lane({
    required bool active,
    required Color color,
    required IconData icon,
    required String title,
    required String desc,
  }) {
    return AnimatedOpacity(
      duration: const Duration(milliseconds: 300),
      opacity: active ? 1 : 0.55,
      child: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: active ? color : color.withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, size: 17, color: active ? Colors.white : color),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: GoogleFonts.outfit(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w800,
                    color: AppColors.ink,
                  ),
                ),
                Text(
                  desc,
                  style: GoogleFonts.outfit(
                    fontSize: 11.5,
                    color: AppColors.inkSoft,
                  ),
                ),
              ],
            ),
          ),
          if (active)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: color,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                "Anda di sini",
                style: GoogleFonts.outfit(
                  color: Colors.white,
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
