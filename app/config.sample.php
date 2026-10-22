<?php
/**
 * Copy this file to app/config.php and fill in your own values.
 * app/config.php holds secrets: never commit it or share it.
 */
return [
    'base_url'             => 'https://tickets.yourwebsite.com', // no trailing slash
    'main_site_url'        => 'https://yourwebsite.com',         // "Back to website" link
    'organiser_name'       => 'Your Brand Name',
    'timezone'             => 'Asia/Kolkata',
    'default_country_code' => '91',

    'db' => [
        // 'mysql' on Hostinger. 'sqlite' is for local development only.
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'u123_tickets',
        'user'     => 'u123_tickets',
        'password' => '',
        'sqlite_path' => __DIR__ . '/data/dev.sqlite',
    ],

    'razorpay' => [
        'key_id'         => 'rzp_test_XXXXXXXX', // rzp_live_... after KYC
        'key_secret'     => '',
        'webhook_secret' => '',                  // different in test and live mode
    ],

    'mail' => [
        'enabled'    => false, // set true once SMTP details below are filled in
        'host'       => 'smtp-relay.brevo.com',
        'port'       => 587,
        'encryption' => 'tls', // tls | ssl
        'username'   => '',
        'password'   => '',
        'from_email' => 'tickets@yourwebsite.com',
        'from_name'  => 'Your Brand Name',
        'reply_to'   => 'support@yourwebsite.com',
    ],

    'whatsapp' => [
        'provider'      => 'none',         // none | meta | interakt | aisensy
        'template_name' => 'event_ticket', // AiSensy: the API campaign name
        'language'      => 'en',
        'meta_api_version'      => 'v21.0',
        'meta_phone_number_id'  => '',
        'meta_access_token'     => '',     // permanent System User token
        'interakt_api_key'      => '',     // base64 key from Interakt developer settings
        'aisensy_api_key'       => '',
    ],

    // Seats are held for unpaid (pending) bookings for this many minutes.
    'hold_minutes' => 15,

    'debug' => false, // true shows errors on screen; must be false in production
];
