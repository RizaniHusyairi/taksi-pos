import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../services/api_service.dart';
import '../providers/auth_provider.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/app_card.dart';
import '../widgets/gradient_button.dart';
import '../widgets/fade_in.dart';
import '../widgets/error_state.dart';
import '../widgets/wallet_source_sheet.dart';
import '../utils/api_error.dart';
import 'driver_deposit_screen.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  String? _error;
  double _balance = 0;
  // Batas utang & status blokir dari server — supaya supir melihat dirinya
  // mendekati batas SEBELUM antriannya terkunci, bukan mendadak ditolak.
  double _debtLimit = 0;
  bool _debtBlocked = false;
  Map<String, dynamic>? _breakdown; // rincian saldo (income/debt) dari API
  List<dynamic> _history = [];

  // Bank Info
  String _bankName = 'Bank BTN';
  String _accountNumber = '';

  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  Future<void> _fetchData() async {
    setState(() => _isLoading = true);
    final auth = Provider.of<AuthProvider>(context, listen: false);

    try {
      // 0. Refresh Auth Profile (to get latest account number)
      await auth.fetchProfile();
      final user = auth.user;
      final profile = user?['driver_profile'];

      // 1. Get Balance
      final balRes = await _apiService.getBalance();

      // 2. Get Withdraw History
      final histRes = await _apiService.getWithdrawalHistory();

      if (mounted) {
        setState(() {
          _balance = double.parse(balRes.data['balance'].toString());
          _breakdown = balRes.data['breakdown'] is Map
              ? Map<String, dynamic>.from(balRes.data['breakdown'])
              : null;
          _debtLimit = double.tryParse('${balRes.data['debt_limit']}') ?? 0;
          _debtBlocked = balRes.data['debt_blocked'] == true;
          _history = histRes.data;

          // Set bank details from profile
          _bankName = profile?['bank_name'] ?? 'Bank BTN'; // Default to BTN
          _accountNumber = profile?['account_number'] ?? '';

          _error = null;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = apiErrorMessage(e);
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _requestWithdrawal() async {
    // Check if account number is set
    if (_accountNumber.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Harap isi nomor rekening terlebih dahulu!'),
        ),
      );
      _showEditBankDialog();
      return;
    }

    // Confirm Dialog
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text("Konfirmasi Penarikan"),
        content: Text(
          "Ajukan penarikan sebesar Rp ${NumberFormat.currency(locale: 'id_ID', symbol: '', decimalDigits: 0).format(_balance)} ke $_bankName ($_accountNumber)?",
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              "Batal",
              style: GoogleFonts.outfit(color: AppColors.inkSoft),
            ),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.skyBlue,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text("Ya, Tarik Dana"),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      await _apiService.requestWithdrawal();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Permintaan penarikan berhasil dikirim!'),
          ),
        );
      }
      _fetchData();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(apiErrorMessage(e))),
        );
      }
    }
  }

  void _showEditBankDialog() {
    final accController = TextEditingController(text: _accountNumber);

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(ctx).viewInsets.bottom,
          left: 24,
          right: 24,
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
                margin: const EdgeInsets.only(bottom: 20),
                decoration: BoxDecoration(
                  color: AppColors.inkFaint.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
            ),
            Text(
              "Atur Rekening Pencairan",
              style: GoogleFonts.outfit(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: AppColors.ink,
              ),
            ),
            const SizedBox(height: 22),
            Text("Nama Bank",
                style: GoogleFonts.outfit(color: AppColors.inkSoft)),
            const SizedBox(height: 8),
            TextField(
              enabled: false,
              decoration: InputDecoration(
                hintText: _bankName,
                hintStyle: GoogleFonts.outfit(color: AppColors.ink),
                prefixIcon:
                    const Icon(Icons.account_balance_rounded, color: AppColors.cyan),
              ),
            ),
            const SizedBox(height: 16),
            Text("Nomor Rekening",
                style: GoogleFonts.outfit(color: AppColors.inkSoft)),
            const SizedBox(height: 8),
            TextField(
              controller: accController,
              keyboardType: TextInputType.number,
              style: GoogleFonts.outfit(color: AppColors.ink),
              decoration: const InputDecoration(
                hintText: "Masukkan nomor rekening",
                prefixIcon: Icon(Icons.numbers_rounded, color: AppColors.cyan),
              ),
            ),
            const SizedBox(height: 28),
            GradientButton(
              label: "SIMPAN REKENING",
              icon: Icons.save_rounded,
              onPressed: () async {
                if (accController.text.isEmpty) return;
                try {
                  Navigator.pop(ctx);
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Menyimpan rekening...')),
                  );
                  await _apiService.updateBankDetails(accController.text);
                  await _fetchData(); // Refresh UI
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Rekening berhasil disimpan!'),
                      ),
                    );
                  }
                } catch (e) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(apiErrorMessage(e))),
                    );
                  }
                }
              },
            ),
            const SizedBox(height: 28),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final currencyFormat = NumberFormat.currency(
      locale: 'id_ID',
      symbol: 'Rp ',
      decimalDigits: 0,
    );

    return Scaffold(
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          const SkyHeader(
            title: 'Dompet',
            subtitle: 'Saldo & pencairan dana Anda',
          ),
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                ? RefreshIndicator(
                    color: AppColors.skyBlue,
                    onRefresh: _fetchData,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: [
                        SizedBox(
                          height: 460,
                          child: ErrorState(
                              message: _error!, onRetry: _fetchData),
                        ),
                      ],
                    ),
                  )
                : RefreshIndicator(
                    color: AppColors.skyBlue,
                    onRefresh: _fetchData,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
                      children: [
                        // === Balance hero ===
                        FadeInUp(child: _balanceCard(currencyFormat)),
                        const SizedBox(height: 16),
                        // === Rincian saldo (transparansi keuangan) ===
                        _breakdownCard(currencyFormat),
                        const SizedBox(height: 18),
                        // === Bank account ===
                        FadeInUp(delayMs: 90, child: _bankCard()),
                        const SizedBox(height: 28),
                        FadeInUp(
                          delayMs: 150,
                          child: Text(
                            "Riwayat Penarikan",
                            style: GoogleFonts.outfit(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              color: AppColors.ink,
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                        if (_history.isEmpty)
                          _emptyHistory()
                        else
                          ..._history.asMap().entries.map(
                                (e) => FadeInUp(
                                  delayMs: (e.key * 50).clamp(0, 300),
                                  child: _withdrawalItem(e.value, currencyFormat),
                                ),
                              ),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _balanceCard(NumberFormat currency) {
    final canWithdraw = _balance >= 10000;
    final b = _breakdown;
    final hak = b != null
        ? ((b['income']?['net'] ?? 0) as num).toDouble()
        : (_balance > 0 ? _balance : 0.0);
    final debt =
        b != null ? ((b['debt']?['total'] ?? 0) as num).toDouble() : 0.0;
    final denom = hak + debt;
    final greenFrac = denom > 0 ? (hak / denom) : 1.0;

    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: AppColors.skyBlue.withValues(alpha: 0.35),
            blurRadius: 26,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Total Saldo Bersih',
                style: GoogleFonts.outfit(
                  color: Colors.white.withValues(alpha: 0.9),
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                ),
              ),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.account_balance_wallet_rounded,
                    color: Colors.white, size: 20),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _AnimatedMoney(
            value: _balance,
            currency: currency,
            style: GoogleFonts.outfit(
              fontSize: 36,
              fontWeight: FontWeight.w900,
              color: Colors.white,
              height: 1.0,
            ),
          ),
          if (b != null) ...[
            const SizedBox(height: 18),
            _RatioBar(greenFrac: greenFrac),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _miniStat('Hak Anda', currency.format(hak), isHak: true),
                _miniStat('Setoran', currency.format(debt), isHak: false),
              ],
            ),
          ],
          const SizedBox(height: 20),
          _WhiteButton(
            label: 'TARIK DANA',
            icon: Icons.arrow_outward_rounded,
            onPressed: canWithdraw ? _requestWithdrawal : null,
          ),
          // Jalur KEDUA melunasi utang: bayar tunai ke admin. Sebelum ini
          // satu-satunya cara adalah dipotong dari pencairan — sehingga supir
          // bersaldo minus cukup tidak pernah menarik dana dan utangnya tidak
          // punya jatuh tempo sama sekali. Tombolnya muncul hanya saat memang
          // ada utang, supaya tidak menambah kebisingan bagi yang bersih.
          if (debt > 0) ...[
            const SizedBox(height: 10),
            _WhiteButton(
              label: _debtBlocked ? 'SETOR TUNAI (ANTRIAN TERKUNCI)' : 'SETOR TUNAI KE ADMIN',
              icon: Icons.savings_rounded,
              outlined: true,
              onPressed: () async {
                await Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const DriverDepositScreen()),
                );
                if (mounted) _fetchData();
              },
            ),
          ],
          if (_debtLimit > 0 && debt > 0)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Center(
                child: Text(
                  _debtBlocked
                      ? 'Utang melewati batas ${currency.format(_debtLimit)} — antrian terkunci sampai Anda menyetor'
                      : 'Batas utang ${currency.format(_debtLimit)} · sisa ${currency.format((_debtLimit - debt).clamp(0, double.infinity))}',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.outfit(
                    fontSize: 11.5,
                    color: Colors.white.withValues(alpha: 0.9),
                  ),
                ),
              ),
            ),
          if (!canWithdraw)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Center(
                child: Text(
                  _balance < 0
                      ? 'Saldo minus — ada setoran yang belum dilunasi'
                      : 'Minimal penarikan Rp 10.000',
                  style: GoogleFonts.outfit(
                    fontSize: 12,
                    color: Colors.white.withValues(alpha: 0.85),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _miniStat(String label, String value, {required bool isHak}) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 9,
          height: 9,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: isHak ? Colors.white : Colors.white.withValues(alpha: 0.4),
          ),
        ),
        const SizedBox(width: 8),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: GoogleFonts.outfit(
                color: Colors.white.withValues(alpha: 0.8),
                fontSize: 11,
                fontWeight: FontWeight.w500,
              ),
            ),
            Text(
              value,
              style: GoogleFonts.outfit(
                color: Colors.white,
                fontSize: 14.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ],
    );
  }

  /// Kartu rincian: menjelaskan dari mana saldo bersih berasal (transparan).
  Widget _breakdownCard(NumberFormat currency) {
    final b = _breakdown;
    if (b == null) return const SizedBox.shrink();

    num n(dynamic v) => (v ?? 0) as num;
    final income = (b['income'] ?? const {}) as Map;
    final debt = (b['debt'] ?? const {}) as Map;
    final ratePct = (n(b['commission_rate']) * 100).round();
    final flat = n(b['manual_fee_flat']);

    final gross = n(income['gross']);
    final commission = n(income['commission']);
    final netIncome = n(income['net']);
    final incomeCount = n(income['count']).toInt();

    final stdFee = n(debt['standard_fee']);
    final stdCount = n(debt['standard_count']).toInt();
    final manualCount = n(debt['manual_count']).toInt();
    final manualFee = n(debt['manual_fee']);
    final debtTotal = n(debt['total']);
    final tripCount = stdCount + manualCount;

    final netBal = n(b['net']).toDouble();

    return AppCard(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                'Rincian Saldo',
                style: GoogleFonts.outfit(
                  fontSize: 17,
                  fontWeight: FontWeight.bold,
                  color: AppColors.ink,
                ),
              ),
              const Spacer(),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: AppColors.paleBlue,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.verified_rounded,
                        size: 13, color: AppColors.skyBlue),
                    const SizedBox(width: 4),
                    Text(
                      'Transparan',
                      style: GoogleFonts.outfit(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: AppColors.skyBlue,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 3),
          Text(
            'Begini saldo bersih Anda dihitung',
            style:
                GoogleFonts.outfit(fontSize: 12.5, color: AppColors.inkSoft),
          ),
          const SizedBox(height: 18),

          // === PEMASUKAN ===
          FadeInUp(
            delayMs: 60,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _sectionHeader(Icons.trending_up_rounded, 'Pemasukan',
                    '$incomeCount transaksi', AppColors.success),
                const SizedBox(height: 6),
                _lineRow('Pendapatan kotor', currency.format(gross),
                    // Baris bernilai 0 sengaja tidak dapat ditekan: membuka
                    // lembar kosong hanya membuat orang mengira fiturnya rusak.
                    bucket: incomeCount > 0 ? 'income' : null),
                _lineRow('Komisi koperasi ($ratePct%)',
                    '− ${currency.format(commission)}',
                    valueColor: AppColors.danger,
                    bucket: incomeCount > 0 ? 'income' : null),
                _divider(),
                _lineRow('Hak bersih Anda', currency.format(netIncome),
                    bold: true, valueColor: AppColors.success),
              ],
            ),
          ),
          const SizedBox(height: 18),

          // === SETORAN KE KOPERASI ===
          FadeInUp(
            delayMs: 150,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _sectionHeader(Icons.account_balance_rounded,
                    'Setoran ke Koperasi', '$tripCount trip',
                    AppColors.warning),
                const SizedBox(height: 6),
                if (stdCount > 0)
                  _lineRow('Setoran komisi tunai', currency.format(stdFee),
                      sub: '$stdCount trip order sistem',
                      bucket: 'debt_standard'),
                if (manualCount > 0)
                  _lineRow('Biaya order manual', currency.format(manualFee),
                      sub: '$manualCount trip × ${currency.format(flat)}',
                      bucket: 'debt_manual'),
                if (tripCount == 0)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Text(
                      'Tidak ada setoran tertunda 🎉',
                      style: GoogleFonts.outfit(
                        color: AppColors.success,
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                    ),
                  ),
                _divider(),
                _lineRow('Total setoran', currency.format(debtTotal),
                    bold: true, valueColor: AppColors.warning),
              ],
            ),
          ),
          const SizedBox(height: 18),

          // === HASIL: SALDO BERSIH ===
          FadeInUp(
            delayMs: 240,
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.paleBlue,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(
                    color: AppColors.skyBlue.withValues(alpha: 0.18)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        'Saldo Bersih',
                        style: GoogleFonts.outfit(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: AppColors.deepBlue,
                        ),
                      ),
                      _AnimatedMoney(
                        value: netBal,
                        currency: currency,
                        style: GoogleFonts.outfit(
                          fontSize: 22,
                          fontWeight: FontWeight.w900,
                          color: AppColors.deepBlue,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Hak Anda ${currency.format(netIncome)}  −  Setoran ${currency.format(debtTotal)}',
                    style: GoogleFonts.outfit(
                      fontSize: 11.5,
                      color: AppColors.inkSoft,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _sectionHeader(
      IconData icon, String title, String trailing, Color color) {
    return Row(
      children: [
        Container(
          padding: const EdgeInsets.all(7),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(9),
          ),
          child: Icon(icon, color: color, size: 16),
        ),
        const SizedBox(width: 10),
        Text(
          title,
          style: GoogleFonts.outfit(
            fontSize: 14,
            fontWeight: FontWeight.w700,
            color: AppColors.ink,
          ),
        ),
        const Spacer(),
        Text(
          trailing,
          style: GoogleFonts.outfit(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: AppColors.inkFaint,
          ),
        ),
      ],
    );
  }

  /// Satu baris rincian.
  ///
  /// Bila [bucket] diisi, baris menjadi dapat ditekan untuk melihat transaksi
  /// asalnya — dan diberi ikon panah kecil. Baris yang bisa ditekan tanpa
  /// penanda apa pun hampir tidak pernah ditemukan pengguna.
  Widget _lineRow(String label, String value,
      {String? sub, Color? valueColor, bool bold = false, String? bucket}) {
    final baris = Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        label,
                        style: GoogleFonts.outfit(
                          color: bold ? AppColors.ink : AppColors.inkSoft,
                          fontSize: 13.5,
                          fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
                        ),
                      ),
                    ),
                    if (bucket != null) ...[
                      const SizedBox(width: 4),
                      const Icon(Icons.chevron_right_rounded,
                          size: 17, color: AppColors.skyBlue),
                    ],
                  ],
                ),
                if (sub != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 1),
                    child: Text(
                      sub,
                      style: GoogleFonts.outfit(
                        color: AppColors.inkFaint,
                        fontSize: 11,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Text(
            value,
            style: GoogleFonts.outfit(
              color: valueColor ?? (bold ? AppColors.ink : AppColors.inkSoft),
              fontSize: bold ? 15 : 13.5,
              fontWeight: bold ? FontWeight.w800 : FontWeight.w600,
            ),
          ),
        ],
      ),
    );

    if (bucket == null) return baris;

    return InkWell(
      onTap: () => WalletSourceSheet.tampilkan(context, bucket),
      borderRadius: BorderRadius.circular(10),
      child: baris,
    );
  }

  Widget _divider() => Container(
        height: 1,
        margin: const EdgeInsets.symmetric(vertical: 7),
        color: AppColors.inkFaint.withValues(alpha: 0.22),
      );

  Widget _bankCard() {
    return AppCard(
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.paleBlue,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.account_balance_rounded,
                color: AppColors.skyBlue, size: 26),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _bankName,
                  style: GoogleFonts.outfit(
                    color: AppColors.inkSoft,
                    fontSize: 12,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  _accountNumber.isEmpty ? "Belum Diatur" : _accountNumber,
                  style: GoogleFonts.outfit(
                    color: AppColors.ink,
                    fontWeight: FontWeight.bold,
                    fontSize: 18,
                    letterSpacing: 1,
                  ),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: _showEditBankDialog,
            child: Text(
              _accountNumber.isEmpty ? "ATUR" : "UBAH",
              style: GoogleFonts.outfit(
                color: AppColors.cyan,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _emptyHistory() {
    return Padding(
      padding: const EdgeInsets.only(top: 30),
      child: Column(
        children: [
          Icon(Icons.receipt_long_rounded,
              size: 54, color: AppColors.inkFaint.withValues(alpha: 0.5)),
          const SizedBox(height: 14),
          Text(
            "Belum ada riwayat penarikan.",
            style: GoogleFonts.outfit(color: AppColors.inkFaint),
          ),
        ],
      ),
    );
  }

  Widget _withdrawalItem(dynamic item, NumberFormat currency) {
    final status = item['status'];
    Color statusColor;
    IconData statusIcon;

    switch (status) {
      case 'Paid':
        statusColor = AppColors.success;
        statusIcon = Icons.check_circle_rounded;
        break;
      case 'Pending':
        statusColor = AppColors.warning;
        statusIcon = Icons.access_time_filled_rounded;
        break;
      case 'Rejected':
        statusColor = AppColors.danger;
        statusIcon = Icons.cancel_rounded;
        break;
      default:
        statusColor = AppColors.inkFaint;
        statusIcon = Icons.help_rounded;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.05),
            blurRadius: 14,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: statusColor.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(statusIcon, color: statusColor, size: 22),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  currency.format(double.parse(item['amount'].toString())),
                  style: GoogleFonts.outfit(
                    color: AppColors.ink,
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  DateFormat('dd MMM yyyy, HH:mm')
                      .format(DateTime.parse(item['requested_at'])),
                  style: GoogleFonts.outfit(
                    color: AppColors.inkFaint,
                    fontSize: 12,
                  ),
                ),
                if (_proofUrl(item) != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: InkWell(
                      onTap: () =>
                          _showProofDialog(context, _proofUrl(item)!),
                      child: Text(
                        "Lihat Bukti Transfer",
                        style: GoogleFonts.outfit(
                          color: AppColors.cyan,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          decoration: TextDecoration.underline,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: statusColor.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              status.toString().toUpperCase(),
              style: GoogleFonts.outfit(
                color: statusColor,
                fontSize: 10,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// URL bukti transfer, DIBANGUN DI KLIEN dari alamat API yang benar-benar
  /// dipakai — bukan memakai `proof_image_url` kiriman server.
  ///
  /// Server membangunnya dengan `asset()`, yang bergantung pada APP_URL di
  /// .env. Begitu APP_URL berbeda dari host yang dihubungi aplikasi, gambarnya
  /// tidak akan pernah ketemu: saat pengujian lewat `adb reverse`,
  /// APP_URL=http://localhost berarti "HP itu sendiri", bukan server. Sisi CSO
  /// sudah lama memakai pola yang benar ini — lihat models/cso_transaction.dart.
  String? _proofUrl(dynamic item) {
    final path = item['proof_image'];
    if (path != null && path.toString().trim().isNotEmpty) {
      return '${ApiService.assetBaseUrl}/storage/$path';
    }
    // Cadangan: pakai URL dari server bila kolom mentahnya tidak dikirim.
    final url = item['proof_image_url'];
    return (url != null && url.toString().trim().isNotEmpty) ? url.toString() : null;
  }

  void _showProofDialog(BuildContext context, String imageUrl) {
    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.transparent,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(16),
                child: Image.network(
                  imageUrl,
                  fit: BoxFit.contain,
                  loadingBuilder: (context, child, loadingProgress) {
                    if (loadingProgress == null) return child;
                    return Container(
                      height: 200,
                      width: double.infinity,
                      alignment: Alignment.center,
                      child: const CircularProgressIndicator(),
                    );
                  },
                  errorBuilder: (context, error, stackTrace) => Container(
                    height: 100,
                    width: double.infinity,
                    color: Colors.grey[200],
                    alignment: Alignment.center,
                    child: const Text(
                      "Gagal memuat gambar",
                      style: TextStyle(color: Colors.black),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            CircleAvatar(
              backgroundColor: Colors.white24,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.pop(ctx),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Teks nominal yang menghitung naik dari 0 (efek count-up) saat muncul.
class _AnimatedMoney extends StatelessWidget {
  final double value;
  final NumberFormat currency;
  final TextStyle style;
  const _AnimatedMoney({
    required this.value,
    required this.currency,
    required this.style,
  });

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 0, end: value),
      duration: const Duration(milliseconds: 850),
      curve: Curves.easeOutCubic,
      builder: (_, v, _) => Text(currency.format(v), style: style),
    );
  }
}

/// Bar rasio "Hak Anda" (putih solid) vs "Setoran" (putih transparan),
/// mengisi dengan animasi saat kartu muncul.
class _RatioBar extends StatelessWidget {
  final double greenFrac;
  const _RatioBar({required this.greenFrac});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 9,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Stack(
          fit: StackFit.expand,
          children: [
            Container(color: Colors.white.withValues(alpha: 0.25)),
            TweenAnimationBuilder<double>(
              tween: Tween<double>(begin: 0, end: greenFrac.clamp(0.0, 1.0)),
              duration: const Duration(milliseconds: 950),
              curve: Curves.easeOutCubic,
              builder: (_, f, _) => Align(
                alignment: Alignment.centerLeft,
                child: FractionallySizedBox(
                  widthFactor: f <= 0 ? 0.0 : f,
                  heightFactor: 1,
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Tombol putih untuk dipakai di atas kartu gradien (kontras tinggi).
class _WhiteButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;

  /// Varian sekunder: transparan dengan garis tepi putih. Dipakai agar
  /// "Setor Tunai" tidak bersaing perhatian dengan "Tarik Dana" — keduanya
  /// tindakan uang, tapi hanya satu yang jadi aksi utama kartu ini.
  final bool outlined;

  const _WhiteButton({
    required this.label,
    required this.icon,
    this.onPressed,
    this.outlined = false,
  });

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null;
    final warnaTeks = outlined ? Colors.white : AppColors.deepBlue;

    return SizedBox(
      width: double.infinity,
      child: Material(
        color: outlined
            ? Colors.white.withValues(alpha: 0.12)
            : (enabled ? Colors.white : Colors.white.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onPressed,
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 15),
            decoration: outlined
                ? BoxDecoration(
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: Colors.white.withValues(alpha: 0.55),
                      width: 1.2,
                    ),
                  )
                : null,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon,
                    color: enabled ? warnaTeks : warnaTeks.withValues(alpha: 0.6),
                    size: 20),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    label,
                    textAlign: TextAlign.center,
                    style: GoogleFonts.outfit(
                      color: enabled ? warnaTeks : warnaTeks.withValues(alpha: 0.6),
                      fontWeight: FontWeight.w700,
                      fontSize: outlined ? 13.5 : 15,
                      letterSpacing: 0.5,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
