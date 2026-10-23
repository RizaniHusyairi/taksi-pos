"""Gabungkan klakson "tin-tin" + suara "Ada penumpang nih!!" menjadi dering
order masuk. Pemakaian: mix_voice.py <suara.wav (mono 44.1k)> <keluaran.wav>"""
import sys
import wave
import numpy as np

sys.path.insert(0, sys.argv[3] if len(sys.argv) > 3 else __import__("os").path.dirname(__file__))
from make_sounds import horn, place, save, SR  # noqa: E402


def load(path):
    with wave.open(path, 'rb') as w:
        assert w.getframerate() == SR and w.getnchannels() == 1
        return np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float64) / 32768


suara = load(sys.argv[1])
suara = suara / np.max(np.abs(suara)) * 0.95

mulai_suara = 0.62
total = mulai_suara + len(suara) / SR + 0.45  # jeda sebelum diulang (insistent)
buf = np.zeros(int(SR * total))
place(buf, 0.00, horn(0.16, amp=0.8))
place(buf, 0.24, horn(0.30, amp=0.8))
place(buf, mulai_suara, suara)

buf = buf / np.max(np.abs(buf)) * (10 ** (-1 / 20))
fade = int(0.02 * SR)
buf[-fade:] *= np.linspace(1, 0, fade)
save(sys.argv[2], buf)
print(f'{len(buf) / SR:.2f}s')
