<?php

return [
    // KcolTech's version file for the in-app updater (https only). Empty = no
    // remote updates: releases go out with the deploy protocol. App\Support\UpdateSource.
    'update_url' => env('MURANO_UPDATE_URL', ''),
];
