<?php

return [
    // Cloudflare Turnstile (free CAPTCHA). Leave both empty to switch it off.
    'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
    'turnstile_secret' => env('TURNSTILE_SECRET_KEY'),

    // Throwaway email providers. The list is never complete, so add to it when you see abuse.
    'disposable_domains' => [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
        'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com',
        'tempinbox.com', 'spamgourmet.com', 'mytemp.email', 'tempail.com', 'burnermail.io',
    ],
];
