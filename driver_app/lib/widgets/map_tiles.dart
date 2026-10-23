import 'package:flutter_map/flutter_map.dart';

/// Sumber tile peta untuk peta CSO & supir — satu tempat supaya keduanya
/// tidak lagi berbeda.
///
/// Dulu memakai CARTO basemaps; sejak CARTO mewajibkan API key, setiap tile
/// hanya berisi tulisan "API KEY REQUIRED" sehingga peta tampak kosong.
/// Sekarang OpenStreetMap standar (sama dengan panel admin). Kebijakan OSM:
/// User-Agent harus mengenali aplikasi, atribusi wajib tampil, dan jangan
/// memaksa tile @2x (retina) karena melipatgandakan beban server mereka.
TileLayer kajTileLayer() => TileLayer(
      urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      userAgentPackageName: 'id.aptpairport.kaj.driver',
      maxNativeZoom: 19,
    );
