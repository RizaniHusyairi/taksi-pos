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

/// Dompet elektronik tujuan pencairan. Kuncinya harus sama persis dengan
/// DriverProfile::EWALLET_PROVIDERS di backend — validasi ada di sana.
const Map<String, String> kEwalletProviders = {
  'shopeepay': 'ShopeePay',
  'dana': 'DANA',
  'gopay': 'GoPay',
  'ovo': 'OVO',
};

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

  // Rincian saldo tertutup secara bawaan: kartu saldo sudah menjawab
  // "bisa tarik berapa / harus setor berapa"; rinciannya untuk yang ingin tahu.
  bool _rincianTerbuka = false;

  static const _oranye = Color(0xFFF57C00);

  // Nama bulan ditulis manual: aplikasi tidak memanggil
  // initializeDateFormatting() (lihat test/date_format_locale_test.dart).
  static const _bulan = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
  ];

  // Tujuan pencairan: 'bank' (BTN) atau 'ewallet'. Hanya satu yang aktif.
  String _payoutMethod = 'bank';
  String _bankName = 'Bank BTN';
  String _accountNumber = '';
  String _ewalletProvider = '';
  String _ewalletNumber = '';
  String _ewalletHolder = '';

  bool get _isEwallet => _payoutMethod == 'ewallet';

  /// Sudah lengkap atau belum. Aturan yang sama ditegakkan ulang di server —
  /// ini cuma supaya supir tidak menekan tombol yang pasti gagal.
  bool get _payoutConfigured => _isEwallet
      ? (_ewalletProvider.isNotEmpty &&
            _ewalletNumber.isNotEmpty &&
            _ewalletHolder.isNotEmpty)
      : _accountNumber.isNotEmpty;

  String get _payoutChannelLabel =>
      _isEwallet ? (kEwalletProviders[_ewalletProvider] ?? '-') : _bankName;

  String get _payoutDetailLabel => _isEwallet
      ? (_ewalletNumber.isEmpty
            ? "Belum Diatur"
            : "$_ewalletNumber a.n. $_ewalletHolder")
      : (_accountNumber.isEmpty ? "Belum Diatur" : _accountNumber);

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

          // Tujuan pencairan dari profil
          _payoutMethod = profile?['payout_method'] ?? 'bank';
          _bankName = profile?['bank_name'] ?? 'Bank BTN'; // Default to BTN
          _accountNumber = profile?['account_number'] ?? '';
          _ewalletProvider = profile?['ewallet_provider'] ?? '';
          _ewalletNumber = profile?['ewallet_number'] ?? '';
          _ewalletHolder = profile?['ewallet_holder_name'] ?? '';

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
    // Tujuan pencairan harus lengkap dulu
    if (!_payoutConfigured) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Harap atur tujuan pencairan terlebih dahulu!'),
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
          "Ajukan penarikan sebesar Rp ${NumberFormat.currency(locale: 'id_ID', symbol: '', decimalDigits: 0).format(_balance)} ke $_payoutChannelLabel ($_payoutDetailLabel)?",
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
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    }
  }

  void _showEditBankDialog() {
    final accController = TextEditingController(text: _accountNumber);
    final numController = TextEditingController(text: _ewalletNumber);
    final holderController = TextEditingController(text: _ewalletHolder);
    // State lokal sheet: metode & provider bisa diubah sebelum disimpan tanpa
    // mengganggu tampilan kartu di belakangnya.
    String metode = _payoutMethod;
    String provider = _ewalletProvider.isNotEmpty ? _ewalletProvider : 'dana';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
            left: 24,
            right: 24,
            top: 12,
          ),
          child: SingleChildScrollView(
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
                  "Atur Tujuan Pencairan",
                  style: GoogleFonts.outfit(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: AppColors.ink,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  "Hanya satu tujuan yang aktif. Dana dikirim manual oleh admin.",
                  style: GoogleFonts.outfit(
                    fontSize: 12,
                    color: AppColors.inkSoft,
                  ),
                ),
                const SizedBox(height: 18),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(
                      value: 'bank',
                      label: Text("Bank BTN"),
                      icon: Icon(Icons.account_balance_rounded),
                    ),
                    ButtonSegment(
                      value: 'ewallet',
                      label: Text("E-Wallet"),
                      icon: Icon(Icons.account_balance_wallet_rounded),
                    ),
                  ],
                  selected: {metode},
                  onSelectionChanged: (pilihan) =>
                      setSheetState(() => metode = pilihan.first),
                ),
                const SizedBox(height: 22),
                if (metode == 'bank') ...[
                  Text(
                    "Nama Bank",
                    style: GoogleFonts.outfit(color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    enabled: false,
                    decoration: InputDecoration(
                      hintText: _bankName,
                      hintStyle: GoogleFonts.outfit(color: AppColors.ink),
                      prefixIcon: const Icon(
                        Icons.account_balance_rounded,
                        color: AppColors.cyan,
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    "Nomor Rekening",
                    style: GoogleFonts.outfit(color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: accController,
                    keyboardType: TextInputType.number,
                    style: GoogleFonts.outfit(color: AppColors.ink),
                    decoration: const InputDecoration(
                      hintText: "Masukkan nomor rekening",
                      prefixIcon: Icon(
                        Icons.numbers_rounded,
                        color: AppColors.cyan,
                      ),
                    ),
                  ),
                ] else ...[
                  Text(
                    "Dompet Elektronik",
                    style: GoogleFonts.outfit(color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    initialValue: provider,
                    style: GoogleFonts.outfit(color: AppColors.ink),
                    decoration: const InputDecoration(
                      prefixIcon: Icon(
                        Icons.account_balance_wallet_rounded,
                        color: AppColors.cyan,
                      ),
                    ),
                    items: kEwalletProviders.entries
                        .map(
                          (e) => DropdownMenuItem(
                            value: e.key,
                            child: Text(e.value),
                          ),
                        )
                        .toList(),
                    onChanged: (v) =>
                        setSheetState(() => provider = v ?? provider),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    "Nomor E-Wallet",
                    style: GoogleFonts.outfit(color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: numController,
                    keyboardType: TextInputType.phone,
                    style: GoogleFonts.outfit(color: AppColors.ink),
                    decoration: const InputDecoration(
                      hintText: "Contoh: 081234567890",
                      prefixIcon: Icon(
                        Icons.phone_iphone_rounded,
                        color: AppColors.cyan,
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    "Nama Pemilik Akun",
                    style: GoogleFonts.outfit(color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: holderController,
                    textCapitalization: TextCapitalization.words,
                    style: GoogleFonts.outfit(color: AppColors.ink),
                    decoration: const InputDecoration(
                      hintText: "Sesuai nama di aplikasi e-wallet",
                      prefixIcon: Icon(
                        Icons.person_rounded,
                        color: AppColors.cyan,
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 28),
                GradientButton(
                  label: "SIMPAN TUJUAN",
                  icon: Icons.save_rounded,
                  onPressed: () async {
                    final lengkap = metode == 'ewallet'
                        ? numController.text.isNotEmpty &&
                              holderController.text.isNotEmpty
                        : accController.text.isNotEmpty;
                    if (!lengkap) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Lengkapi dulu semua isian tujuan.'),
                        ),
                      );
                      return;
                    }
                    try {
                      Navigator.pop(ctx);
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Menyimpan tujuan pencairan...'),
                        ),
                      );
                      await _apiService.updatePayoutAccount(
                        method: metode,
                        accountNumber: accController.text,
                        provider: provider,
                        number: numController.text,
                        holderName: holderController.text,
                      );
                      await _fetchData(); // Refresh UI
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: Text(
                              'Tujuan pencairan berhasil disimpan!',
                            ),
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
                            message: _error!,
                            onRetry: _fetchData,
                          ),
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
                        if (_debtBlocked) ...[
                          FadeInUp(child: _lockedBanner(currencyFormat)),
                          const SizedBox(height: 14),
                        ],
                        // === Kartu saldo (berubah sesuai keadaan) ===
                        FadeInUp(child: _balanceCard(currencyFormat)),
                        const SizedBox(height: 16),
                        // === Rincian saldo (transparansi keuangan) ===
                        _breakdownCard(currencyFormat),
                        const SizedBox(height: 26),
                        FadeInUp(
                          delayMs: 120,
                          child: Text(
                            "Riwayat Penarikan",
                            style: GoogleFonts.outfit(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              color: AppColors.ink,
                            ),
                          ),
                        ),
                        const SizedBox(height: 4),
                        if (_history.isEmpty)
                          _emptyHistory()
                        else
                          ..._riwayatPerBulan(currencyFormat),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  double get _debtTotal {
    final v = _breakdown?['debt']?['total'];
    return v is num ? v.toDouble() : (_balance < 0 ? -_balance : 0);
  }

  double get _hakBersih {
    final v = _breakdown?['income']?['net'];
    return v is num ? v.toDouble() : (_balance > 0 ? _balance : 0);
  }

  /// Banner paling atas saat antrean diblokir karena utang: inilah keadaan
  /// yang membuat supir tidak bisa bekerja, jadi tidak boleh tersembunyi di
  /// label tombol.
  Widget _lockedBanner(NumberFormat currency) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.5)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: const BoxDecoration(
              color: AppColors.danger,
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.lock_rounded,
              color: Colors.white,
              size: 18,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Antrean Anda terkunci',
                  style: GoogleFonts.outfit(
                    color: AppColors.danger,
                    fontWeight: FontWeight.w800,
                    fontSize: 15,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Setoran ${currency.format(_debtTotal)} melewati batas '
                  '${currency.format(_debtLimit)}. Setor tunai ke admin agar '
                  'bisa masuk antrean lagi.',
                  style: GoogleFonts.outfit(
                    color: AppColors.ink,
                    fontSize: 12.5,
                    height: 1.35,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _bukaSetor() async {
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const DriverDepositScreen()),
    );
    if (mounted) _fetchData();
  }

  /// Kartu saldo menjawab satu pertanyaan sesuai keadaan supir:
  ///  - saldo minus  → "harus setor berapa" (oranye, aksi utama SETOR);
  ///  - saldo cukup  → "bisa tarik berapa" (biru, aksi utama TARIK);
  ///  - saldo kecil  → "kurang berapa lagi" (biru, tombol tarik nonaktif).
  Widget _balanceCard(NumberFormat currency) {
    final minus = _balance < 0 || _debtBlocked;
    final canWithdraw = !minus && _balance >= 10000;
    final debt = _debtTotal;

    final gradient = minus
        ? const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFFFF8F00), Color(0xFFE65100)],
          )
        : AppColors.skyGradient;
    final accent = minus ? const Color(0xFFE65100) : AppColors.deepBlue;

    final judul = minus
        ? 'Perlu disetor ke koperasi'
        : (canWithdraw ? 'Saldo bisa ditarik' : 'Saldo Anda');

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: gradient,
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: (minus ? _oranye : AppColors.skyBlue).withValues(
              alpha: 0.35,
            ),
            blurRadius: 26,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  judul,
                  style: GoogleFonts.outfit(
                    color: Colors.white.withValues(alpha: 0.92),
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  minus
                      ? Icons.savings_rounded
                      : Icons.account_balance_wallet_rounded,
                  color: Colors.white,
                  size: 20,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _AnimatedMoney(
            value: minus ? (debt > 0 ? debt : -_balance) : _balance,
            currency: currency,
            style: GoogleFonts.outfit(
              fontSize: 36,
              fontWeight: FontWeight.w900,
              color: Colors.white,
              height: 1.0,
            ),
          ),
          const SizedBox(height: 10),
          if (minus)
            _debtLimitInfo(currency, debt)
          else
            _keteranganSaldo(currency, canWithdraw, debt),
          const SizedBox(height: 18),
          if (minus)
            _WhiteButton(
              label: 'SETOR TUNAI KE ADMIN',
              icon: Icons.savings_rounded,
              accent: accent,
              onPressed: _bukaSetor,
            )
          else ...[
            _WhiteButton(
              label: 'TARIK DANA',
              icon: Icons.arrow_outward_rounded,
              accent: accent,
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
                label: 'SETOR TUNAI KE ADMIN',
                icon: Icons.savings_rounded,
                outlined: true,
                onPressed: _bukaSetor,
              ),
            ],
          ],
          const SizedBox(height: 14),
          _tujuanPencairan(),
        ],
      ),
    );
  }

  Widget _keteranganSaldo(
    NumberFormat currency,
    bool canWithdraw,
    double debt,
  ) {
    final teks = canWithdraw
        ? (debt > 0
              ? 'Hak ${currency.format(_hakBersih)} − setoran ${currency.format(debt)}. Seluruh saldo dikirim saat ditarik.'
              : 'Seluruh saldo dikirim ke tujuan pencairan di bawah.')
        : 'Minimal penarikan Rp 10.000 — kurang '
              '${currency.format((10000 - _balance).clamp(0, 10000))} lagi.';
    return Text(
      teks,
      style: GoogleFonts.outfit(
        fontSize: 12,
        height: 1.35,
        color: Colors.white.withValues(alpha: 0.88),
      ),
    );
  }

  /// Seberapa dekat setoran dengan batas yang mengunci antrean.
  Widget _debtLimitInfo(NumberFormat currency, double debt) {
    if (_debtLimit <= 0) {
      return Text(
        'Saldo minus — setoran komisi tunai belum dilunasi.',
        style: GoogleFonts.outfit(
          fontSize: 12,
          color: Colors.white.withValues(alpha: 0.88),
        ),
      );
    }
    final frac = (debt / _debtLimit).clamp(0.0, 1.0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: frac,
            minHeight: 8,
            backgroundColor: Colors.white.withValues(alpha: 0.25),
            valueColor: const AlwaysStoppedAnimation(Colors.white),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          _debtBlocked
              ? 'Melewati batas ${currency.format(_debtLimit)} — antrean terkunci'
              : 'Batas ${currency.format(_debtLimit)} · sisa '
                    '${currency.format((_debtLimit - debt).clamp(0, double.infinity))} sebelum antrean terkunci',
          style: GoogleFonts.outfit(
            fontSize: 11.5,
            color: Colors.white.withValues(alpha: 0.92),
          ),
        ),
      ],
    );
  }

  /// Tujuan pencairan ditaruh di kartu saldo, tepat di bawah tombol tarik:
  /// informasi itu dibutuhkan persis saat supir hendak menarik dana.
  Widget _tujuanPencairan() {
    final String detail;
    if (!_payoutConfigured) {
      detail = 'Belum diatur';
    } else if (_isEwallet) {
      detail = '${_samarkan(_ewalletNumber)} · $_ewalletHolder';
    } else {
      detail = _samarkan(_accountNumber);
    }

    return Material(
      color: Colors.white.withValues(alpha: 0.14),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: _showEditBankDialog,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
          child: Row(
            children: [
              Icon(
                _isEwallet
                    ? Icons.account_balance_wallet_outlined
                    : Icons.account_balance_rounded,
                color: Colors.white,
                size: 20,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _payoutConfigured
                          ? 'Dikirim ke $_payoutChannelLabel'
                          : 'Tujuan pencairan',
                      style: GoogleFonts.outfit(
                        color: Colors.white.withValues(alpha: 0.8),
                        fontSize: 11,
                      ),
                    ),
                    Text(
                      detail,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.outfit(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 13.5,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 5,
                ),
                decoration: BoxDecoration(
                  color: _payoutConfigured
                      ? Colors.white.withValues(alpha: 0.2)
                      : Colors.white,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  _payoutConfigured ? 'UBAH' : 'ATUR',
                  style: GoogleFonts.outfit(
                    color: _payoutConfigured
                        ? Colors.white
                        : AppColors.deepBlue,
                    fontWeight: FontWeight.w800,
                    fontSize: 11.5,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// 0012345678 → •••• 5678 (layar bisa terlihat orang di sebelah).
  static String _samarkan(String nomor) {
    final n = nomor.replaceAll(' ', '');
    if (n.length <= 4) return n;
    return '•••• ${n.substring(n.length - 4)}';
  }

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
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(22),
            onTap: () => setState(() => _rincianTerbuka = !_rincianTerbuka),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 14, 16),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: AppColors.paleBlue,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(
                      Icons.receipt_long_rounded,
                      color: AppColors.skyBlue,
                      size: 18,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Rincian Saldo',
                          style: GoogleFonts.outfit(
                            fontSize: 15.5,
                            fontWeight: FontWeight.bold,
                            color: AppColors.ink,
                          ),
                        ),
                        Text(
                          'Hak ${currency.format(netIncome)}  −  Setoran ${currency.format(debtTotal)}',
                          style: GoogleFonts.outfit(
                            fontSize: 12,
                            color: AppColors.inkSoft,
                          ),
                        ),
                      ],
                    ),
                  ),
                  AnimatedRotation(
                    turns: _rincianTerbuka ? 0.5 : 0,
                    duration: const Duration(milliseconds: 200),
                    child: const Icon(
                      Icons.expand_more_rounded,
                      color: AppColors.inkSoft,
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (_rincianTerbuka)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Begini saldo bersih Anda dihitung. Ketuk baris bertanda › untuk melihat transaksinya.',
                    style: GoogleFonts.outfit(
                      fontSize: 12,
                      color: AppColors.inkSoft,
                    ),
                  ),
                  const SizedBox(height: 16),

                  // === PEMASUKAN ===
                  FadeInUp(
                    delayMs: 60,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _sectionHeader(
                          Icons.trending_up_rounded,
                          'Pemasukan',
                          '$incomeCount transaksi',
                          AppColors.success,
                        ),
                        const SizedBox(height: 6),
                        _lineRow(
                          'Pendapatan kotor',
                          currency.format(gross),
                          // Baris bernilai 0 sengaja tidak dapat ditekan: membuka
                          // lembar kosong hanya membuat orang mengira fiturnya rusak.
                          bucket: incomeCount > 0 ? 'income' : null,
                        ),
                        _lineRow(
                          'Komisi koperasi ($ratePct%)',
                          '− ${currency.format(commission)}',
                          valueColor: AppColors.danger,
                          bucket: incomeCount > 0 ? 'income' : null,
                        ),
                        _divider(),
                        _lineRow(
                          'Hak bersih Anda',
                          currency.format(netIncome),
                          bold: true,
                          valueColor: AppColors.success,
                        ),
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
                        _sectionHeader(
                          Icons.account_balance_rounded,
                          'Setoran ke Koperasi',
                          '$tripCount trip',
                          AppColors.warning,
                        ),
                        const SizedBox(height: 6),
                        if (stdCount > 0)
                          _lineRow(
                            'Setoran komisi tunai',
                            currency.format(stdFee),
                            sub: '$stdCount trip order sistem',
                            bucket: 'debt_standard',
                          ),
                        if (manualCount > 0)
                          _lineRow(
                            'Biaya order manual',
                            currency.format(manualFee),
                            sub: '$manualCount trip × ${currency.format(flat)}',
                            bucket: 'debt_manual',
                          ),
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
                        _lineRow(
                          'Total setoran',
                          currency.format(debtTotal),
                          bold: true,
                          valueColor: AppColors.warning,
                        ),
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
                          color: AppColors.skyBlue.withValues(alpha: 0.18),
                        ),
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
            ),
        ],
      ),
    );
  }

  Widget _sectionHeader(
    IconData icon,
    String title,
    String trailing,
    Color color,
  ) {
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
  Widget _lineRow(
    String label,
    String value, {
    String? sub,
    Color? valueColor,
    bool bold = false,
    String? bucket,
  }) {
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
                      const Icon(
                        Icons.chevron_right_rounded,
                        size: 17,
                        color: AppColors.skyBlue,
                      ),
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

  Widget _emptyHistory() {
    return Padding(
      padding: const EdgeInsets.only(top: 30),
      child: Column(
        children: [
          Icon(
            Icons.receipt_long_rounded,
            size: 54,
            color: AppColors.inkFaint.withValues(alpha: 0.5),
          ),
          const SizedBox(height: 14),
          Text(
            "Belum ada riwayat penarikan.",
            style: GoogleFonts.outfit(color: AppColors.inkFaint),
          ),
        ],
      ),
    );
  }

  static DateTime? _tgl(dynamic v) =>
      v == null ? null : DateTime.tryParse('$v')?.toLocal();

  String _tglPendek(DateTime d) =>
      '${d.day} ${_bulan[d.month - 1].substring(0, 3)}, '
      '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  static String _kunciBulan(DateTime? d) =>
      d == null ? '-' : '${d.year}-${d.month}';

  /// Riwayat dikelompokkan per bulan, dengan total yang sudah terkirim.
  List<Widget> _riwayatPerBulan(NumberFormat currency) {
    final terkirim = <String, double>{};
    for (final x in _history) {
      if (x['status'] != 'Paid') continue;
      final k = _kunciBulan(_tgl(x['requested_at']));
      terkirim[k] =
          (terkirim[k] ?? 0) + (double.tryParse('${x['amount']}') ?? 0);
    }

    final out = <Widget>[];
    String? kunci;
    var idx = 0;
    for (final item in _history) {
      final d = _tgl(item['requested_at']);
      final k = _kunciBulan(d);
      if (k != kunci) {
        kunci = k;
        out.add(
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 14, 4, 8),
            child: Row(
              children: [
                Text(
                  d == null
                      ? 'Tanpa tanggal'
                      : '${_bulan[d.month - 1]} ${d.year}',
                  style: GoogleFonts.outfit(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.inkSoft,
                  ),
                ),
                const Spacer(),
                Text(
                  'Terkirim ${currency.format(terkirim[k] ?? 0)}',
                  style: GoogleFonts.outfit(
                    fontSize: 12,
                    color: AppColors.inkFaint,
                  ),
                ),
              ],
            ),
          ),
        );
      }
      out.add(
        FadeInUp(
          delayMs: (idx++ * 50).clamp(0, 300),
          child: _withdrawalItem(item, currency),
        ),
      );
    }
    return out;
  }

  Widget _withdrawalItem(dynamic item, NumberFormat currency) {
    final (Color warna, IconData ikon, String label) = switch (item['status']) {
      'Paid' => (AppColors.success, Icons.check_circle_rounded, 'Terkirim'),
      'Pending' => (AppColors.warning, Icons.schedule_rounded, 'Diproses'),
      'Rejected' => (AppColors.danger, Icons.cancel_rounded, 'Ditolak'),
      _ => (AppColors.inkFaint, Icons.help_rounded, '${item['status'] ?? '-'}'),
    };
    final diajukan = _tgl(item['requested_at']);
    final diproses = _tgl(item['processed_at']);
    final tujuan = '${item['payout_label'] ?? ''}'.trim();
    final bukti = _proofUrl(item);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
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
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: warna.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(ikon, color: warna, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        currency.format(
                          double.tryParse('${item['amount']}') ?? 0,
                        ),
                        style: GoogleFonts.outfit(
                          color: AppColors.ink,
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 9,
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
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                if (tujuan.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    'ke $tujuan',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.outfit(
                      color: AppColors.inkSoft,
                      fontSize: 12.5,
                    ),
                  ),
                ],
                const SizedBox(height: 4),
                Text(
                  [
                    if (diajukan != null) 'Diajukan ${_tglPendek(diajukan)}',
                    if (diproses != null && item['status'] != 'Pending')
                      '${item['status'] == 'Rejected' ? 'Ditolak' : 'Dikirim'} ${_tglPendek(diproses)}',
                  ].join('  ·  '),
                  style: GoogleFonts.outfit(
                    color: AppColors.inkFaint,
                    fontSize: 11.5,
                  ),
                ),
                if (bukti != null) ...[
                  const SizedBox(height: 8),
                  ActionChip(
                    avatar: const Icon(
                      Icons.image_outlined,
                      size: 16,
                      color: AppColors.skyBlue,
                    ),
                    label: const Text('Bukti transfer'),
                    labelStyle: GoogleFonts.outfit(
                      color: AppColors.skyBlue,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                    backgroundColor: AppColors.paleBlue,
                    side: BorderSide.none,
                    visualDensity: VisualDensity.compact,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(20),
                    ),
                    onPressed: () => _showProofDialog(context, bukti),
                  ),
                ],
              ],
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
    return (url != null && url.toString().trim().isNotEmpty)
        ? url.toString()
        : null;
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

class _WhiteButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;

  /// Varian sekunder: transparan dengan garis tepi putih. Dipakai agar
  /// "Setor Tunai" tidak bersaing perhatian dengan "Tarik Dana" — keduanya
  /// tindakan uang, tapi hanya satu yang jadi aksi utama kartu ini.
  final bool outlined;

  /// Warna teks/ikon tombol solid — mengikuti warna kartu (biru / oranye).
  final Color accent;

  const _WhiteButton({
    required this.label,
    required this.icon,
    this.onPressed,
    this.outlined = false,
    this.accent = AppColors.deepBlue,
  });

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null;
    final warnaTeks = outlined ? Colors.white : accent;

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
                Icon(
                  icon,
                  color: enabled ? warnaTeks : warnaTeks.withValues(alpha: 0.6),
                  size: 20,
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    label,
                    textAlign: TextAlign.center,
                    style: GoogleFonts.outfit(
                      color: enabled
                          ? warnaTeks
                          : warnaTeks.withValues(alpha: 0.6),
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
