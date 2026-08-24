<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;

class NewOrderForDriver extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $booking;

    /**
     * Create a new message instance.
     *
     * Sengaja TIDAK menerima URL struk lagi. `receipt_token` di dalam URL itu
     * adalah satu-satunya kunci form penilaian penumpang yang terbuka tanpa
     * login (PublicReceiptController::rate) — mengirimkannya ke supir membuat
     * supir bisa menilai perjalanannya sendiri. Struk adalah milik penumpang.
     *
     * @param Booking $booking Objek booking lengkap
     */
    public function __construct($booking)
    {
        $this->booking = $booking;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Order Baru Masuk! - Taksi POS')
                    ->view('emails.new_order_driver');
    }
}