# VpnHood WHMCS Integration Package

This package includes:
- `vpnhoodstore`: WHMCS provisioning module for VpnHood
- `vpnhoodconfig`: Addon module for global API settings

## Installation

1. Extract the ZIP file.
2. Copy the `modules/servers/vpnhoodstore/` folder to your WHMCS `/modules/servers/` directory.
3. Copy the `modules/addons/vpnhoodconfig/` folder to your WHMCS `/modules/addons/` directory.

## Configuration

1. In WHMCS Admin, go to **System Settings > Addon Modules**.
2. Activate `VpnHood Configuration`.
3. Click **Configure** and enter:
   - API Key
   - Endpoint URL (e.g. `https://api.vpnhood.com`)
   - App ID

## Product Setup

1. Go to **System Settings > Products/Services**.
2. Create or edit a product.
3. In the **Module Settings** tab:
   - Set Module Name to `vpnhoodstore`.

## Usage

- When a user purchases the product, their info is sent to VpnHood API.
- In the client area, a button appears to fetch their premium code.