<?php

namespace Base\Office\Exception;

/** A deposit refused: too large, a type not accepted, an empty file (share.error.<key>). */
class ShareException extends \RuntimeException
{
    public function __construct(string $key, private readonly array $parameters = [])
    {
        parent::__construct($key);
    }

    public function getKey(): string
    {
        return $this->getMessage();
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}
