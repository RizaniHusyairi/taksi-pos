import 'dart:io';
import 'package:dio/dio.dart';

/// True kalau error berasal dari masalah koneksi/jaringan (offline, timeout),
/// bukan respons dari server. Dipakai untuk membedakan "internet mati" vs
/// "server menolak".
bool isConnectionError(Object? e) {
  if (e is SocketException) return true;
  if (e is DioException) {
    switch (e.type) {
      case DioExceptionType.connectionError:
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return true;
      case DioExceptionType.unknown:
        return e.error is SocketException;
      default:
        return false;
    }
  }
  return false;
}

/// Mengubah error apa pun menjadi pesan ramah berbahasa Indonesia yang aman
/// ditampilkan ke pengguna (tanpa membocorkan stack trace / detail teknis).
String apiErrorMessage(Object? e) {
  if (e is DioException) {
    if (isConnectionError(e)) {
      if (e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.sendTimeout) {
        return 'Server terlalu lama merespons. Periksa koneksi lalu coba lagi.';
      }
      return 'Tidak ada koneksi internet. Periksa jaringan Anda.';
    }

    final res = e.response;
    if (res != null) {
      // Utamakan pesan yang dikirim server.
      final data = res.data;
      if (data is Map) {
        final msg = data['message'];
        if (msg is String && msg.isNotEmpty) return msg;
        final err = data['error'];
        if (err is String && err.isNotEmpty) return err;
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) return first.first.toString();
        }
      }
      final code = res.statusCode ?? 0;
      if (code == 401) return 'Sesi Anda berakhir. Silakan masuk kembali.';
      if (code == 403) return 'Anda tidak memiliki akses untuk tindakan ini.';
      if (code == 404) return 'Data tidak ditemukan.';
      if (code == 422) return 'Data yang dikirim tidak valid.';
      if (code >= 500) return 'Server sedang bermasalah. Coba beberapa saat lagi.';
      return 'Terjadi kesalahan (kode $code).';
    }
    return 'Tidak ada koneksi internet. Periksa jaringan Anda.';
  }

  if (e is SocketException) {
    return 'Tidak ada koneksi internet. Periksa jaringan Anda.';
  }
  return 'Terjadi kesalahan. Silakan coba lagi.';
}
