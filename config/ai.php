<?php

return [
    /*
    | SSL verify for outbound AI APIs. true = system CAs, false = disable (local only),
    | or an absolute path to a cacert.pem. When unset, storage/app/certs/cacert.pem is used if present.
    */
    'ssl_verify' => env('AI_SSL_VERIFY', true),
];
