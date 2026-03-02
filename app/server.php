<?php

require_once APP_ROOT . '/app/bootstrap.php';
require_once APP_ROOT . '/app/lib/functions.php';
require_once APP_ROOT . '/app/http/router.php';

$state = load_state();
$p = default_prompts();

require APP_ROOT . '/app/views/main.php';
