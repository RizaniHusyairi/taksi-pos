"""Sintesis 3 suara notifikasi Angkasa Jaya (timbre bel/marimba, nuansa
chime bandara). Keluaran WAV mono 44.1 kHz; dikonversi ke OGG oleh ffmpeg."""
import sys
import wave
import numpy as np

SR = 44100


def note(freq, dur, amp=1.0, decay=6.0, partials=((1, 1.0), (2.0, 0.45), (3.0, 0.18), (4.16, 0.12), (5.43, 0.05))):
    t = np.arange(int(SR * dur)) / SR
    sig = np.zeros_like(t)
    for ratio, a in partials:
        # Parsial atas meluruh lebih cepat → serangan cerah, ekor lembut.
        sig += a * np.sin(2 * np.pi * freq * ratio * t) * np.exp(-decay * ratio ** 0.7 * t)
    attack = np.minimum(1, t / 0.004)
    return amp * sig * attack


def horn(dur, amp=1.0):
    """Klakson mobil dua nada (± F#4 & A#4, seperti klakson sedan): gelombang
    kaya harmonik, dipotong lembut (tanh), lalu dilunakkan low-pass satu kutub."""
    t = np.arange(int(SR * dur)) / SR
    sig = np.zeros_like(t)
    for f in (370.0, 466.0):
        for h in range(1, 12):
            sig += np.sin(2 * np.pi * f * h * t) / h ** 1.1
    sig = np.tanh(1.8 * sig / np.max(np.abs(sig)))
    out = np.empty_like(sig)
    a = 0.35  # low-pass satu kutub: buang desis frekuensi tinggi
    acc = 0.0
    for i, x in enumerate(sig):
        acc += a * (x - acc)
        out[i] = acc
    env = np.minimum(1, t / 0.012) * np.minimum(1, (dur - t) / 0.03)
    return amp * out * env


def place(buf, start, sig):
    i = int(start * SR)
    end = min(len(buf), i + len(sig))
    buf[i:end] += sig[: end - i]


def finish(buf, peak_db=-1.0):
    buf = buf / np.max(np.abs(buf)) * (10 ** (peak_db / 20))
    fade = int(0.02 * SR)
    buf[-fade:] *= np.linspace(1, 0, fade)
    return buf


def save(path, buf):
    data = (np.clip(buf, -1, 1) * 32767).astype(np.int16)
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(data.tobytes())


def main(out):
    G5, A5, B5, C6, D6, E6, G6, C7 = 783.99, 880.0, 987.77, 1046.5, 1174.66, 1318.51, 1567.98, 2093.0

    # 1) ORDER MASUK — klakson "tin-tin" lalu motif chime naik yang tegas.
    #    Diputar berulang (FLAG_INSISTENT), jadi diakhiri jeda singkat.
    order = np.zeros(int(SR * 2.4))
    place(order, 0.00, horn(0.16, amp=0.9))
    place(order, 0.24, horn(0.30, amp=0.9))
    base = 0.72
    for k, f in enumerate((G5, C6, E6)):
        place(order, base + k * 0.11, note(f, 0.5, amp=0.8, decay=9))
    place(order, base + 0.33, note(G6, 0.9, amp=1.0, decay=4.0))
    place(order, base + 0.33, note(C6, 0.9, amp=0.45, decay=4.0))  # badan akor
    order = finish(order)

    # 2) GILIRAN ANTREAN — satu klakson pendek lalu chime bandara 3 nada.
    queue = np.zeros(int(SR * 2.0))
    place(queue, 0.0, horn(0.22, amp=0.6))
    for k, f in enumerate((E6, G6, C6)):
        place(queue, 0.38 + k * 0.32, note(f, 1.2, amp=0.9, decay=3.2,
                                           partials=((1, 1.0), (2.0, 0.3), (3.0, 0.08), (4.16, 0.05))))
    queue = finish(queue, peak_db=-3.0)

    # 3) NOTIFIKASI UMUM — klakson halus sekejap lalu "ting" dua nada.
    notif = np.zeros(int(SR * 1.0))
    place(notif, 0.0, horn(0.12, amp=0.35))
    place(notif, 0.20, note(D6, 0.5, amp=0.7, decay=8))
    place(notif, 0.29, note(A5 * 2, 0.65, amp=1.0, decay=6))
    notif = finish(notif, peak_db=-2.0)

    for name, buf in (('kaj_order', order), ('kaj_queue', queue), ('kaj_notif', notif)):
        save(f'{out}/{name}.wav', buf)
        print(name, f'{len(buf) / SR:.2f}s')


if __name__ == '__main__':
    main(sys.argv[1])
