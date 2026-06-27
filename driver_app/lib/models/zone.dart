/// Zona tujuan beserta tarifnya (sumber: GET /cso/zones).
class Zone {
  final int id;
  final String name;
  final num price;

  const Zone({required this.id, required this.name, required this.price});

  factory Zone.fromJson(Map<String, dynamic> json) {
    return Zone(
      id: json['id'] as int,
      name: (json['name'] ?? 'Zona').toString(),
      price: json['price'] is num
          ? json['price'] as num
          : num.tryParse(json['price']?.toString() ?? '0') ?? 0,
    );
  }
}
