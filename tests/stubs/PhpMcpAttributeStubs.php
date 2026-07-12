<?php

declare(strict_types=1);

/**
 * PhpMcpAttributeStubs.php — minimal stand-ins for the php-mcp/server PHP 8
 * attributes used by the AgenticTool classes.
 *
 * The marketplace add-on (96617) ships the real `PhpMcp\Server\Attributes\McpTool`
 * and `Schema`. In CI / local dev the SDK is absent, so these stubs let the REAL
 * tool classes (which declare `#[McpTool(...)]` / `#[Schema(...)]`) be autoloaded
 * and reflected. They capture their arguments so reflection-based tests can assert
 * the canonical tool `name` and schema `properties`.
 *
 * These are #[Attribute] classes so `ReflectionMethod::getAttributes(McpTool::class)`
 * + `->newInstance()` behave exactly as they would with the real SDK.
 */

namespace PhpMcp\Server\Attributes {
    if (!class_exists(McpTool::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class McpTool
        {
            public function __construct(
                public readonly ?string $name = null,
                public readonly ?string $description = null,
            ) {}
        }
    }

    if (!class_exists(Schema::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class Schema
        {
            /**
             * @param array<string,mixed> $properties
             * @param list<string>        $required
             */
            public function __construct(
                public readonly array $properties = [],
                public readonly array $required = [],
            ) {}
        }
    }
}
