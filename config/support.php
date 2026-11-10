<?php

return [
    /*
    | The one normal mailbox used for guest ticket replies. A random token is
    | carried in the subject, so Mailcow needs no catch-all or plus-addressing.
    */
    'inbox' => [
        'host' => env('SUPPORT_IMAP_HOST'),
        'port' => (int) env('SUPPORT_IMAP_PORT', 993),
        'encryption' => env('SUPPORT_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('SUPPORT_IMAP_USERNAME'),
        'password' => env('SUPPORT_IMAP_PASSWORD'),
        'mailbox' => env('SUPPORT_IMAP_MAILBOX', 'INBOX'),
        'address' => env('SUPPORT_INBOX_ADDRESS', env('MAIL_FROM_ADDRESS')),
    ],
];
