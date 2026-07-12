<?php

declare(strict_types=1);

/**
 * McpToolGuard — runtime availability check for the php-mcp/server SDK.
 *
 * The PrestaShop MCP Server add-on (marketplace ID 96617) ships the
 * `PhpMcp\Server\Attributes\McpTool` attribute class.  When that add-on is
 * not installed the attribute class is absent and tool registration must be
 * skipped to avoid fatal errors.
 *
 * Usage in module bootstrap:
 *
 *   if (McpToolGuard::isAvailable()) {
 *       // register AgenticTools with the MCP server
 *   }
 */

namespace Trusteed\AgenticTools;

final class McpToolGuard
{
    /**
     * Returns true when the php-mcp/server SDK attribute class is loadable,
     * i.e. the MCP Server add-on (96617) is installed and active.
     */
    public static function isAvailable(): bool
    {
        return class_exists('PhpMcp\\Server\\Attributes\\McpTool');
    }
}
