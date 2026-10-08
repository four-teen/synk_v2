SYNK workstation setup

The repository contains the application pages, database/authentication loaders, access controls, templates, tests, and reviewed schema migrations. Credentials and real application data stay outside Git.

To reproduce this installation on another workstation:

1. Clone the repository into your PHP web server's application directory, such as `C:/VertrigoServ/www/synk` or `C:/xampp/htdocs/synk`.
2. Use the PHP/MySQL setup and extensions required by your existing installation. The database connection uses `mysqli`; Google login also uses cURL and OpenSSL. Faculty Excel exports use ZipArchive, and profile image processing uses GD.
3. Export a fresh, complete database backup from the current working application and import it into the target MySQL/MariaDB server. The older `synk_db_100726.sql` does not include all of the current schema changes. Migration files alone do not contain the existing accounts, assignments, or imported students.
4. Manually copy `backend/db_config.local.php` from this workstation to the same relative location in the clone. Change `host`, `username`, `password`, and `database` only where the target database settings differ.
5. Manually copy `backend/auth_config.local.php` to the same relative location. This restores the existing Google OAuth credentials, login mode, allowed domain, and redirect URL.
6. Copy the contents of `uploads/` separately when you want to retain existing uploaded profile images. Keep the repository's `uploads/profile/.htaccess` file in place.
7. Ensure PHP can write to the runtime/upload directories used by this installation, including `tmp/`, `temp/`, `.tmp/`, and `uploads/profile/`. Create empty runtime directories if the clone does not have them. Saved sessions, old page copies, generated previews, and debug logs do not need to be transferred.
8. Open the application and verify login, assigned faculty workloads, imported student counts, and uploaded profile images.

Keep the repository's `backend/db.php` and `backend/auth_config.php` loaders. Restore the two `.local.php` files beside them; avoid replacing the loaders with older credential-containing copies.

If you configure a workstation from scratch, copy `backend/db_config.local.example.php` to `backend/db_config.local.php` and fill in the database settings. Copy `backend/auth_config.local.example.php` to `backend/auth_config.local.php` and fill in Google OAuth settings when needed. Both examples have blank credentials.

Google login on a different application URL

When the target application has a different URL, update `google_redirect_uri` in the local auth file and configure the same callback URL for that Google OAuth client. The configured URL must exactly match a registered redirect URI, as described in the [Google OAuth documentation](https://developers.google.com/identity/protocols/oauth2/web-server). The callback path is `/synk/backend/auth_google_callback.php` when the application is served under `/synk`. Preserve the existing redirect URL when the application URL stays the same. Google credentials should be transferred privately and never pasted into tracked files.

First commit and deployment

Review `REPOSITORY_EXCLUSIONS.md` before staging files. The sanitized loaders and configuration examples belong in Git; actual local configuration, database backups, and uploads do not. The repository's deployment workflow is now manual and requires GitHub's `LIGHTSAIL_SSH_KEY`, `LIGHTSAIL_HOST`, and `LIGHTSAIL_USER` secrets. It also requires the two private local configuration files under `/opt/bitnami/apache/backup_synk/` on the deployment server. No deployment has been run as part of this preparation.

The other existing GitHub workflows manage issues and issue labels. Review those workflows when setting up the new repository if you want those automations enabled.
