# Agents

The framework's [AGENTS.md](https://github.com/crabphp/framework/blob/main/AGENTS.md) holds for this package: its rules for code, comments, tests and documentation all apply. What follows is particular to MCP.

## MCP

- The package is a server only, built against MCP revision `2026-07-28`. The specification at modelcontextprotocol.io is the authority; link the section a behaviour follows.
- Tools, resources and skills are listed explicitly on a server; nothing is discovered by scanning.
- Input schemas come from the framework's validator, the same as routes; do not write a second schema reader.
