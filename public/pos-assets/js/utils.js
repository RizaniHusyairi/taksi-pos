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
     * Sangat berguna untuk feedback ke pengguna setelah aksi.
     */
    showToast(msg, type = 'info') {
        let el = document.getElementById('kt-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'kt-toast';
            // Posisikan di pojok kanan bawah agar lebih umum
            el.className = 'fixed bottom-4 right-4 z-50';
            document.body.appendChild(el);
        }
        
        const box = document.createElement('div');
        const color = type === 'success' ? 'bg-emerald-600' : (type === 'error' ? 'bg-red-600' : 'bg-slate-800');
        
        // Animasi sederhana untuk masuk dan keluar
        box.className = color + ' text-white px-4 py-2 rounded-lg shadow-lg mb-2 transition-all duration-300 transform translate-y-4 opacity-0';
        box.textContent = msg;
        el.appendChild(box);

        // Animate in
        setTimeout(() => {
            box.classList.remove('translate-y-4', 'opacity-0');
        }, 10);

        // Animate out and remove
        setTimeout(() => {
            box.classList.add('opacity-0');
            box.addEventListener('transitionend', () => box.remove());
        }, 2500);
    }
};