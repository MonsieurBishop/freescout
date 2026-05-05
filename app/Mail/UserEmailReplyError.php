<?php
/**
 * User replied from wrong email address to the email notification.
 */

namespace App\Mail;

use Illuminate\Mail\Mailable;

class UserEmailReplyError extends Mailable
{
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        \MailHelper::prepareMailable($this);

        // ADVALLY 2026-05-05: mark as auto-replied per RFC 3834 so receiving
        // FreeScout mailboxes can detect this is an auto-response and skip
        // it via MailHelper::isAutoResponder. Prevents staff-mailbox loops.
        $this->withSwiftMessage(function ($swiftmessage) {
            $headers = $swiftmessage->getHeaders();
            $headers->addTextHeader("Auto-Submitted", "auto-replied");
            $headers->addTextHeader("X-Auto-Response-Suppress", "All");
            $headers->addTextHeader("Precedence", "auto_reply");
        });

        return $this->subject(__("Unable to process your update"))
            ->view("emails/user/email_reply_error");
    }
}
