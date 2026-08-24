import 'dart:io';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'connectivity_service.dart';

class ApiService {
  // Testing via USB: phone reaches the PC server through `adb reverse tcp:8000 tcp:8000`,
  // so localhost on the phone tunnels to the PC. No Wi-Fi/firewall needed.
  //   LAN alternative: http://<PC-LAN-IP>:8000/api  (needs server on 0.0.0.0 + firewall open)
  //   Production:      https://kaj.aptpairport.id/api
  //
  // Nilai bawaan = PRODUKSI, supaya build rilis tidak pernah lagi ikut terbawa
  // alamat uji coba karena lupa mengembalikannya. Untuk menguji, timpa lewat
  // --dart-define (tanpa menyentuh berkas ini):
  //   flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api   (adb reverse)
  //   flutter run --dart-define=API_BASE_URL=http://10.10.20.71:8000/api (Wi-Fi LAN)
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://kaj.aptpairport.id/api',
  );

  /// Base URL untuk aset publik (mis. /storage/...) — baseUrl tanpa sufiks '/api'.
  static String get assetBaseUrl => baseUrl.endsWith('/api')
      ? baseUrl.substring(0, baseUrl.length - 4)
      : baseUrl;

  late Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiService() {
    _dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 30),
        receiveTimeout: const Duration(seconds: 30),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    // Bypass SSL Certificate Verifications for HandshakeException
    _dio.httpClientAdapter = IOHttpClientAdapter(
      createHttpClient: () {
        final client = HttpClient();
        client.badCertificateCallback =
            (X509Certificate cert, String host, int port) => true;
        return client;
      },
    );

    // Add interceptor to attach token
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _storage.read(key: 'auth_token');
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          return handler.next(options);
        },
        onResponse: (response, handler) {
          // Respons sukses => tandai online (sembunyikan banner offline global).
          AppConnectivity.instance.reportSuccess();
          return handler.next(response);
        },
        onError: (DioException e, handler) {
          // Error koneksi => tandai offline (tampilkan banner global).
          AppConnectivity.instance.reportError(e);
          if (e.response?.statusCode == 401) {
            // Token invalid/sesi berakhir — penanganan logout per-pemanggil.
          }
          return handler.next(e);
        },
      ),
    );
  }

  // --- Auth Endpoints ---

  Future<Response> login(String username, String password) async {
    return await _dio.post(
      '/login',
      data: {'username': username, 'password': password},
    );
  }

  Future<Response> logout() async {
    return await _dio.post('/logout');
  }

  // --- Driver Endpoints ---

  Future<Response> getProfile() async {
    return await _dio.get('/driver/profile');
  }

  /// [accuracy] = radius ketidakpastian fix dalam meter (Position.accuracy).
  /// Server memakainya untuk MENUNDA keputusan di dalam/luar area saat fix-nya
  /// meragukan — posisinya tetap disimpan. Opsional agar aman bila null.
  ///
  /// [isMocked] = Position.isMocked, yaitu Android sendiri yang memberi tahu
  /// bahwa fix ini datang dari mock provider (aplikasi fake GPS). Server
  /// menolak fix seperti ini dan mengeluarkan supir dari antrian.
  /// Flag ini TIDAK berdiri sendiri sebagai pengaman — APK modifikasi tinggal
  /// tidak mengirimnya — makanya server juga menghitung lompatan mustahil
  /// antar-ping. Lihat DriverApiController::periksaLokasiPalsu().
  Future<Response> updateLocation(
    double lat,
    double lng, {
    double? accuracy,
    bool? isMocked,
  }) async {
    return await _dio.post(
      '/driver/location',
      data: {
        'latitude': lat,
        'longitude': lng,
        if (accuracy != null) 'accuracy': accuracy,
        if (isMocked != null) 'is_mocked': isMocked,
      },
    );
  }

  /// Titik pusat & radius geofence bandara — untuk peta di beranda supir.
  Future<Response> getAirportArea() async {
    return await _dio.get('/driver/airport-area');
  }

  Future<Response> setStatus(
    String action, {
    String? reason,
    String? manualDestination,
    int? manualPrice,
    double? latitude,
    double? longitude,
    bool? isMocked,
  }) async {
    final Map<String, dynamic> data = {'action': action};
    if (reason != null) data['reason'] = reason;
    if (manualDestination != null) {
      data['manual_destination'] = manualDestination;
    }
    if (manualPrice != null) data['manual_price'] = manualPrice;
    if (latitude != null) data['latitude'] = latitude;
    if (longitude != null) data['longitude'] = longitude;
    // Pintu masuk antrian dijaga sama ketatnya dengan ping berkala — lihat
    // catatan isMocked di updateLocation().
    if (isMocked != null) data['is_mocked'] = isMocked;

    return await _dio.post('/driver/status', data: data);
  }

  Future<Response> getBalance() async {
    return await _dio.get('/driver/balance');
  }

  Future<Response> getWithdrawalHistory() async {
    return await _dio.get('/driver/withdrawals');
  }

  Future<Response> requestWithdrawal() async {
    return await _dio.post('/driver/withdrawals');
  }

  Future<Response> getTripHistory() async {
    return await _dio.get('/driver/history');
  }

  Future<Response> startBooking(int bookingId) async {
    return await _dio.post('/driver/bookings/$bookingId/start');
  }

  Future<Response> completeBooking(int bookingId) async {
    return await _dio.post('/driver/bookings/$bookingId/complete');
  }

  Future<Response> updateBankDetails(String accountNumber) async {
    return await _dio.post(
      '/driver/bank-details',
      data: {'account_number': accountNumber},
    );
  }

  Future<Response> updateProfile(Map<String, dynamic> data) async {
    return await _dio.post('/driver/profile/update', data: data);
  }

  Future<Response> changePassword(
    String currentPassword,
    String newPassword,
  ) async {
    return await _dio.post(
      '/driver/change-password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
        'new_password_confirmation': newPassword,
      },
    );
  }

  Future<Response> updateFcmToken(String token) async {
    return await _dio.post(
      '/driver/update-fcm-token',
      data: {'fcm_token': token},
    );
  }

  // --- CSO Endpoints ---

  Future<Response> getCsoProfile() async {
    return await _dio.get('/cso/profile');
  }

  Future<Response> updateCsoProfile(Map<String, dynamic> data) async {
    return await _dio.post('/cso/profile/update', data: data);
  }

  Future<Response> changeCsoPassword(
    String currentPassword,
    String newPassword,
  ) async {
    return await _dio.post(
      '/cso/profile/password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
        'new_password_confirmation': newPassword,
      },
    );
  }

  Future<Response> getCsoZones() async {
    return await _dio.get('/cso/zones');
  }

  Future<Response> getCsoAvailableDrivers() async {
    return await _dio.get('/cso/available-drivers');
  }

  Future<Response> getCsoCompanyQris() async {
    return await _dio.get('/cso/company-qris');
  }

  Future<Response> getCsoDriverLocations() async {
    return await _dio.get('/cso/driver-locations');
  }

  Future<Response> getCsoDashboardStats() async {
    return await _dio.get('/cso/dashboard-stats');
  }

  Future<Response> getCsoHistory({String? startDate, String? endDate}) async {
    final Map<String, dynamic> params = {};
    if (startDate != null && endDate != null) {
      params['start_date'] = startDate;
      params['end_date'] = endDate;
    }
    return await _dio.get(
      '/cso/history',
      queryParameters: params.isEmpty ? null : params,
    );
  }

  /// Membuat order CSO (booking + transaksi + notifikasi) dalam satu panggilan.
  /// [proofImagePath] hanya diisi untuk metode QRIS (foto bukti transfer).
  Future<Response> csoProcessOrder({
    required int driverId,
    required int zoneId,
    required String method,
    required String passengerPhone,
    String? proofImagePath,
  }) async {
    final Map<String, dynamic> fields = {
      'driver_id': driverId,
      'zone_id': zoneId,
      'method': method,
      'passenger_phone': passengerPhone,
    };
    if (proofImagePath != null) {
      fields['payment_proof'] = await MultipartFile.fromFile(proofImagePath);
    }
    return await _dio.post(
      '/cso/process-order',
      data: FormData.fromMap(fields),
    );
  }

  // --- Setoran tunai CSO ke admin ---

  /// Rekap tunai yang belum disetor, dikelompokkan per tanggal.
  Future<Response> getCsoDepositOutstanding() async {
    return await _dio.get('/cso/deposits/outstanding');
  }

  /// Ajukan setoran untuk [dates] (format 'YYYY-MM-DD').
  /// Nominalnya dihitung server dari tanggal-tanggal itu — klien tidak
  /// mengirim angka apa pun, supaya tidak mungkin berbeda dengan catatan asli.
  Future<Response> createCsoDeposit(
    List<String> dates, {
    String? note,
    String? proofPath,
  }) async {
    final Map<String, dynamic> fields = {
      // Dio mengirim List sebagai dates[]=..., sesuai validasi 'dates.*'.
      'dates': dates,
      if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
    };
    if (proofPath != null) {
      fields['proof_image'] = await MultipartFile.fromFile(proofPath);
    }
    return await _dio.post('/cso/deposits', data: FormData.fromMap(fields));
  }

  /// Riwayat setoran CSO yang sedang login (paginated).
  Future<Response> getCsoDeposits() async {
    return await _dio.get('/cso/deposits');
  }

  /// Menyanggah metode pembayaran satu transaksi: "saya tidak menerima tunai
  /// ini". Server memeriksa sendiri kelayakannya — aplikasi cukup membaca flag
  /// `can_dispute` dari riwayat trip untuk menampilkan tombolnya.
  Future<Response> disputePaymentMethod(int transactionId, String note) async {
    return await _dio.post(
      '/driver/transactions/$transactionId/dispute-method',
      data: {'note': note},
    );
  }

  // --- Setoran tunai SUPIR ke admin (pelunasan utang komisi) ---
  //
  // Bentuknya kembar dengan setoran CSO di atas. Satu bedanya penting: nominal
  // di sini adalah KOMISI yang terutang, bukan tarif penuh — supir menyetor
  // bagian koperasi, bukan seluruh ongkos penumpang.

  /// Rekap utang komisi yang belum disetor, dikelompokkan per tanggal.
  Future<Response> getDriverDepositOutstanding() async {
    return await _dio.get('/driver/deposits/outstanding');
  }

  /// Ajukan setoran untuk [dates] (format 'YYYY-MM-DD').
  /// Nominalnya dihitung server — klien tidak mengirim angka apa pun.
  Future<Response> createDriverDeposit(
    List<String> dates, {
    String? note,
    String? proofPath,
  }) async {
    final Map<String, dynamic> fields = {
      'dates': dates,
      if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
    };
    if (proofPath != null) {
      fields['proof_image'] = await MultipartFile.fromFile(proofPath);
    }
    return await _dio.post('/driver/deposits', data: FormData.fromMap(fields));
  }

  /// Riwayat setoran supir yang sedang login (paginated).
  Future<Response> getDriverDeposits() async {
    return await _dio.get('/driver/deposits');
  }

  Future<Response> csoChangeDriver(int bookingId, int newDriverId) async {
    return await _dio.post(
      '/cso/bookings/$bookingId/change-driver',
      data: {'new_driver_id': newDriverId},
    );
  }
}
