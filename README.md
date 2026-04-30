# Smart Report — GLPI Plugin

> Automated report scheduling, CSV generation, queue management, and email delivery for GLPI 10 & 11.

[![GLPI](https://img.shields.io/badge/GLPI-10.0.x%20%7C%2011.x-blue)](https://glpi-project.org/)
[![License](https://img.shields.io/badge/License-GPL%20v3-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0--beta-orange)](setup.php)

---

## Table of Contents

1. [Introduction](#introduction)
2. [Features](#features)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Usage](#usage)
7. [Screenshots](#screenshots)
8. [Technical Details](#technical-details)
9. [Known Limitations](#known-limitations)
10. [Contributing](#contributing)
11. [License](#license)

---

## Introduction

**Smart Report** is a GLPI plugin that automates the generation, scheduling, and delivery of reports built from GLPI Saved Searches. It bridges the gap between GLPI's built-in search engine and recurring, unattended report distribution — so teams receive accurate, timely CSV data exports without manual intervention.

### Use Cases

- **Scheduled reporting** — automatically generate and email reports on a daily or monthly cadence without anyone needing to log in
- **Large dataset exports** — stream and paginate result sets of 50,000+ rows without exhausting PHP memory
- **Stakeholder delivery** — send CSV attachments (or download links for large files) directly to users or groups
- **Audit trails** — track how many times each generated file has been downloaded and enforce file retention policies

---

## Features

### Report Execution

| Capability | Detail |
|---|---|
| **Manual execution** | Execute any report on demand via the Execute button in the report form |
| **Scheduled execution** | Cron-driven scheduler enqueues due reports every 5 minutes |
| **Parallel workers** | Up to 3 independent worker slots can run simultaneously (configurable) |
| **Stuck job recovery** | Jobs running longer than 30 minutes are automatically reset and retried |
| **Retry on failure** | Each job is retried up to 3 times before being marked as failed |

### CSV Generation

| Capability | Detail |
|---|---|
| **Saved Search source** | Reports are backed by any GLPI Saved Search — filters, columns, and sort are all inherited |
| **Streaming / batching** | Results are fetched 500 rows at a time and written directly to disk — memory usage is O(page) not O(total) |
| **UTF-8 BOM** | Output files include a BOM header so Excel on Windows auto-detects the encoding correctly |
| **Stable pagination** | Pages are sorted by `id ASC` — a guaranteed unique key — preventing duplicates or skipped rows at page boundaries |

### Email Delivery

| Capability | Detail |
|---|---|
| **Per-report recipients** | Assign individual users and/or groups to each report |
| **Privacy-aware sending** | First recipient uses `To:`, all others use `BCC:` — addresses are not exposed to each other |
| **Attachment vs. link** | Files ≤ size limit are attached; larger files send a secure download link instead |
| **Configurable sender** | "From" address defaults to GLPI's notification email and can be overridden per-installation |

### Queue Management

| Capability | Detail |
|---|---|
| **Live queue view** | GLPI-standard list view under **Plugins → Smart Report → Queue** shows all pending and running jobs |
| **Execution order** | Jobs are displayed in the order they will be executed (arrival order, running jobs first) |
| **Status filtering** | Filter by Pending, Running, Done, or Failed using GLPI's standard search criteria bar |
| **No completed clutter** | Done and Failed jobs are purged from the queue table after 7 days; only active jobs appear by default |

### File & Retention Management

| Uniqueness Mode | Behaviour |
|---|---|
| **Daily** | One file per report per calendar day — same-day re-runs overwrite the file; download count is preserved |
| **Monthly** | One file per report per calendar month — same-month re-runs overwrite |
| **Duplicate** | Every execution creates a new timestamped file — nothing is overwritten |

| Capability | Detail |
|---|---|
| **Retention period** | Each report has a configurable retention period in days; expired files are deleted automatically |
| **Download tracking** | `download_count` increments each time a file is downloaded via the plugin's download endpoint |
| **Keep-forever option** | Set retention to `0` to keep files indefinitely |

### Compatibility

- **GLPI 10.0.x** — fully supported
- **GLPI 11.x** — fully supported (version-adaptive API calls via `Glpiversion` compatibility layer)

---

## Requirements

| Requirement | Minimum Version |
|---|---|
| GLPI | 10.0.0 |
| PHP | 8.1 |
| MySQL / MariaDB | 5.7 / 10.3 |
| Composer | 2.x |

---

## Installation

### From a Release Archive

1. Download the latest release ZIP from the [Releases](../../releases) page.
2. Extract the archive into your GLPI plugins directory:
   ```
   /var/www/html/glpi/plugins/smartreport/
   ```
3. Run Composer to generate the autoloader:
   ```bash
   cd /var/www/html/glpi/plugins/smartreport
   composer install --no-dev --optimize-autoloader
   ```
4. In GLPI, navigate to **Setup → Plugins** and click **Install** next to *Smart Report*, then **Enable**.

### From Source (Development)

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/your-org/smartreport.git smartreport
cd smartreport
composer install
```

Then install and enable through **Setup → Plugins** as above.

> **Important:** The plugin directory must be named exactly `smartreport` — GLPI derives the plugin key from the directory name.

---

## Configuration

Navigate to **Setup → General** and open the **Smart Report** tab.

![Configuration Tab](docs/screenshots/config-tab.png)

### Settings

| Field | Description | Default |
|---|---|---|
| **From Email Address** | The `From:` address used for all outgoing report emails. If left blank, falls back to GLPI's configured notification sender address. | *(GLPI notification email)* |
| **File Size Limit (MB)** | Maximum CSV file size to attach directly to the email. Files exceeding this limit are not attached — recipients receive a secure download link instead. Set to `0` to always attach regardless of size. | `5 MB` |

> **Note:** The *From Email Address* field is pre-populated with GLPI's current notification email when no custom value has been saved. Once you save a value (even the same one), that value becomes the stored address and is no longer automatically updated when GLPI's notification email changes.

### Automatic Actions (Cron)

Two cron tasks are registered automatically on plugin installation. Configure their frequency under **Setup → Automatic Actions**:

| Task Name | Default Frequency | Purpose |
|---|---|---|
| `scheduleReports` | Every 5 minutes | Enqueues reports that are due; resets stuck jobs; purges old queue rows |
| `workerSlot1` | Every 1 minute | Claims and executes one pending report from the queue |

Both tasks operate in **External** mode — they are triggered by the system cron calling `/var/www/html/glpi/front/cron.php`, not by GLPI's internal PHP cron runner.

#### Recommended System Crontab

```cron
* * * * * www-data /usr/bin/php /var/www/html/glpi/front/cron.php &>/dev/null
```

---

## Usage

### Creating a Report

1. Ensure a **Saved Search** exists for the data you want to export (create one from any GLPI list view by clicking the bookmark icon).
2. Navigate to **Plugins → Smart Report**.
3. Click **Add** to create a new report definition.
4. Fill in the form:

| Field | Description |
|---|---|
| **Name** | Display name for the report |
| **Saved Search** | The GLPI Saved Search that defines the dataset and columns |
| **Frequency** | How often the report should be generated (daily, weekly, etc.) |
| **Uniqueness** | File overwrite behaviour — Daily, Monthly, or Duplicate (see table above) |
| **Retention Period** | Number of days to keep generated files (`0` = keep forever) |
| **Recipients** | Users and/or groups to email the report to |
| **Send Email** | Toggle email delivery on or off |
| **Status** | Enable or disable automatic scheduling for this report |

5. Click **Save**.

![Report Creation Form](docs/screenshots/report-form.png)

### Executing a Report Manually

Open any report definition and click the **Execute** button. The report is generated immediately in the same request — the queue is not used for manual execution.

### Automatic Execution (Cron)

When the `scheduleReports` cron task fires:

1. Any report with status **Scheduled** and no active queue entry is examined against its configured frequency and last-run date.
2. Due reports are inserted into the execution queue as **Pending** jobs.
3. When `workerSlot1` fires, it atomically claims one Pending job (MySQL-level row lock prevents double-claiming) and executes it:
   - Builds the CSV by paginating through `Search::getDatas()` in batches of 500 rows
   - Saves the file to the GLPI document directory
   - Sends the email to all configured recipients
   - Updates the job status to **Done**
4. If the worker fails or is killed, the `scheduleReports` task detects the stuck job after 30 minutes and resets it for retry (up to 3 attempts).

### Monitoring the Queue

Navigate to **Plugins → Smart Report → Queue** to see the live execution queue.

![Queue List View](docs/screenshots/queue-listview.png)

The list shows only **Pending** and **Running** jobs. Use GLPI's standard criteria bar to filter by status, report name, worker, or queue date. Completed jobs are not shown here — they are purged automatically after 7 days.

### Downloading a Generated Report

Generated files are listed in the **Generated Reports** tab of each report definition. Click the **Download** link to retrieve the CSV. Each download increments the file's `download_count`.

---

## Screenshots

| Screen | Preview |
|---|---|
| Report creation form | ![Report Form](docs/screenshots/report-form.png) |
| Configuration tab (Setup → General) | ![Config Tab](docs/screenshots/config-tab.png) |
| Queue list view | ![Queue](docs/screenshots/queue-listview.png) |
| Profile rights tab | ![Profile Rights](docs/screenshots/profile-rights.png) |
| Generated report email (with attachment) | ![Email Attachment](docs/screenshots/email-attachment.png) |
| Generated report email (download link) | ![Email Link](docs/screenshots/email-link.png) |

> Screenshots go in `docs/screenshots/`. Add them by committing PNG files at the paths above.

---

## Technical Details

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     GLPI Cron Runner                    │
│              (front/cron.php every minute)              │
└───────────────────┬─────────────────┬───────────────────┘
                    │                 │
         ┌──────────▼──────┐   ┌──────▼────────────┐
         │ scheduleReports │   │   workerSlot1/2/3  │
         │  (every 5 min)  │   │   (every 1 min)    │
         └──────────┬──────┘   └──────┬─────────────┘
                    │                 │
         Enqueue    │    Claim + Run  │
         due jobs   │    one job      │
                    │                 │
         ┌──────────▼─────────────────▼─────────────┐
         │         glpi_plugin_smartreport_queue     │
         │   status: pending → running → done/failed │
         └──────────────────────┬────────────────────┘
                                │
                   ┌────────────▼────────────┐
                   │      streamCSV()        │
                   │  Search::getDatas()     │
                   │  500 rows per page      │
                   │  sorted by id ASC       │
                   └────────────┬────────────┘
                                │
                   ┌────────────▼────────────┐
                   │  glpi_plugin_smartreport│
                   │  _generatedreports      │
                   │  (file path, metadata,  │
                   │   download_count)       │
                   └────────────┬────────────┘
                                │
                   ┌────────────▼────────────┐
                   │     sendReportByEmail() │
                   │  Attach ≤ limit or link │
                   │  To + BCC recipients    │
                   └─────────────────────────┘
```

### Large Dataset Handling

The report executor uses streaming pagination to handle result sets of any size without exhausting PHP memory:

- `Search::getDatas()` is called once per 500-row page with `start` offset incremented each iteration
- Each page is written to the CSV file handle immediately and then discarded from memory
- Sorting is always performed on the `id` field (search option `1`) — the only universally unique key across GLPI itemtypes — ensuring stable, overlap-free pagination regardless of dataset size or timestamp collisions
- The total row count is captured from the first page's `totalcount` and used as a termination bound alongside an empty-page guard

### Session Isolation for Cron

Report execution in cron context requires a synthetic GLPI session. The plugin:

1. Finds a Super-Admin user from the database
2. Calls `Session::initEntityProfiles()`, `Session::changeProfile()`, and `Session::changeActiveEntities('all', true)` to mirror what a superadmin sees in the UI
3. Sets `$_SESSION['glpilist_limit']` to the page size after `changeActiveEntities` (which resets it) so GLPI's Search engine honours the batch size
4. Preserves the original session in a variable and restores it in a `finally` block after execution

### Cron Parallelism and Atomicity

Worker slots use a single `UPDATE … WHERE status='pending' ORDER BY date_creation ASC LIMIT 1` to claim jobs. MySQL's row-level lock ensures that even if two worker processes fire simultaneously, only one UPDATE wins per row — the losing worker sees `affected_rows = 0` and exits cleanly without executing any report. This provides safe parallel execution without a separate lock table.

---

## Known Limitations

| Limitation | Notes |
|---|---|
| **CSV format only** | Reports are exported as CSV. Excel (XLSX), PDF, and other formats are not currently supported. |
| **One active worker slot** | Only `workerSlot1` is enabled by default. Slots 2 and 3 can be uncommented in `setup.php` if your GLPI installation supports parallel cron invocations. |
| **Saved Search dependency** | Each report requires an existing GLPI Saved Search. The plugin does not provide its own query builder. |
| **Email delivery** | Email is sent via GLPI's configured SMTP transport. If GLPI's email is not configured, report emails will not be sent. |
| **No browser push** | The queue monitor is a snapshot — refresh the page manually to see updated status. Real-time push is not implemented. |
| **Single tenant** | The plugin does not currently support per-entity report isolation. All reports run in the context of the configured Super-Admin user with all entities active. |

---

## Contributing

- Open a ticket for each bug or feature request so it can be discussed before work begins
- Follow the [GLPI plugin development guidelines](http://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
- Follow the [GitFlow](http://git-flow.readthedocs.io/) branching model (`feature/`, `bugfix/`, `release/`)
- Work on a new branch in your own fork
- Open a Pull Request — all PRs require review by a maintainer before merge
- PSR-12 coding standard is enforced; run `composer cs-check` before submitting

---

## License

This plugin is distributed under the terms of the **GNU General Public License v3.0 or later**.
See [LICENSE](LICENSE) for the full text.

---

*Smart Report is not an official GLPI product and is not affiliated with Teclib'.*
