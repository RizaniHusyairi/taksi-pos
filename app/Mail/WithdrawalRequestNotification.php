<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Withdrawals;
use App\Models\User;

class WithdrawalRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $withdrawal;
    public $driver;

    /**
     * Create a new message instance.
     */
    public function __construct(Withdrawals $withdrawal, User $driver)
    {
        $this->withdrawal = $withdrawal;
        $this->driver = $driver;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Pengajuan Pencairan Dana Baru - Taksi POS')
                    ->view('emails.withdrawal_request');
    }
}