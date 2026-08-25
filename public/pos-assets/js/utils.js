// public/pos-assets/js/utils.js

export const Utils = {
    /**
     * Mengubah angka menjadi format mata uang Rupiah.
     * Sangat berguna untuk menampilkan harga dan total.
     */
    formatCurrency(n) {
        try {
            return new Intl.NumberFormat('id-ID', { 
                style: 'currency', 
                currency: 'IDR', 
                maximumFractionDigits: 0 
            }).format(n || 0);
        } catch (e) {
            return 'Rp ' + (n || 0).toLocaleString('id-ID');
        }
    },

    /**
     * Menampilkan notifikasi sementara (toast) di layar.
     *
     * Menggantikan alert() bawaan browser untuk pesan yang TIDAK memerlukan
     * jawaban: berhasil disimpan, gagal memuat, dan sejenisnya. Toast tidak
     * memblokir halaman dan tidak memaksa pengguna menekan tombol untuk pesan
     * yang sebenarnya hanya perlu dibaca sekilas.
     *
     * Tanda tangannya sengaja dipertahankan (msg, type) karena sudah dipakai
     * di beberapa tempat.
     */
    showToast(msg, type = 'info') {
        UIStyles.pastikanAda();

        let wadah = document.getElementById('kt-toast');
        if (!wadah) {
            wadah = document.createElement('div');
            wadah.id = 'kt-toast';
            wadah.className = 'kt-toast-wrap';
            // aria-live: pembaca layar mengumumkan isinya tanpa memindah fokus.
            wadah.setAttribute('aria-live', 'polite');
            wadah.setAttribute('role', 'status');
            document.body.appendChild(wadah);
        }

        const jenis = ['success', 'error', 'warning'].includes(type) ? type : 'info';
        const box = document.createElement('div');
        box.className = `kt-toast kt-toast--${jenis}`;
        box.innerHTML = `
            <span class="kt-toast__icon">${UIStyles.ikon(jenis)}</span>
            <span class="kt-toast__msg"></span>
            <button type="button" class="kt-toast__close" aria-label="Tutup">&times;</button>
            <span class="kt-toast__bar"></span>`;
        box.querySelector('.kt-toast__msg').textContent = msg;

        const tutup = () => {
            if (box.dataset.tutup) return;      // cegah dua kali
            box.dataset.tutup = '1';
            box.classList.add('kt-toast--keluar');
            box.addEventListener('transitionend', () => box.remove(), { once: true });
            // Jaring pengaman: kalau transitionend tidak pernah datang
            // (mis. tab tidak aktif), elemen tetap dibuang.
            setTimeout(() => box.remove(), 400);
        };

        box.querySelector('.kt-toast__close').addEventListener('click', tutup);
        wadah.appendChild(box);

        // Reflow paksa, BUKAN requestAnimationFrame: rAF tidak dijalankan
        // browser saat tab sedang di latar belakang, sehingga kelas "masuk"
        // tidak pernah terpasang dan toast-nya diam-diam tak pernah terlihat.
        void box.offsetWidth;
        box.classList.add('kt-toast--masuk');

        const durasi = jenis === 'error' ? 5000 : 3000;  // galat perlu waktu baca lebih
        let timer = setTimeout(tutup, durasi);

        // Jangan hilang saat sedang dibaca / disalin.
        box.addEventListener('mouseenter', () => {
            clearTimeout(timer);
            box.classList.add('kt-toast--tahan');
        });
        box.addEventListener('mouseleave', () => {
            box.classList.remove('kt-toast--tahan');
            timer = setTimeout(tutup, 1200);
        });
    },

    /**
     * Pengganti confirm() bawaan. Mengembalikan Promise<boolean>.
     *
     *   if (!await Utils.confirm({ title: '...', message: '...' })) return;
     */
    confirm(opsi = {}) {
        return UIDialog.buka({
            variant: 'danger',
            confirmText: 'Ya, Lanjutkan',
            cancelText: 'Batal',
            ...opsi,
            mode: 'confirm',
        });
    },

    /**
     * Pengganti prompt() bawaan. Mengembalikan Promise<string|null> —
     * null bila dibatalkan, sama seperti prompt() aslinya, sehingga pola
     * pemeriksaan di pemanggil tidak perlu berubah bentuk.
     */
    prompt(opsi = {}) {
        return UIDialog.buka({
            variant: 'info',
            confirmText: 'Simpan',
            cancelText: 'Batal',
            ...opsi,
            mode: 'prompt',
        });
    },
};

