# Dead Link Checker

A YOURLS plugin that automatically detects broken long URLs behind short links \(those returning 4xx/5xx status codes or connection failures\)\. It displays all dead links in a visual backend table and supports one\-click deletion\.

---

## Features

- **Batch HEAD Probing**: Sends HEAD requests via `get_headers()`, follows chains of 301/302 redirects, and retrieves the final HTTP status code

- **Intelligent Broken Link Judgement**: Links returning 2xx and 3xx status codes are marked valid; 4xx, 5xx, connection timeouts and failed connections are marked broken

- **Batch AJAX Scanning**: Prevents PHP execution timeouts caused by scanning massive link volumes at once; customizable batch size

- **Real\-time Progress Bar**: Frontend visual progress indicator with percentage and processed/total count

- **One\-Click Deletion**: Individual delete buttons next to every dead link entry with instant effect

- **Flexible SSL Control**: Toggle SSL certificate validation to fit public domains, private OSS and intranet environments

---

## Installation Guide

1. Create a plugin folder in your YOURLS root directory:

    ```Plain Text
    user/plugins/link-checker/
    ```

2. Place `plugin.php` inside the folder:

    ```Plain Text
    user/plugins/link-checker/plugin.php
    ```

3. Log into the YOURLS backend → **Manage Plugins** → Locate **Dead Link Checker** → Click **Activate**

---

## User Guide

### Backend Configuration

After activation, a subpage named **Dead Link Check** will appear under the **Plugins** menu on the left sidebar of the admin panel\.

#### Scan Timeout \(Seconds\)

Maximum waiting time for a single link\. Links hitting this timeout will be marked broken\. Recommended value: **5–15 seconds**\.

#### Batch Size

Number of links scanned per AJAX request\. Too large a value may trigger PHP execution timeouts\. Recommended value: **30–100**\.

#### SSL Certificate Validation

- **Enabled**: Validates SSL certificates of remote servers \(recommended for public domains\)

- **Disabled**: Allows self\-signed certificates \(recommended for private OSS and intranet domains\)

### Run Full Scan

1. Click the button labelled **Start Scanning All Links**

2. The progress bar displays real\-time scan progress

3. Once scanning finishes, all dead links will be listed in a table

4. A **Delete** button sits beside each dead link for instant removal

---

## Notes

- **HEAD Requests**: The scanner uses HEAD instead of GET requests to avoid downloading response bodies and save bandwidth

- **Redirect Following**: `max_redirects` is set to 5, sufficient for most CDN and OSS origin pull scenarios

- **PHP Version Requirement**: The third parameter of `get_headers()` requires PHP 7\.1 or higher; verify your server environment meets this requirement

- **Timeout Prevention**: Do not close the page during scanning; batched AJAX requests will automatically complete the full scan workflow

---

## Compatibility

- YOURLS 1\.7 and above

- PHP 7\.1 and above \(requires the third argument of `get_headers()`\)

---

## Author

ai

