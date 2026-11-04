<?php
#[Attribute(Attribute::TARGET_FUNCTION)]
class Get {
    public function __construct(public string $path) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Post {
    public function __construct(public string $path) {}
}
