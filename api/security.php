<?php
require_once __DIR__ . '/attributes.php';

#[SecurityScheme('BearerAuth', 'http', 'bearer', 'JWT')]
function __security_bearer() {}
