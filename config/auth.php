<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        /*
        | THE FLOOR, IN MILLISECONDS, THAT `PasswordResetService` PADS BOTH OF ITS ENTRY
        | POINTS UP TO. Not a broker option — the key sits here because this is where
        | everything about password resets is configured, and `Password::broker()` only ever
        | reads the named sub-arrays beside it.
        |
        | WHY IT IS NOT THE FRAMEWORK'S 200 ms. The broker wraps its own work in a `Timebox`,
        | but a timebox is a FLOOR, not an equaliser: it pads a fast branch up to the
        | duration and lets a slow one run long. The branch that FINDS an account does work
        | the branch that finds nothing does not — bcrypt-hashing the new token (measured at
        | 213-230 ms on this codebase with BCRYPT_ROUNDS=12), rendering the Blade mail, and
        | on production handing it to SMTP. All of that is already over 200 ms, so the stock
        | floor equalised nothing and the response time itself answered the question the
        | endpoint refuses to answer in words: does this address have an account?
        |
        | THE FLOOR MUST EXCEED THE COST OF THE BRANCH THAT HITS. 1000 ms buys room for
        | bcrypt + render + a local relay. IF YOU CHANGE `BCRYPT_ROUNDS`, OR POINT MAIL AT A
        | REMOTE SMTP SERVER, MEASURE AGAIN: a hit that outruns the floor puts the oracle
        | straight back. A slow-but-honest alternative for a remote relay is to queue the
        | mail, which takes the transport out of the measured branch entirely.
        |
        | Zero disables the padding (and with it the defence). Tests set it explicitly rather
        | than inheriting it, and fake the clock — see PasswordResetTest.
        */
        'timebox_ms' => (int) env('AUTH_PASSWORD_TIMEBOX_MS', 1000),

        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
