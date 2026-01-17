export async function apiFetch(url, options = {}) {
  const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ...(options.headers || {})
    },
    ...options
  });

  if (!response.ok) {
    let error = {};
    try {
      error = await response.json();
      throw new Error(error.message || 'Terjadi kesalahan pada server');
    } catch (e) {
        console.error(`API Error on ${url}:`, error);
        alert(`Gagal berkomunikasi dengan server: ${error.message}`);
        throw e; // Lemparkan lagi agar bisa ditangkap oleh pemanggil
    }
  }

  return response.json();
}
