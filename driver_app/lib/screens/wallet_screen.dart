import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../services/api_service.dart';
import '../providers/auth_provider.dart';
import 'package:dio/dio.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  double _balance = 0;
  List<dynamic> _history = [];
  String _error = '';

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
          _history = histRes.data;

          // Set bank details from profile
          _bankName = profile?['bank_name'] ?? 'Bank BTN'; // Default to BTN
          _accountNumber = profile?['account_number'] ?? '';

          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted)
        setState(() {
          _error = 'Failed to load data';
          _isLoading = false;
        });
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
            child: const Text("Batal"),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text("Ya, Tarik Dana"),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      await _apiService.requestWithdrawal();
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Permintaan penarikan berhasil dikirim!'),
          ),
        );
      _fetchData();
    } catch (e) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal mengajukan penarikan')),
        );
    }
  }

  void _showEditBankDialog() {
    final accController = TextEditingController(text: _accountNumber);

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1A1A1A),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(ctx).viewInsets.bottom,
          left: 24,
          right: 24,
          top: 24,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              "Atur Rekening Pencairan",
              style: GoogleFonts.outfit(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: Colors.white,
              ),
            ),
            const SizedBox(height: 24),

            Text("Nama Bank", style: GoogleFonts.outfit(color: Colors.white70)),
            const SizedBox(height: 8),
            TextField(
              enabled: false,
              decoration: InputDecoration(
                filled: true,
                fillColor: Colors.white10,
                hintText: "Bank BTN",
                hintStyle: GoogleFonts.outfit(color: Colors.white),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
            ),
            const SizedBox(height: 16),

            Text(
              "Nomor Rekening",
              style: GoogleFonts.outfit(color: Colors.white70),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: accController,
              keyboardType: TextInputType.number,
              style: GoogleFonts.outfit(color: Colors.white),
              decoration: InputDecoration(
                filled: true,
                fillColor: Colors.white10,
                hintText: "Masukkan nomor rekening",
                hintStyle: GoogleFonts.outfit(color: Colors.white30),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(color: Colors.white10),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(color: Color(0xFFD4AF37)),
                ),
              ),
            ),
            const SizedBox(height: 32),

            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFFD4AF37),
                  foregroundColor: Colors.black,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                onPressed: () async {
                  if (accController.text.isEmpty) return;
                  try {
                    Navigator.pop(ctx);
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Menyimpan rekening...')),
                    );
                    await _apiService.updateBankDetails(accController.text);
                    await _fetchData(); // Refresh UI
                    if (mounted)
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Rekening berhasil disimpan!'),
                        ),
                      );
                  } catch (e) {
                    if (mounted)
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Gagal menyimpan rekening'),
                        ),
                      );
                  }
                },
                child: Text(
                  "SIMPAN REKENING",
                  style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
                ),
              ),
            ),
            const SizedBox(height: 32),
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
      backgroundColor: const Color(0xFF1A1A1A),
      appBar: AppBar(
        title: Text(
          'DOMPET',
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.bold,
            color: Colors.white,
          ),
        ),
        backgroundColor: Colors.transparent,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _fetchData,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // === Bank Account Card ===
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      color: Colors.white10,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: Colors.white10),
                    ),
                    child: Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.blue.withOpacity(0.2),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: const Icon(
                            Icons.account_balance,
                            color: Colors.blue,
                            size: 28,
                          ),
                        ),
                        const SizedBox(width: 16),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _bankName,
                                style: GoogleFonts.outfit(
                                  color: Colors.white70,
                                  fontSize: 12,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                _accountNumber.isEmpty
                                    ? "Belum Diatur"
                                    : _accountNumber,
                                style: GoogleFonts.outfit(
                                  color: Colors.white,
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
                              color: const Color(0xFFD4AF37),
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 24),

                  // Balance Card
                  Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(
                        colors: [Color(0xFFD4AF37), Color(0xFFA6862D)],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      borderRadius: BorderRadius.circular(16),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(0xFFD4AF37).withOpacity(0.3),
                          blurRadius: 20,
                          offset: const Offset(0, 10),
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
                                color: Colors.black54,
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const Icon(
                              Icons.account_balance_wallet,
                              color: Colors.black54,
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          currencyFormat.format(_balance),
                          style: GoogleFonts.outfit(
                            fontSize: 36,
                            fontWeight: FontWeight.w900,
                            color: Colors.black,
                          ),
                        ),
                        const SizedBox(height: 24),
                        SizedBox(
                          width: double.infinity,
                          child: ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: Colors.black,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                              ),
                              elevation: 0,
                            ),
                            onPressed: _balance >= 10000
                                ? _requestWithdrawal
                                : null,
                            child: Text(
                              'TARIK DANA',
                              style: GoogleFonts.outfit(
                                fontWeight: FontWeight.bold,
                                letterSpacing: 1,
                              ),
                            ),
                          ),
                        ),
                        if (_balance < 10000)
                          Padding(
                            padding: const EdgeInsets.only(top: 12),
                            child: Center(
                              child: Text(
                                "Minimal penarikan Rp 10.000",
                                style: GoogleFonts.outfit(
                                  fontSize: 12,
                                  color: Colors.black54,
                                ),
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 32),
                  Text(
                    "Riwayat Penarikan",
                    style: GoogleFonts.outfit(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 16),

                  if (_history.isEmpty)
                    Center(
                      child: Padding(
                        padding: const EdgeInsets.only(top: 32),
                        child: Column(
                          children: [
                            Icon(
                              Icons.history,
                              size: 48,
                              color: Colors.white10,
                            ),
                            const SizedBox(height: 16),
                            Text(
                              "Belum ada riwayat penarikan.",
                              style: GoogleFonts.outfit(color: Colors.white30),
                            ),
                          ],
                        ),
                      ),
                    )
                  else
                    ..._history.map((item) {
                      final status = item['status'];
                      Color statusColor;
                      IconData statusIcon;

                      switch (status) {
                        case 'Paid':
                          statusColor = Colors.green;
                          statusIcon = Icons.check_circle;
                          break;
                        case 'Pending':
                          statusColor = Colors.orange;
                          statusIcon = Icons.access_time_filled;
                          break;
                        case 'Rejected':
                          statusColor = Colors.red;
                          statusIcon = Icons.cancel;
                          break;
                        default:
                          statusColor = Colors.grey;
                          statusIcon = Icons.help;
                      }

                      return Container(
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.05),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: Colors.white10),
                        ),
                        child: ListTile(
                          contentPadding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 8,
                          ),
                          leading: Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: statusColor.withOpacity(0.2),
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Icon(
                              statusIcon,
                              color: statusColor,
                              size: 20,
                            ),
                          ),
                          title: Text(
                            currencyFormat.format(
                              double.parse(item['amount'].toString()),
                            ),
                            style: GoogleFonts.outfit(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 16,
                            ),
                          ),
                          subtitle: Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(
                              DateFormat(
                                'dd MMM yyyy, HH:mm',
                              ).format(DateTime.parse(item['requested_at'])),
                              style: GoogleFonts.outfit(
                                color: Colors.white38,
                                fontSize: 12,
                              ),
                            ),
                          ),
                          trailing: Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 10,
                              vertical: 4,
                            ),
                            decoration: BoxDecoration(
                              color: statusColor.withOpacity(0.1),
                              borderRadius: BorderRadius.circular(20),
                              border: Border.all(
                                color: statusColor.withOpacity(0.3),
                              ),
                            ),
                            child: Text(
                              status.toUpperCase(),
                              style: GoogleFonts.outfit(
                                color: statusColor,
                                fontSize: 10,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ),
                      );
                    }).toList(),

                  const SizedBox(height: 32),
                ],
              ),
            ),
    );
  }
}
