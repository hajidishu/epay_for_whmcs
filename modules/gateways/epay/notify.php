<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/recovery.php';

epay_secure_serve_callback(true);
