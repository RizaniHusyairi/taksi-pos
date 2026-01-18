<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Kolom foreign key ke tabel 'bookings'
            // Ini cara modern dan aman untuk membuat relasi di Laravel.
<<<<<<< HEAD
                $table->foreignId('booking_id')
                    ->constrained()
                    ->cascadeOnDelete()
                    ->unique();

=======
            $table->foreignId('booking_id')
                  ->constrained('bookings')
                  ->onDelete('cascade'); // Opsi: jika booking dihapus, transaksi ini juga ikut terhapus.
            
                  
>>>>>>> 02fb6853decde7e985c741a4668e771b992f392e
            // Kolom untuk metode pembayaran dengan nilai yang sudah ditentukan.
            $table->enum('method', ['QRIS', 'CashCSO', 'CashDriver']);

            // Kolom untuk jumlah uang. (total 10 digit, 2 digit di belakang koma)
            // Sangat direkomendasikan untuk nilai moneter.
            $table->decimal('amount', 10, 2);
            $table->string('payment_proof')->nullable();
            $table->enum('payout_status',['Unpaid','Processing','Paid'])->default('Unpaid');

            // Kolom created_at dan updated_at (TIMESTAMP)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
