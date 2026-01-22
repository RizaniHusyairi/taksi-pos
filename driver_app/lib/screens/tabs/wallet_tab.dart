import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../config/api_constants.dart';
import '../../services/api_service.dart';

class WalletTab extends StatefulWidget {
  const WalletTab({super.key});

  @override
  State<WalletTab> createState() => _WalletTabState();
}

class _WalletTabState extends State<WalletTab> {
  final _api = ApiService();
  bool _isLoading = true;
  Map<String, dynamic>? _data;
  List<dynamic> _withdrawals = [];

  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  Future<void> _fetchData() async {
    try {
      final resBalance = await _api.client.get(ApiConstants.wallet);
      final resWd = await _api.client.get(ApiConstants.withdrawals);

      if (mounted) {
        setState(() {
          _data = resBalance.data;
          _withdrawals = resWd.data;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _requestWithdrawal() async {
    try {
      setState(() => _isLoading = true);
      await _api.client.post(ApiConstants.requestWithdrawal);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Permintaan pencairan berhasil dikirim'),
          backgroundColor: Colors.green,
        ),
      );
      _fetchData();
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Gagal request pencairan'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) return const Center(child: CircularProgressIndicator());

    final balance = _data?['balance'] ?? 0;
    final income = _data?['income_pending'] ?? 0;
    final debt = _data?['debt_pending'] ?? 0;
    final fmt = NumberFormat.currency(
      locale: 'id',
      symbol: 'Rp ',
      decimalDigits: 0,
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Dompet Saya')),
      body: RefreshIndicator(
        onRefresh: _fetchData,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Balance Card
            Card(
              color: const Color(0xFF2563eb),
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Saldo Bersih Siap Cair',
                      style: TextStyle(color: Colors.white70),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      fmt.format(balance),
                      style: const TextStyle(
                        fontSize: 32,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                      ),
                    ),
                    const Divider(color: Colors.white24, height: 32),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Pemasukan: ${fmt.format(income)}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 12,
                          ),
                        ),
                        Text(
                          'Potongan: ${fmt.format(debt)}',
                          style: const TextStyle(
                            color: Colors.redAccent,
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),

            // Action Button
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: balance >= 10000 ? _requestWithdrawal : null,
                style: ElevatedButton.styleFrom(
                  backgroundColor: balance >= 10000
                      ? const Color(0xFF2563eb)
                      : Colors.grey,
                ),
                child: Text(
                  balance >= 10000
                      ? 'CAIRKAN DANA SEKARANG'
                      : 'Saldo Belum Cukup (< 10rb)',
                ),
              ),
            ),

            const SizedBox(height: 24),
            const Text(
              'Riwayat Penarikan',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 12),

            // Withdrawal List
            ..._withdrawals
                .map(
                  (w) => Card(
                    margin: const EdgeInsets.only(bottom: 12),
                    child: ListTile(
                      title: Text(
                        fmt.format(w['amount']),
                        style: const TextStyle(fontWeight: FontWeight.bold),
                      ),
                      subtitle: Text(
                        DateFormat(
                          'dd MMM yyyy, HH:mm',
                        ).format(DateTime.parse(w['requested_at'])),
                      ),
                      trailing: Chip(
                        label: Text(
                          w['status'],
                          style: const TextStyle(
                            fontSize: 10,
                            color: Colors.white,
                          ),
                        ),
                        backgroundColor:
                            w['status'] == 'Paid' || w['status'] == 'Approved'
                            ? Colors.green
                            : Colors.orange,
                        padding: EdgeInsets.zero,
                        visualDensity: VisualDensity.compact,
                      ),
                    ),
                  ),
                )
                ,

            if (_withdrawals.isEmpty)
              const Padding(
                padding: EdgeInsets.all(20),
                child: Text(
                  'Belum ada riwayat penarikan.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
