<?php

#[Attribute(Attribute::TARGET_FUNCTION)]
class Get
{
    public function __construct(
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Post
{
    public function __construct(
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Param
{
    public function __construct(
        public string $name,
        public string $in = 'path',      // path | query | header
        public string $type = 'string',  // string | int | bool | float | datetime
        public bool $required = true,
        public ?string $description = null,
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Body
{
    public function __construct(
        public string $schema,
        public bool $required = true,
        public ?array $example = null,
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Response
{
    public function __construct(
        public int $status,
        public string $description,
        public ?string $schema = null,
        public ?array $example = null,
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Security
{
    public function __construct(public string $scheme) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Schema
{
    public function __construct(
        public string $name,
        public array $fields,
        public array $required = [],
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class SecurityScheme
{
    public function __construct(
        public string $name,
        public string $type,      // http | apiKey
        public string $scheme,    // bearer | basic
        public ?string $bearerFormat = null,
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Put
{
    public function __construct(
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Patch
{
    public function __construct(
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class Delete
{
    public function __construct(
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}