import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:dio/dio.dart';
import 'api_service.dart';
import '../config/api_constants.dart';

class AuthService with ChangeNotifier {
  final ApiService _apiService = ApiService();
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  Map<String, dynamic>? _user;
  Map<String, dynamic>? get user => _user;

  bool get isAuthenticated => _user != null;

  Future<bool> tryAutoLogin() async {
    final token = await _storage.read(key: 'auth_token');
    if (token == null) return false;

    try {
      final response = await _apiService.client.get(ApiConstants.profile);
      _user = response.data;
      notifyListeners();
      return true;
    } catch (e) {
      await logout();
      return false;
    }
  }

  Future<void> login(String username, String password) async {
    _isLoading = true;
    notifyListeners();

    try {
      final url = '${ApiConstants.baseUrl}${ApiConstants.login}';
      print('Attempting login to: $url');
      print('Username: $username');

      final response = await _apiService.client.post(
        ApiConstants.login,
        data: {'username': username, 'password': password},
      );

      print('Response status: ${response.statusCode}');
      print('Response data: ${response.data}');

      final token = response.data['token'];
      final user = response.data['user'];

      if (token != null) {
        await _storage.write(key: 'auth_token', value: token);
        _user = user;
        notifyListeners();
      } else {
        throw 'Token tidak ditemukan dalam response';
      }
    } on DioException catch (e) {
      print('DioError: ${e.message}');
      print('DioError Type: ${e.type}');
      print('DioError Response: ${e.response}');
      throw e.response?.data['message'] ?? 'Koneksi Gagal: ${e.type}';
    } catch (e) {
      print('General Error: $e');
      throw 'Terjadi kesalahan sistem: $e';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    try {
      // Optional: Call logout API endpoint
      // await _apiService.client.post('/logout');
    } catch (e) {
      // Ignore
    }
    await _storage.delete(key: 'auth_token');
    _user = null;
    notifyListeners();
  }
}
