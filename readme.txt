primary color: #0D1A63
secondary color: #9CCFFF
tertiary color: #685AFF
fourth color: #FFADAD

# Vuqa — Program Monitoring in One Place

> **All your programme's digital needs addressed.**
> Vuqa is a web-based M&E (Monitoring & Evaluation) platform for NGOs, government agencies, and development organisations to collect data, track outcomes, and generate AI-powered reports — 100% paperlessly.

---

## ? Features

| Feature | Description |
|---|---|
| ?? **Paperless Data Collection** | Replace paper forms with digital field data capture. Offline-capable, syncs on reconnect. |
| ?? **Real-time Dashboards** | Live charts and indicator tracking across all sites, teams, and programmes. |
| ?? **AI Workplan Generation** | Describe programme goals — Vuqa's AI drafts a structured, timeline-ready workplan instantly. |
| ?? **Bank-grade Security** | AES-256 encryption at rest and in transit. Role-based access controls. |
| ?? **Automated Reporting** | Generate donor-ready reports (USAID, EU, UN formats) in one click. Schedule recurring delivery. |
| ??? **Multi-programme Management** | Run multiple programmes, cohorts, and geographies from one account. |

---

## ?? Who Is Vuqa For?

- **NGOs & INGOs** — Manage grants, log activities, and produce donor reports without a consultant.
- **Government Agencies** — Centralise data from national down to ward level with full audit trails.
- **Health Programmes** — Track patient cohorts, CHW performance, and commodity supply chains.
- **Education Programmes** — Monitor enrolment, attendance, assessments, and scholarships.
- **Livelihoods Programmes** — Track VSLA groups, business grants, income verification, and market linkages.

---

## ??? Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL / MariaDB |
| Frontend | Bootstrap 5.3, Vanilla JS |
| Icons | Font Awesome 6 |
| Fonts | Google Fonts (Syne + DM Sans) |
| AI Layer | OpenAI API / Anthropic Claude API |
| Auth | PHP Sessions + bcrypt password hashing |

---

## ?? Getting Started

### Prerequisites

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.4+
- Apache or Nginx web server
- Composer (for dependency management)

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/your-org/vuqa.git
cd vuqa

# 2. Install PHP dependencies (if applicable)
composer install

# 3. Copy and configure environment
cp includes/config.example.php includes/config.php
```

Edit `includes/config.php` with your database credentials and API keys:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'vuqa');

define('AI_API_KEY', 'your_ai_api_key');
define('APP_URL',    'https://yourdomain.com');

session_start();
```

```bash
# 4. Import the database schema
mysql -u your_db_user -p vuqa < database/schema.sql

# 5. (Optional) Seed demo data
mysql -u your_db_user -p vuqa < database/seed.sql

# 6. Set directory permissions
chmod 755 uploads/
chmod 755 exports/
```

### Apache Virtual Host (example)

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/vuqa/public

    <Directory /var/www/vuqa/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/vuqa_error.log
    CustomLog ${APACHE_LOG_DIR}/vuqa_access.log combined
</VirtualHost>
```

Enable `mod_rewrite` and restart Apache:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## ?? Project Structure

```
vuqa/
+-- includes/
¦   +-- config.php          # DB credentials, session start, constants
¦   +-- layout.php          # Shared header/nav/footer wrapper
¦   +-- functions.php       # Utility functions
+-- public/
¦   +-- index.php           # Entry point (redirects to welcome or dashboard)
¦   +-- welcome.php         # Public landing page
¦   +-- login.php
¦   +-- register.php
¦   +-- dashboard.php
+-- modules/
¦   +-- programmes/         # Programme CRUD
¦   +-- data-collection/    # Form builder & submissions
¦   +-- reports/            # Report generation engine
¦   +-- workplans/          # AI workplan module
¦   +-- users/              # User & role management
+-- database/
¦   +-- schema.sql
¦   +-- seed.sql
+-- assets/
¦   +-- css/
¦   +-- js/
¦   +-- uploads/
+-- README.md
```

---

## ?? Security

- All passwords hashed with `password_hash()` (bcrypt, cost 12)
- Prepared statements used throughout (PDO) — no raw SQL concatenation
- CSRF tokens on all state-changing forms
- `htmlspecialchars()` applied on all output
- Session-based auth with regeneration on login
- HTTPS enforced in production config

**Please report vulnerabilities privately to:** `security@vuqa.io`

---

## ?? Deployment

For production deployments:

1. Set `APP_ENV=production` in your config.
2. Disable PHP error display (`display_errors = Off`).
3. Enable HTTPS and set an HSTS header.
4. Configure a cron job for scheduled report emails:

```bash
# Run every day at 06:00
0 6 * * * php /var/www/vuqa/cron/send_reports.php >> /var/log/vuqa_cron.log 2>&1
```

---

## ?? Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "feat: add your feature"`
4. Push the branch: `git push origin feature/your-feature-name`
5. Open a Pull Request

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting.

### Commit Convention

We follow [Conventional Commits](https://www.conventionalcommits.org/):

| Prefix | Use for |
|---|---|
| `feat:` | New features |
| `fix:` | Bug fixes |
| `docs:` | Documentation changes |
| `style:` | Formatting, no logic change |
| `refactor:` | Code restructuring |
| `chore:` | Tooling, deps, CI |

---

## ?? License

This project is licensed under the **MIT License** — see [LICENSE](LICENSE) for details.

---

## ?? Contact & Support

- **Website:** [vuqa.io](https://vuqa.io)
- **Support:** support@vuqa.io
- **Issues:** [GitHub Issues](https://github.com/your-org/vuqa/issues)

---

<p align="center">Made with ?? for programme teams across East Africa and beyond.</p>

