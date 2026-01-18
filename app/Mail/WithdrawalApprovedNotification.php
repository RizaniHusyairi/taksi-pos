<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Withdrawals;

class WithdrawalApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $withdrawal;

    public function __construct(Withdrawals $withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    public function build()
    {
        return $this->subject('Pencairan Dana Berhasil - Taksi POS')
                    ->view('emails.withdrawal_approved');
    }
}