/* ========================================================================
 * Gaya & ikon dialog — disuntik sekali, tidak bergantung pada Tailwind.
 *
 * Ditulis sebagai CSS biasa (bukan utility) supaya komponen ini tetap benar
 * di halaman mana pun yang memuat utils.js, termasuk yang tidak menjalankan
 * Tailwind. Warnanya mengikuti tema lewat `.dark` pada elemen leluhur.
 * ===================================================================== */
const UIStyles = {
    sudah: false,

    ikon(jenis) {
        const p = {
            success: '<path d="M20 6L9 17l-5-5"/>',
            error:   '<path d="M18 6L6 18M6 6l12 12"/>',
            warning: '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
            info:    '<path d="M12 16v-4m0-4h.01"/><circle cx="12" cy="12" r="9"/>',
            danger:  '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        }[jenis] || '<circle cx="12" cy="12" r="9"/>';

        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
    },

    pastikanAda() {
        if (this.sudah || document.getElementById('kt-ui-styles')) { this.sudah = true; return; }
        this.sudah = true;

        const s = document.createElement('style');
        s.id = 'kt-ui-styles';
        s.textContent = `
/* ---------- Toast ---------- */
.kt-toast-wrap{position:fixed;right:1rem;bottom:1rem;z-index:9999;display:flex;
  flex-direction:column;align-items:flex-end;gap:.5rem;max-width:min(24rem,calc(100vw - 2rem));
  pointer-events:none}
.kt-toast{pointer-events:auto;position:relative;display:flex;align-items:flex-start;gap:.625rem;
  width:100%;padding:.75rem .875rem;border-radius:.875rem;overflow:hidden;
  background:#fff;color:#1f2937;border:1px solid #e5e7eb;
  box-shadow:0 12px 32px -10px rgba(15,23,42,.35),0 2px 6px -2px rgba(15,23,42,.15);
  opacity:0;transform:translateY(10px) scale(.97);
  transition:opacity .28s ease,transform .28s cubic-bezier(.22,1,.36,1)}
.dark .kt-toast{background:#172033;color:#e2e8f0;border-color:rgba(255,255,255,.10)}
.kt-toast--masuk{opacity:1;transform:translateY(0) scale(1)}
.kt-toast--keluar{opacity:0;transform:translateY(6px) scale(.97)}
.kt-toast__icon{flex:none;width:1.25rem;height:1.25rem;margin-top:.05rem}
.kt-toast__icon svg{width:100%;height:100%}
.kt-toast__msg{flex:1;font-size:.8125rem;line-height:1.35;font-weight:500;word-break:break-word}
.kt-toast__close{flex:none;border:0;background:none;cursor:pointer;font-size:1.05rem;line-height:1;
  color:currentColor;opacity:.35;padding:0 .1rem;transition:opacity .2s}
.kt-toast__close:hover{opacity:.9}
.kt-toast__bar{position:absolute;left:0;bottom:0;height:2.5px;width:100%;transform-origin:left;
  transform:scaleX(1);transition:transform linear}
.kt-toast--masuk .kt-toast__bar{transform:scaleX(0);transition-duration:3s}
.kt-toast--error.kt-toast--masuk .kt-toast__bar{transition-duration:5s}
.kt-toast--tahan .kt-toast__bar{transition:none;transform:scaleX(1)}
.kt-toast--success .kt-toast__icon{color:#059669}.kt-toast--success .kt-toast__bar{background:#10b981}
.kt-toast--error   .kt-toast__icon{color:#dc2626}.kt-toast--error   .kt-toast__bar{background:#ef4444}
.kt-toast--warning .kt-toast__icon{color:#d97706}.kt-toast--warning .kt-toast__bar{background:#f59e0b}
.kt-toast--info    .kt-toast__icon{color:#2563eb}.kt-toast--info    .kt-toast__bar{background:#3b82f6}

/* ---------- Dialog ---------- */
.kt-dlg-backdrop{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;
  justify-content:center;padding:1rem;background:rgba(15,23,42,.55);
  backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);
  opacity:0;transition:opacity .22s ease}
.kt-dlg-backdrop--masuk{opacity:1}
.kt-dlg{width:100%;max-width:26rem;border-radius:1.25rem;overflow:hidden;
  background:#fff;color:#1f2937;border:1px solid #e5e7eb;
  box-shadow:0 28px 70px -20px rgba(15,23,42,.55);
  transform:translateY(14px) scale(.94);opacity:0;
  transition:transform .34s cubic-bezier(.34,1.4,.5,1),opacity .24s ease}
.dark .kt-dlg{background:#172033;color:#e2e8f0;border-color:rgba(255,255,255,.10)}
.kt-dlg-backdrop--masuk .kt-dlg{transform:translateY(0) scale(1);opacity:1}
.kt-dlg--keluar .kt-dlg{transform:translateY(8px) scale(.96);opacity:0}
.kt-dlg__body{padding:1.5rem 1.5rem 1.125rem;text-align:center}
.kt-dlg__icon{width:3.25rem;height:3.25rem;margin:0 auto .875rem;border-radius:9999px;
  display:flex;align-items:center;justify-content:center;
  animation:ktPop .45s cubic-bezier(.34,1.56,.64,1) both}
.kt-dlg__icon svg{width:1.6rem;height:1.6rem}
@keyframes ktPop{0%{transform:scale(.4);opacity:0}100%{transform:scale(1);opacity:1}}
.kt-dlg--danger  .kt-dlg__icon{background:rgba(239,68,68,.12);color:#dc2626}
.kt-dlg--warning .kt-dlg__icon{background:rgba(245,158,11,.14);color:#d97706}
.kt-dlg--info    .kt-dlg__icon{background:rgba(59,130,246,.13);color:#2563eb}
.kt-dlg--success .kt-dlg__icon{background:rgba(16,185,129,.13);color:#059669}
.kt-dlg__title{font-size:1.0625rem;font-weight:800;line-height:1.3}
.kt-dlg__msg{margin-top:.4rem;font-size:.8125rem;line-height:1.5;opacity:.72}
.kt-dlg__field{margin-top:1rem;text-align:left}
.kt-dlg__label{display:block;font-size:.6875rem;font-weight:700;letter-spacing:.04em;
  text-transform:uppercase;opacity:.55;margin-bottom:.35rem}
.kt-dlg__input{width:100%;border-radius:.75rem;border:1px solid #e5e7eb;background:#f9fafb;
  color:inherit;padding:.6rem .75rem;font-size:.8125rem;font-family:inherit;resize:vertical;
  transition:border-color .18s,box-shadow .18s}
.dark .kt-dlg__input{background:rgba(0,0,0,.25);border-color:rgba(255,255,255,.12)}
.kt-dlg__input:focus{outline:0;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.18)}
.kt-dlg__err{margin-top:.4rem;font-size:.6875rem;font-weight:600;color:#dc2626;
  display:none;animation:ktGoyang .32s ease}
.kt-dlg__err--tampil{display:block}
@keyframes ktGoyang{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}
  75%{transform:translateX(4px)}}
.kt-dlg__actions{display:flex;gap:.5rem;padding:0 1.5rem 1.5rem}
.kt-dlg__btn{flex:1;border:0;cursor:pointer;border-radius:.75rem;padding:.65rem 1rem;
  font-size:.8125rem;font-weight:700;font-family:inherit;transition:transform .12s,filter .18s,background .18s}
.kt-dlg__btn:active{transform:scale(.97)}
.kt-dlg__btn--batal{background:#f1f5f9;color:#475569}
.dark .kt-dlg__btn--batal{background:rgba(255,255,255,.07);color:#cbd5e1}
.kt-dlg__btn--batal:hover{background:#e2e8f0}
.dark .kt-dlg__btn--batal:hover{background:rgba(255,255,255,.12)}
.kt-dlg__btn--ok{color:#fff}
.kt-dlg__btn--ok:hover{filter:brightness(1.07)}
.kt-dlg--danger  .kt-dlg__btn--ok{background:linear-gradient(135deg,#ef4444,#dc2626);
  box-shadow:0 8px 20px -8px rgba(220,38,38,.7)}
.kt-dlg--warning .kt-dlg__btn--ok{background:linear-gradient(135deg,#f59e0b,#d97706);
  box-shadow:0 8px 20px -8px rgba(217,119,6,.7)}
.kt-dlg--info    .kt-dlg__btn--ok{background:linear-gradient(135deg,#3b82f6,#2563eb);
  box-shadow:0 8px 20px -8px rgba(37,99,235,.7)}
.kt-dlg--success .kt-dlg__btn--ok{background:linear-gradient(135deg,#10b981,#059669);
  box-shadow:0 8px 20px -8px rgba(5,150,105,.7)}

@media (prefers-reduced-motion: reduce){
  .kt-toast,.kt-dlg,.kt-dlg-backdrop{transition-duration:.01ms}
  .kt-dlg__icon,.kt-dlg__err{animation:none}
  .kt-toast__bar{display:none}
}`;
        document.head.appendChild(s);
    },
};

/* ========================================================================
 * Dialog modal berbasis Promise, pengganti confirm() & prompt().
 * ===================================================================== */
const UIDialog = {
    aktif: null,

    buka(o) {
        UIStyles.pastikanAda();

        // Hanya satu dialog pada satu waktu; yang lama ditutup sebagai "batal".
        if (this.aktif) this.aktif.selesai(o.mode === 'prompt' ? null : false);

        return new Promise((resolve) => {
            const fokusSebelumnya = document.activeElement;

            const backdrop = document.createElement('div');
            backdrop.className = 'kt-dlg-backdrop';
            backdrop.innerHTML = `
                <div class="kt-dlg kt-dlg--${o.variant}" role="dialog" aria-modal="true" aria-labelledby="kt-dlg-title">
                  <div class="kt-dlg__body">
                    <div class="kt-dlg__icon">${UIStyles.ikon(o.variant)}</div>
                    <h3 class="kt-dlg__title" id="kt-dlg-title"></h3>
                    <p class="kt-dlg__msg"></p>
                    ${o.mode === 'prompt' ? `
                      <div class="kt-dlg__field">
                        <label class="kt-dlg__label" for="kt-dlg-input"></label>
                        <textarea id="kt-dlg-input" class="kt-dlg__input" rows="3"></textarea>
                        <div class="kt-dlg__err"></div>
                      </div>` : ''}
                  </div>
                  <div class="kt-dlg__actions">
                    <button type="button" class="kt-dlg__btn kt-dlg__btn--batal"></button>
                    <button type="button" class="kt-dlg__btn kt-dlg__btn--ok"></button>
                  </div>
                </div>`;

            // textContent, bukan innerHTML: judul & pesan sering memuat nama
            // yang berasal dari data.
            backdrop.querySelector('.kt-dlg__title').textContent = o.title || 'Konfirmasi';
            const pesanEl = backdrop.querySelector('.kt-dlg__msg');
            pesanEl.textContent = o.message || '';
            pesanEl.style.display = o.message ? '' : 'none';

            const btnBatal = backdrop.querySelector('.kt-dlg__btn--batal');
            const btnOk = backdrop.querySelector('.kt-dlg__btn--ok');
            btnBatal.textContent = o.cancelText;
            btnOk.textContent = o.confirmText;

            const input = backdrop.querySelector('.kt-dlg__input');
            const errEl = backdrop.querySelector('.kt-dlg__err');
            if (input) {
                backdrop.querySelector('.kt-dlg__label').textContent = o.label || 'Keterangan';
                input.placeholder = o.placeholder || '';
                input.value = o.defaultValue ?? '';
                if (o.multiline === false) input.rows = 1;
            }

            document.body.appendChild(backdrop);
            const scrollLama = document.body.style.overflow;
            document.body.style.overflow = 'hidden';   // halaman di belakang tidak ikut bergulir

            // Reflow paksa (alasan sama seperti pada toast).
            void backdrop.offsetWidth;
            backdrop.classList.add('kt-dlg-backdrop--masuk');
            setTimeout(() => (input || btnOk).focus(), 60);

            const selesai = (hasil) => {
                if (backdrop.dataset.tutup) return;
                backdrop.dataset.tutup = '1';
                UIDialog.aktif = null;

                document.removeEventListener('keydown', onKey, true);
                backdrop.classList.remove('kt-dlg-backdrop--masuk');
                backdrop.classList.add('kt-dlg--keluar');
                document.body.style.overflow = scrollLama;

                setTimeout(() => {
                    backdrop.remove();
                    // Kembalikan fokus ke tempat asalnya — kalau tidak, fokus
                    // melompat ke awal halaman setiap kali dialog ditutup.
                    if (fokusSebelumnya && fokusSebelumnya.focus) fokusSebelumnya.focus();
                }, 240);

                resolve(hasil);
            };

            const batal = () => selesai(o.mode === 'prompt' ? null : false);

            const setuju = () => {
                if (!input) return selesai(true);

                const nilai = input.value.trim();
                if (o.required && !nilai) {
                    errEl.textContent = o.requiredMessage || 'Bagian ini wajib diisi.';
                    errEl.classList.add('kt-dlg__err--tampil');
                    // Paksa ulang animasi goyang agar terasa saat ditekan lagi.
                    errEl.style.animation = 'none';
                    void errEl.offsetWidth;
                    errEl.style.animation = '';
                    input.focus();
                    return;
                }
                selesai(nilai);
            };

            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); batal(); return; }
                if (e.key === 'Enter') {
                    // Isian satu baris: Enter mengirim, seperti input biasa.
                    // Isian banyak baris: Enter = baris baru, Ctrl/Cmd+Enter mengirim.
                    const banyakBaris = input && o.multiline !== false;
                    if (banyakBaris && !(e.ctrlKey || e.metaKey)) return;
                    e.preventDefault();
                    setuju();
                    return;
                }
                // Jerat fokus sederhana: Tab hanya berputar di antara dua tombol
                // dan input, tidak lolos ke halaman di belakang dialog.
                if (e.key === 'Tab') {
                    const bisaFokus = [input, btnBatal, btnOk].filter(Boolean);
                    if (!bisaFokus.includes(document.activeElement)) {
                        e.preventDefault();
                        bisaFokus[0].focus();
                        return;
                    }
                    const i = bisaFokus.indexOf(document.activeElement);
                    const next = e.shiftKey
                        ? (i - 1 + bisaFokus.length) % bisaFokus.length
                        : (i + 1) % bisaFokus.length;
                    e.preventDefault();
                    bisaFokus[next].focus();
                }
            };

            btnBatal.addEventListener('click', batal);
            btnOk.addEventListener('click', setuju);
            backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) batal(); });
            document.addEventListener('keydown', onKey, true);

            UIDialog.aktif = { selesai };
        });
    },
};