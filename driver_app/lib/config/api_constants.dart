class ApiConstants {
  // Ganti dengan IP komputer Anda jika jalankan di Emulator (misal 10.0.2.2 untuk Android Emulator)
  // Atau IP LAN jika di device fisik
  static const String baseUrl = 'http://192.168.1.22:8000/api';

  static const String login = '/login';
  static const String profile = '/driver/profile';
  static const String location = '/driver/location';
  static const String queueJoin = '/driver/queue/join';
  static const String queueLeave = '/driver/queue/leave';
  static const String activeOrder = '/driver/active-order';
  static const String completeOrder = '/driver/order/complete';
  static const String history = '/driver/history';
  static const String wallet = '/driver/balance';
  static const String withdrawals = '/driver/withdrawals';
  static const String requestWithdrawal = '/driver/withdrawals/request';
}
