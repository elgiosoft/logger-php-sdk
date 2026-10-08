<?php

declare(strict_types=1);

namespace Elgiosoft\Logger;

/**
 * Data attached to every log captured while the scope is active (user, tags, named contexts).
 */
final class Scope
{
    /**
     * @var array{id?: string, email?: string, username?: string, ip_address?: string}|null
     */
    public ?array $user = null;

    /**
     * @var array<string, string>
     */
    public array $tags = [];

    /**
     * @var array<string, array<array-key, mixed>>
     */
    public array $contexts = [];
}
