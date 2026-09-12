<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are the default lines which match reasons
    | that are given by the password broker for a password update attempt
    | outcome such as failure due to an invalid password / reset token.
    |
    */

    'reset' => 'Your password has been reset.',
    'sent' => 'We have emailed your password reset link.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset token is invalid.',
    'user' => "We can't find a user with that email address.",

    /*
    |--------------------------------------------------------------------------
    | The answer the forgot-password endpoint actually gives
    |--------------------------------------------------------------------------
    |
    | `sent` and `user` above are the broker's own two outcomes and they are exactly
    | what we must NOT say out loud: telling the two apart turns the form into a
    | membership check on our user table. This one line is returned for every address,
    | known or not, sent or throttled — see PasswordResetService.
    |
    | It is phrased conditionally ("if an account exists") rather than asserting a
    | send, because for an unknown address nothing was sent and the sentence still
    | has to be true.
    |
    */

    'requested' => 'If an account exists for that address, we have sent it a password reset link.',

    /*
    |--------------------------------------------------------------------------
    | The reset mail
    |--------------------------------------------------------------------------
    |
    | Copy for App\Modules\Auth\Mail\PasswordResetMail. It lives here, beside the
    | broker results, so the whole flow's prose is in one file per language; the
    | invitation mail (hardcoded English) is the older convention this improves on.
    |
    */

    'mail' => [
        'subject' => 'Reset your Taskio password',
        'greeting' => 'Hi :name,',
        'intro' => 'We received a request to set a new password for your Taskio account.',
        'action' => 'Set a new password',
        'fallback' => 'Or paste this link into your browser:',
        'expires' => 'This link expires in :minutes minutes and can be used once.',
        'ignore' => 'If you did not request it, you can ignore this email — your password will not change.',
    ],

];
