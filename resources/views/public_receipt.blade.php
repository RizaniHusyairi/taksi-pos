<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Struk #{{ $transaction->id }} — Taksi Angkasa Jaya</title>
    <style>
        :root { --blue:#0B4DA2; --blue2:#1769c4; --amber:#F59E0B; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
               background:#eef2f7; color:#1f2937; padding:18px; }
        .card { max-width:400px; margin:0 auto; background:#fff; border-radius:20px; overflow:hidden;
                box-shadow:0 12px 34px rgba(11,77,162,.14); }
        .head { background:linear-gradient(135deg,var(--blue),var(--blue2)); color:#fff;
                padding:22px 22px 18px; text-align:center; position:relative; }
        .head img { width:78px; filter:brightness(0) invert(1); opacity:.95; }
        .head .co { font-weight:800; letter-spacing:.5px; margin-top:6px; font-size:15px; }
        .head .loc { font-size:11px; opacity:.85; margin-top:2px; }
        .seal { position:absolute; top:14px; right:14px; background:rgba(255,255,255,.18);
                border:1px solid rgba(255,255,255,.45); color:#fff; font-size:10px; font-weight:800;
                padding:4px 10px; border-radius:20px; letter-spacing:.5px; }
        .body { padding:18px 22px; }
        .row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; font-size:13.5px; }
        .row + .row { border-top:1px dashed #e5e7eb; }
        .row .k { color:#6b7280; } .row .v { font-weight:600; text-align:right; }
        .route { background:#f3f6fb; border:1px solid #e5edf7; border-radius:12px; padding:12px 14px; margin:12px 0; }
        .route .label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; }
        .route .name { font-weight:800; font-size:16px; color:var(--blue); margin-top:2px; }
        .total { display:flex; justify-content:space-between; align-items:center; background:var(--blue);
                 color:#fff; border-radius:14px; padding:14px 18px; margin-top:6px; }
        .total .lbl { font-size:12px; opacity:.85; } .total .amt { font-size:22px; font-weight:800; }
        .qr { text-align:center; margin:18px 0 4px; }
        .qr img { width:108px; } .qr .cap { font-size:10px; color:#9ca3af; margin-top:4px; }
        .rate { border-top:1px solid #eef0f2; margin-top:8px; padding:18px 22px 6px; text-align:center; }
        .rate h3 { margin:0 0 10px; font-size:15px; }
        .stars { font-size:34px; line-height:1; user-select:none; }
        .stars span { cursor:pointer; color:#d1d5db; transition:transform .1s,color .1s; display:inline-block; padding:0 2px; }
        .stars span.on { color:var(--amber); }
        .stars span:active { transform:scale(1.25); }
        textarea { width:100%; margin-top:12px; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px;
                   font-family:inherit; font-size:13px; resize:vertical; min-height:52px; }
        .btn { width:100%; margin-top:12px; background:var(--blue); color:#fff; border:none; border-radius:12px;
               padding:13px; font-size:14px; font-weight:700; cursor:pointer; }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .thanks { padding:6px 0 4px; }
        .thanks .big { font-size:30px; } .thanks .msg { font-weight:700; margin-top:4px; color:#15803d; }
        .ro-stars { font-size:26px; color:var(--amber); letter-spacing:3px; margin-top:6px; }
        .foot { text-align:center; font-size:11px; color:#9ca3af; padding:14px 22px 20px; line-height:1.6; }
    </style>
</head>
<body>
    @php
        $b = $transaction->booking;
        $route = optional(optional($b)->zoneTo)->name ?: (optional($b)->manual_destination ?: 'Manual');
        $method = $transaction->method === 'CashDriver' ? 'Tunai (Supir)'
                : ($transaction->method === 'CashCSO' ? 'Tunai (Kasir)' : $transaction->method);
        $line = optional(optional(optional($b)->driver)->driverProfile)->line_number;
    @endphp
    <div class="card">
        <div class="head">
            <div class="seal">LUNAS</div>
            <img src="{{ asset('pos-assets/img/logo-apt.svg') }}" alt="Logo" onerror="this.style.display='none'">
            <div class="co">KOPERASI ANGKASA JAYA</div>
            <div class="loc">Bandar Udara APT. Pranoto Samarinda</div>
        </div>

        <div class="body">
            <div class="row"><span class="k">No. Struk</span><span class="v">#{{ $transaction->id }}</span></div>
            <div class="row"><span class="k">Waktu</span><span class="v">{{ $transaction->created_at->format('d M Y, H:i') }}</span></div>
            <div class="row"><span class="k">Kasir</span><span class="v">{{ optional(optional($b)->cso)->name ?: 'Admin' }}</span></div>
            <div class="row"><span class="k">Supir</span><span class="v">{{ optional(optional($b)->driver)->name ?: '-' }}@if($line) · L{{ $line }}@endif</span></div>
            <div class="row"><span class="k">Pembayaran</span><span class="v">{{ $method }}</span></div>

            <div class="route">
                <div class="label">Tujuan</div>
                <div class="name">{{ $route }}</div>
            </div>

            <div class="total"><span class="lbl">TOTAL DIBAYAR</span><span class="amt">Rp {{ number_format($transaction->amount, 0, ',', '.') }}</span></div>

            <div class="qr">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode(route('receipt.show', $transaction->receipt_token)) }}" alt="QR">
                <div class="cap">Scan untuk memvalidasi keaslian struk</div>
            </div>
        </div>

        <div class="rate" id="rateBox">
            @if($rating)
                <div class="thanks">
                    <div class="msg">Terima kasih atas penilaian Anda</div>
                    <div class="ro-stars">{!! str_repeat('★', (int) $rating->stars) . str_repeat('☆', 5 - (int) $rating->stars) !!}</div>
                    @if($rating->comment)<div style="font-size:12px;color:#6b7280;margin-top:8px;">“{{ $rating->comment }}”</div>@endif
                </div>
            @else
                <h3>Bagaimana perjalanan Anda?</h3>
                <div class="stars" id="stars">@for($i=1;$i<=5;$i++)<span data-v="{{ $i }}">★</span>@endfor</div>
                <textarea id="comment" placeholder="Tulis komentar untuk supir (opsional)…" maxlength="500"></textarea>
                <button class="btn" id="submitRate" disabled>Kirim Penilaian</button>
            @endif
        </div>

        <div class="foot">Simpan struk ini sebagai bukti pembayaran sah.<br>© {{ date('Y') }} Koperasi Taksi Angkasa Jaya</div>
    </div>

    @unless($rating)
    <script>
        (function () {
            let val = 0;
            const stars = Array.prototype.slice.call(document.querySelectorAll('#stars span'));
            const btn = document.getElementById('submitRate');
            stars.forEach(function (s) {
                s.addEventListener('click', function () {
                    val = +s.dataset.v;
                    stars.forEach(function (x) { x.classList.toggle('on', +x.dataset.v <= val); });
                    btn.disabled = false;
                });
            });
            btn.addEventListener('click', async function () {
                if (!val) return;
                btn.disabled = true; btn.textContent = 'Mengirim…';
                try {
                    const res = await fetch('{{ route('receipt.rate', $transaction->receipt_token) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                        },
                        body: JSON.stringify({ stars: val, comment: document.getElementById('comment').value })
                    });
                    const j = await res.json().catch(function () { return {}; });
                    document.getElementById('rateBox').innerHTML =
                        '<div class="thanks"><div class="big">🙏</div><div class="msg">' +
                        (j.message || 'Terima kasih!') + '</div><div class="ro-stars">' +
                        '★'.repeat(val) + '☆'.repeat(5 - val) + '</div></div>';
                } catch (e) {
                    btn.disabled = false; btn.textContent = 'Kirim Penilaian';
                    alert('Gagal mengirim penilaian. Silakan coba lagi.');
                }
            });
        })();
    </script>
    @endunless
</body>
</html>
