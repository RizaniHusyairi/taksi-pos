<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;

class NewOrderForDriver extends Mailable
{
    use Queueable, SerializesModels;

    public $booking;
    public $receiptUrl;

    /**
     * Create a new message instance.
     *
     * @param Booking $booking Objek booking lengkap
     * @param string $receiptUrl Link URL struk pembayaran
     */
    public function __construct($booking, $receiptUrl)
    {
        $this->booking = $booking;
        $this->receiptUrl = $receiptUrl;
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