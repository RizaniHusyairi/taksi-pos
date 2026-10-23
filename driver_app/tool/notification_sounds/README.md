# Suara notifikasi Angkasa Jaya

File hasil dipasang di `android/app/src/main/res/raw/` dan dipakai oleh kanal
di `lib/services/notification_service.dart`.

- `make_sounds.py <folder>` — klakson + chime sintetis: `kaj_order`, `kaj_queue`, `kaj_notif`.
- `mix_voice.py <suara.wav> <keluaran.wav>` — klakson "tin-tin" + rekaman suara.

## Dering order masuk dengan suara "Ada penumpang nih!!" (ElevenLabs)

Pilihan user 2026-10-23: suara **Liam** (`TX3LPaxmHKxFdv7VOQHJ`), model
`eleven_v3`, `language_code: id`, teks `[excited] Ada penumpang nih!!`,
`stability 0.3`, `similarity_boost 0.75`, keluaran `mp3_44100_128`.

Versi final WAJIB dibuat dari akun ElevenLabs **berbayar** (paket gratis tidak
boleh dipakai komersial). Langkah:

1. TTS → `liam.mp3`
2. `ffmpeg -i liam.mp3 -ac 1 -ar 44100 -af "silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse,highpass=f=80" liam.wav`
3. `python mix_voice.py liam.wav kaj_order.wav`
4. `ffmpeg -i kaj_order.wav -c:a libvorbis -q:a 5 ../../android/app/src/main/res/raw/kaj_order_voice.ogg`
5. Kanal order naik ke `order_alarm_channel_v3` (Android mengunci suara kanal).
