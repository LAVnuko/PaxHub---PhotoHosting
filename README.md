PixHub CMS
PHP CMS project archive prepared for publication on GitHub. The repository contains the PixHub image-hosting application in the repository root and an additional/older Minecraft server monitoring application in [`paxhub.ru/`](paxhub.ru/).
Important: this is a source-code handoff, not a guarantee that the applications are production-ready. Review configuration, database schema, authentication, file uploads, and deployment settings before putting either application online.

Contents
- index.php, auth.php, profile.php, gallery.php, album.php, show.php, etc. — main PixHub application entry points.
- library/ — shared application logic, database connection, language files, logging and sitemap helpers.
- includes/, src/ — configuration, template handling and application services/entities.
- template/, templates/ — page templates and styles.
- admin/ — administration interface.
- oauth/ — OAuth provider handlers.
- cron/ — scheduled jobs.
- paxhub.ru/ — separate/legacy Minecraft monitoring application, with its own Composer configuration and environment template.
- composer.json, composer.lock — dependency definitions. Third-party dependencies are intentionally not committed; Composer installs them.
Requirements
- PHP version compatible with the selected application and its composer.json.
- Composer 2.x.
- MySQL/MariaDB with a database schema matching the application.
- PHP extensions required by the installed packages and image handling features (commonly PDO MySQL, GD or Imagick, fileinfo, mbstring, OpenSSL, and cURL; confirm against your hosting environment).
- Apache with mod_rewrite for the included .htaccess rules, or equivalent rewrite rules for your web server.
- Redis is used by the nested Minecraft monitoring app if enabled/configured.
Setup: PixHub at repository root
1. Clone the repository and enter its directory.
2. Install PHP dependencies:
   composer install --no-dev --prefer-dist --optimize-autoloader
3. Create your local configuration file:
   cp .env.example .env
   On Windows, copy .env.example to .env manually. Set database credentials, generate a strong API_KEY_SECRET, and configure OAuth/reCAPTCHA only if those features are used.
4. Create an empty MySQL/MariaDB database and import your own trusted schema. The original archive did not provide a usable schema for a clean installation; its database backup was excluded because database dumps can contain private user data and credentials. Do not import an untrusted production dump into a public or development environment.
5. Configure the web server document root to this directory. Ensure uploads/, cache/, and the relevant runtime directories are writable by the PHP process, while keeping .env, logs and private files inaccessible from HTTP.
6. In production, keep APP_DEBUG=false, use HTTPS, use a database user with only the permissions required by the application, and remove any temporary diagnostic scripts before deployment.
Setup: nested paxhub.ru/ application
This folder is a separate/legacy application and has its own composer.json and .env.example. If you intend to run it, enter that directory and install its dependencies separately:
cd paxhub.ru
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
Fill in its database, Redis and OAuth settings. Point the web server document root at paxhub.ru/ only if that is the application you intend to deploy. Its database schema and migration/install procedure must be reviewed separately; they are not supplied as a clean-install database in this repository.
Scheduled jobs
Review cron/check_servers.php and the scripts under paxhub.ru/cron/ before adding them to the system crontab. Configure the correct PHP binary, absolute paths, environment variables, and run frequency for your host. Do not run a script until its database and service configuration has been checked.
GitHub publication checklist
- [ ] Copy .env.example to .env locally and never commit .env.
- [ ] Replace all example passwords and secrets with unique values in your deployment environment.
- [ ] Verify no production database dumps, logs, user uploads, or personal data are staged.
- [ ] Review OAuth callback URLs, domain names and sitemap configuration for your deployment.
- [ ] Review the code and licenses of third-party assets/dependencies. The MIT license in this repository applies to the project code you have the rights to license; Composer dependencies and other third-party assets remain under their own licenses.
- [ ] Run PHP syntax checks and application tests in your own environment.
License
The project is distributed under the MIT License; see LICENSE. This does not override licenses or terms applying to third-party dependencies, fonts, images, or other assets.
