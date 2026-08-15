# Language Pack Server for GlotPress

A WordPress plugin that transforms a site running **GlotPress** into a language pack delivery server for custom/premium plugins and themes. It generates, stores, and serves localized translation packages in the exact style of WordPress.org.

## Key Features

1.  **Core updates REST endpoint:** Client sites can query `/wp-json/gp-language-pack-server/v1/api/{project}` to check for translation updates.
2.  **POT Import REST endpoint:** Import new translation templates from external build tools (like GitHub Actions) to GlotPress automatically via `/wp-json/gp-language-pack-server/v1/projects/{project}/import`.
3.  **Automatic Pack Generation:** Packages translations as standard `.po` and `.mo` files into a ZIP archive, triggered either dynamically (when translations are saved), or at scheduled daily intervals via WP-Cron.
4.  **Automatic JED JSON Compilation:** If translations reference JavaScript files (e.g. Gutenberg block editors), it compiles JED 1.x-compliant `.json` files and packages them into the ZIP.
5.  **Change-Detection Optimization:** Skips ZIP regeneration if no changes have occurred since the last build to save CPU and disk.
6.  **Interactive Control Center:** View active GlotPress projects, progress metrics, status badges (`Generated`, `Needs Update`, `Not Generated`), and manually build or delete ZIP files.

---

## 1. Servable Endpoints

*   **Translations Check API:**
    `GET https://your-server.com/wp-json/gp-language-pack-server/v1/api/{project-slug}`
*   **Originals Import API:**
    `POST https://your-server.com/wp-json/gp-language-pack-server/v1/projects/{project-slug}/import`

---

## 2. Automatic POT Upload (GitHub Actions)

Add this workflow step to your premium plugin repository to automatically sync translation keys when a new template file is built:

```yaml
- name: Upload POT to GlotPress
  run: |
    curl -X POST -u "wp_admin_username:application_password" \
      -F "file=@languages/my-plugin.pot" \
      "https://your-server.com/wp-json/gp-language-pack-server/v1/projects/your-project-slug/import"
```

> [!NOTE]
> Generate WordPress Application Passwords inside the user profile page of a WordPress account with write privileges to the target GlotPress project.

---

## 3. Drop-in Client Integration

To enable a premium plugin or theme to query this server and receive updates natively via the WordPress dashboard, bundle the [client package](https://github.com/helgatheviking/gp-language-pack-client/) in your software.

### Option A: Install via Composer (Recommended)

1. In your premium plugin or theme directory, run:
```bash
composer require helgatheviking/gp-language-pack-client
```

2. Initialize the client using its namespace (typically in your main plugin file, as long as Composer's autoloader is included):

```php
use HelgaTheViking\GPLanguagePack\Client as GPLanguagePackClient;

// Bootstrap translation updates from custom GlotPress server
new GPLanguagePackClient(
	'https://your-server.com',  // URL of your GlotPress site
	'your-project-slug',       // Slug or path of the GlotPress project
	'your-textdomain',         // The plugin/theme text domain (e.g. 'my-premium-plugin')
	'plugin'                   // Software type: 'plugin' or 'theme' (default 'plugin')
);
```

### Option B: Manual Require

1. Download or copy the [Client.php](https://github.com/helgatheviking/gp-language-pack-client/blob/trunk/src/Client.php) file into your project.
2. Manually require and load the namespaced class:

```php
// Bootstrap translation updates from custom GlotPress server
if ( file_exists( __DIR__ . '/Client.php' ) ) {
	require_once __DIR__ . '/Client.php';
	
	new \HelgaTheViking\GPLanguagePack\Client\Client(
		'https://your-server.com',
		'your-project-slug',
		'your-textdomain',
		'plugin'
	);
}
```

### How the Client Works:
1.  **Transient Cache:** Queries your GlotPress server and caches the response locally for **12 hours** to keep client sites fast and lightweight.
2.  **Locale Check:** Filters translation packages to match only the site's active languages (`get_locale()` and `get_available_languages()`).
3.  **Modified Checking:** Inspects the file modification time (`filemtime`) of the local `.mo` file under `wp-content/languages/plugins/` (or `themes/`).
4.  **Native Updates:** If the local file is missing or older than the server release, it injects the update directly into WordPress's core update transient. WordPress then natively presents the update under **Dashboard > Updates > Translations** and handles the download and extraction automatically.

---

## 4. WP-CLI Command

The plugin registers a custom WP-CLI command to manually trigger, test, or schedule language pack generation from the command line.

```bash
wp gp-language-packs generate [options]
```

### Options
* `--project=<slug>`: Limit generation to a single project slug. Omit to process all active projects.
* `--locale=<wp_locale>`: Limit generation to a single WordPress locale (e.g. `es_ES`). Requires `--project` to be set.
* `--threshold=<percent>`: Minimum percent translated required to generate a pack. Defaults to the threshold configured in the admin dashboard (default: `90`).

### Examples
```bash
# Generate packs for all active projects that meet the default threshold.
wp gp-language-packs generate

# Generate packs only for a specific project.
wp gp-language-packs generate --project=my-plugin

# Generate only the Spanish pack for a specific project.
wp gp-language-packs generate --project=my-plugin --locale=es_ES

# Force generation for sets at 70% or more translated.
wp gp-language-packs generate --threshold=70
```
