/// Zona tujuan beserta tarifnya (sumber: GET /cso/zones).
class Zone {
  final int id;
  final String name;
  final num price;

  /// 'dalam' (Dalam Kota Samarinda) | 'luar' (Luar Kota) — ikut poster tarif.
  final String category;

  /// Keterangan area (mis. "Depan Bandara"). Null bila tidak ada.
  final String? description;

  const Zone({
    required this.id,
    required this.name,
    required this.price,
    this.category = 'dalam',
    this.description,
  });

  bool get isLuarKota => category == 'luar';

  factory Zone.fromJson(Map<String, dynamic> json) {
    final desc = json['description']?.toString().trim();
    return Zone(
      id: json['id'] as int,
      name: (json['name'] ?? 'Zona').toString(),
      price: json['price'] is num
          ? json['price'] as num
          : num.tryParse(json['price']?.toString() ?? '0') ?? 0,
      category: json['category']?.toString() == 'luar' ? 'luar' : 'dalam',
      description: (desc == null || desc.isEmpty) ? null : desc,
    );
  }
}
