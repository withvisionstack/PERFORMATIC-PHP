<?php

#[SecurityScheme('BearerAuth', 'http', 'bearer', 'JWT')]
function __security_bearer() {}
