# Architecture Changes Summary

## Overview

The site has been restructured to use a **split architecture** where data updates are performed by a UK-based PC and deployed to the web server via HTTPS. This replaces the previous VPN-based approach.

## Why This Change?

The gov.uk Fuel Finder API is restricted to UK IP addresses. When the web server is outside the UK (e.g., Germany), the previous solution required:
- NordVPN CLI on the server
- Complex VPN connection management
- Risk of SSH disconnections
- Web server hanging during VPN connection

**New solution:** Use a UK-based PC to download the data and deploy it to the web server.

## New Architecture

```
┌─────────────────┐         HTTPS          ┌──────────────────┐
│   UK Home PC    │  ───────────────────►  │  Web Server      │
│  (Debian + PHP) │   POST database file   │  (Any location)  │
└─────────────────┘                        └──────────────────┘
       │                                           │
       │ Fuel Finder API (UK IP)                   │ Serves website
       ▼                                           ▼
  Downloads data                              SQLite Database
  Builds SQLite DB                            (Atomic swap)
```
