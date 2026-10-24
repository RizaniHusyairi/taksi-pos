import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../utils/api_error.dart';
import 'app_card.dart';
import 'gradient_button.dart';

/// Kartu konsesi bulanan di Dompet.
///
/// Aturan server (ConcessionService): konsesi boleh dicicil dari saldo
/// dompet; antrean hanya terkunci bila bulan berjalan belum dicicil sama
/// sekali. Kartu ini sengaja menonjolkan keadaan "belum bayar" karena itulah
/// yang membuat supir tidak bisa bekerja.
class ConcessionCard extends StatelessWidget {
  final Map<String, dynamic> summary;
  final double balance;

  /// Dipanggil setelah cicilan berhasil, supaya Dompet memuat ulang saldo.
  final VoidCallback onChanged;

  const ConcessionCard({
    super.key,
    required this.summary,
    required this.balance,
    required this.onChanged,
  });

  static final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  int _int(String k) => (summary[k] is num) ? (summary[k] as num).toInt() : 0;

  @override
  Widget build(BuildContext context) {
    final amount = _int('amount');
    final paid = _int('paid');
    final remaining = _int('remaining');
    final status = '${summary['status']}';
    final blocked = summary['blocked'] == true;
    final frac = amount > 0 ? (paid / amount).clamp(0.0, 1.0) : 0.0;
    final saldo = balance.floor();

    final Color warna;
    final String label;
    if (status == 'Paid') {
      warna = AppColors.success;
      label = 'Lunas';
    } else if (status == 'Waived') {
      warna = AppColors.inkSoft;
      label = 'Dibebaskan';
    } else if (blocked) {
      warna = AppColors.danger;
      label = 'Belum dibayar';
    } else {
      warna = AppColors.warning;
      label = 'Mencicil';
    }

    return AppCard(
      border: blocked
          ? Border.all(color: AppColors.danger.withValues(alpha: 0.5))
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: warna.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(Icons.event_note_rounded, color: warna, size: 20),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Konsesi ${summary['period_label'] ?? ''}',
                      style: GoogleFonts.outfit(
                        fontWeight: FontWeight.w800,
                        fontSize: 15.5,
                        color: AppColors.ink,
                      ),
                    ),
                    Text(
                      'Tagihan ${_rupiah.format(amount)} / bulan',
                      style: GoogleFonts.outfit(
                        fontSize: 12,
                        color: AppColors.inkSoft,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: warna.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  label,
                  style: GoogleFonts.outfit(
                    color: warna,
                    fontWeight: FontWeight.w700,
                    fontSize: 11.5,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: frac,
              minHeight: 8,
              backgroundColor: AppColors.paleBlue,
              valueColor: AlwaysStoppedAnimation(
                status == 'Paid' ? AppColors.success : AppColors.skyBlue,
              ),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Terbayar ${_rupiah.format(paid)}',
                  style: GoogleFonts.outfit(
                    fontSize: 12.5,
                    color: AppColors.ink,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              if (status == 'Open')
                Text(
                  'Sisa ${_rupiah.format(remaining)}',
                  style: GoogleFonts.outfit(
                    fontSize: 12.5,
                    color: AppColors.inkSoft,
                  ),
                ),
            ],
          ),
          if (blocked) ...[
            const SizedBox(height: 10),
            Text(
              'Antrean terkunci sampai konsesi bulan ini dicicil, '
              'berapa pun nominalnya.',
              style: GoogleFonts.outfit(
                fontSize: 12,
                color: AppColors.danger,
                height: 1.35,
              ),
            ),
          ],
          if (status == 'Open') ...[
            const SizedBox(height: 14),
            GradientButton(
              label: 'BAYAR DARI SALDO',
              icon: Icons.account_balance_wallet_rounded,
              height: 50,
              onPressed: saldo > 0
                  ? () async {
                      final ok = await ConcessionPaySheet.tampilkan(
                        context,
                        remaining: remaining,
                        balance: saldo,
                      );
                      if (ok) onChanged();
                    }
                  : null,
            ),
            if (saldo <= 0) ...[
              const SizedBox(height: 8),
              Text(
                'Saldo dompet kosong. Bayar tunai ke admin, nanti admin '
                'yang mencatat cicilannya.',
                style: GoogleFonts.outfit(
                  fontSize: 11.5,
                  color: AppColors.inkSoft,
                  height: 1.35,
                ),
              ),
            ],
          ],
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () => ConcessionHistorySheet.tampilkan(context),
              icon: const Icon(Icons.history_rounded, size: 18),
              label: Text(
                'Riwayat konsesi',
                style: GoogleFonts.outfit(fontWeight: FontWeight.w600),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Lembar input nominal cicilan. Batas = min(sisa konsesi, saldo dompet);
/// server memeriksa ulang batas yang sama di dalam kunci baris.
class ConcessionPaySheet extends StatefulWidget {
  final int remaining;
  final int balance;

  const ConcessionPaySheet({
    super.key,
    required this.remaining,
    required this.balance,
  });

  static Future<bool> tampilkan(
    BuildContext context, {
    required int remaining,
    required int balance,
  }) async {
    final hasil = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) =>
          ConcessionPaySheet(remaining: remaining, balance: balance),
    );
    return hasil == true;
  }

  @override
  State<ConcessionPaySheet> createState() => _ConcessionPaySheetState();
}

class _ConcessionPaySheetState extends State<ConcessionPaySheet> {
  final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );
  final _nominal = TextEditingController();
  bool _mengirim = false;
  String? _galat;

  int get _maks =>
      widget.remaining < widget.balance ? widget.remaining : widget.balance;

  int get _nilai => int.tryParse(_nominal.text) ?? 0;

  @override
  void initState() {
    super.initState();
    _nominal.addListener(() => setState(() => _galat = null));
  }

  @override
  void dispose() {
    _nominal.dispose();
    super.dispose();
  }

  String? get _galatInput {
    if (_nominal.text.isEmpty) return null;
    if (_nilai < 1) return 'Nominal minimal Rp 1';
    if (_nilai > _maks) return 'Maksimal ${_rupiah.format(_maks)}';
    return null;
  }

  Future<void> _kirim() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _mengirim = true;
      _galat = null;
    });
    try {
      await ApiService().payConcession(_nilai);
      if (mounted) {
        Navigator.pop(context, true);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Cicilan konsesi ${_rupiah.format(_nilai)} berhasil.',
            ),
          ),
        );
      }
    } catch (e) {
      if (mounted) setState(() => _galat = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _mengirim = false);
    }
  }

  Widget _chip(String label, int nilai) {
    final aktif = nilai <= _maks && nilai > 0;
    return ActionChip(
      label: Text(label, style: GoogleFonts.outfit(fontSize: 12.5)),
      onPressed: aktif ? () => _nominal.text = '$nilai' : null,
    );
  }

  @override
  Widget build(BuildContext context) {
    final valid = _nilai >= 1 && _galatInput == null;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.inkFaint.withValues(alpha: 0.5),
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                'Bayar Konsesi dari Saldo',
                style: GoogleFonts.outfit(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: AppColors.ink,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Sisa konsesi ${_rupiah.format(widget.remaining)} · '
                'saldo ${_rupiah.format(widget.balance)}',
                style: GoogleFonts.outfit(
                  fontSize: 12.5,
                  color: AppColors.inkSoft,
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _nominal,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                autofocus: true,
                decoration: InputDecoration(
                  labelText: 'Nominal (Rp)',
                  hintText: '$_maks',
                  errorText: _galatInput,
                ),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                children: [
                  _chip('50rb', 50000),
                  _chip('100rb', 100000),
                  _chip(
                    _maks == widget.remaining ? 'Lunasi' : 'Semua saldo',
                    _maks,
                  ),
                ],
              ),
              if (_galat != null) ...[
                const SizedBox(height: 10),
                Text(
                  _galat!,
                  style: GoogleFonts.outfit(
                    color: AppColors.danger,
                    fontSize: 12.5,
                  ),
                ),
              ],
              const SizedBox(height: 18),
              GradientButton(
                label: 'BAYAR',
                icon: Icons.check_rounded,
                loading: _mengirim,
                onPressed: valid && !_mengirim ? _kirim : null,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Riwayat konsesi 12 bulan terakhir beserta cicilannya.
class ConcessionHistorySheet extends StatelessWidget {
  const ConcessionHistorySheet({super.key});

  static Future<void> tampilkan(BuildContext context) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const ConcessionHistorySheet(),
    );
  }

  static final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  String _status(Map c) => switch (c['status']) {
    'Paid' => 'Lunas',
    'Waived' => 'Dibebaskan',
    _ => (c['paid'] ?? 0) > 0 ? 'Mencicil' : 'Belum dibayar',
  };

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.of(context).size.height * 0.8,
      ),
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Riwayat Konsesi',
            style: GoogleFonts.outfit(
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: AppColors.ink,
            ),
          ),
          const SizedBox(height: 12),
          Flexible(
            child: FutureBuilder(
              future: ApiService().getConcessionHistory(),
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.all(24),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                if (snap.hasError) {
                  return Text(apiErrorMessage(snap.error!));
                }
                final rows = (snap.data!.data['data'] as List?) ?? [];
                if (rows.isEmpty) {
                  return const Text('Belum ada tagihan konsesi.');
                }
                return ListView.separated(
                  shrinkWrap: true,
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const Divider(height: 18),
                  itemBuilder: (_, i) {
                    final c = rows[i] as Map;
                    final cicilan = (c['payments'] as List?) ?? [];
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                '${c['period_label']}',
                                style: GoogleFonts.outfit(
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.ink,
                                ),
                              ),
                            ),
                            Text(
                              _status(c),
                              style: GoogleFonts.outfit(
                                fontSize: 12,
                                color: AppColors.inkSoft,
                              ),
                            ),
                          ],
                        ),
                        Text(
                          '${_rupiah.format(c['paid'] ?? 0)} dari '
                          '${_rupiah.format(c['amount'] ?? 0)}',
                          style: GoogleFonts.outfit(
                            fontSize: 12.5,
                            color: AppColors.inkSoft,
                          ),
                        ),
                        for (final p in cicilan)
                          Padding(
                            padding: const EdgeInsets.only(top: 4, left: 8),
                            child: Text(
                              '• ${_rupiah.format(p['amount'] ?? 0)} — '
                              '${switch (p['source']) {
                                'wallet' => 'potong saldo',
                                'self_trip' => 'fee trip mandiri',
                                _ => 'tunai ke admin',
                              }}'
                              '${p['created_at'] != null ? ' · ${DateFormat('dd/MM HH:mm').format(DateTime.parse(p['created_at']).toLocal())}' : ''}',
                              style: GoogleFonts.outfit(
                                fontSize: 11.5,
                                color: AppColors.inkSoft,
                              ),
                            ),
                          ),
                      ],
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
