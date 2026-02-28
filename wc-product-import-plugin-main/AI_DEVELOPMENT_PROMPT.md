# COMPREHENSIVE AI PROMPT FOR WORDPRESS PLUGIN DEVELOPMENT

## Using This Prompt

This is a **template prompt structure** you can use with Claude, ChatGPT, Gemini, or any AI to generate production-quality WordPress plugins. Customize it with your specific requirements.

---

# 🚀 COMPLETE PROMPT TEMPLATE

## PART 1: PROJECT OVERVIEW & REQUIREMENTS

```
You are an expert WordPress and WooCommerce plugin developer with 15+ years of experience.
You will develop a production-ready WordPress plugin following enterprise-level architecture patterns.

PLUGIN PURPOSE:
[Your plugin's core functionality in 2-3 sentences]

Example: "Create a WordPress plugin that synchronizes WooCommerce products from a remote JSON 
data source. The plugin will automatically create new products, update existing ones, and delete 
products no longer in the source. It will support scheduled syncing, manual triggers, comprehensive 
logging, and real-time progress tracking."

PLUGIN DETAILS:
- Name: [Plugin Name]
- Slug: [plugin-slug]
- Version: 1.0.0
- Author: [Your Name]
- WordPress Minimum: 5.8
- PHP Minimum: 7.4
- WooCommerce (if applicable): Yes, version 5.0+

KEY FEATURES TO IMPLEMENT:
1. [Feature 1] - [Brief description]
2. [Feature 2] - [Brief description]
3. [Feature 3] - [Brief description]
4. [Feature 4] - [Brief description]
5. [Feature 5] - [Brief description]

EXPECTED USER WORKFLOW:
1. User installs plugin
2. User configures settings (admin panel)
3. User manually triggers first sync OR waits for automatic sync
4. Plugin fetches data from external source
5. Plugin processes and syncs data
6. User views results and logs
```

---

## PART 2: ARCHITECTURE SPECIFICATIONS

```
ARCHITECTURE PATTERN:
- Core Pattern: Modular Object-Oriented Design with Dependency Injection
- Main Class: Singleton pattern for bootstrap
- Supporting Classes: Service-based architecture
- Data Flow: Linear with clear input/output boundaries

CLASS STRUCTURE (You will create these classes):

1. MAIN BOOTSTRAP CLASS: [Plugin_Name]
   - Pattern: Singleton
   - Responsibility: Initialize plugin, load dependencies, manage WordPress hooks
   - Methods:
     * get_instance() - Return singleton instance
     * __construct() - Initialize
     * check_dependencies() - Verify required plugins/features exist
     * includes() - Load all class files
     * init_hooks() - Register activation/deactivation/AJAX hooks
     * activate() - Plugin activation tasks (create directories, set defaults)
     * deactivate() - Plugin deactivation tasks (cleanup)
     * init_components() - Initialize components after plugins_loaded
     * ajax_[action_name]() - Handle AJAX requests

2. SYNC HANDLER CLASS: [Plugin_Name]_Sync_Handler
   - Pattern: Dependency Injection (receives all dependencies in constructor)
   - Responsibility: Orchestrate the entire sync/import workflow
   - Dependencies Injected:
     * Logger instance
     * Data Fetcher instance
     * Data Mapper instance
     * State Manager instance
   - Properties:
     * $logger - For logging
     * $fetcher - For data retrieval
     * $mapper - For data transformation
     * $state_manager - For state persistence
     * $stats - Array tracking created/updated/deleted/skipped/errors counts
     * $batch_size - Number of items per batch
   - Main Methods:
     * run_sync() - Main orchestration method
     * process_[item]($id, $data, $existing_items) - Process individual item
     * [delete]_[item]($id) - Delete/trash item
     * update_progress($status, $message, $percent) - Update UI progress
     * update_sync_status($status, $message) - Final status
     * get_stats() - Return statistics
     * is_running() - Check if sync in progress
     * get_progress() - Get current progress

3. DATA FETCHER CLASS: [Plugin_Name]_Fetcher
   - Responsibility: Retrieve data from external source (API, JSON URL, CSV, etc.)
   - Methods:
     * fetch($url = '') - Download and decode data
     * validate_structure($data) - Verify data format is correct
     * save_hash($data) - Store hash for comparison
     * get_[item]_hash($item) - Generate hash of single item
   - Error Handling:
     * Network errors (timeouts, connection refused)
     * HTTP errors (404, 500, etc.)
     * Format errors (invalid JSON, missing fields)
     * Empty responses
   - Features:
     * Extended timeout (120 seconds) for large files
     * SSL verification enabled
     * Proper user-agent headers
     * Retry logic (optional)

4. DATA MAPPER CLASS: [Plugin_Name]_Mapper
   - Responsibility: Transform external data to internal format, create/update items
   - Methods:
     * map_[item]($external_data, $existing_id = null) - Create or update
     * get_all_synced_[items]() - Retrieve all previously synced items
     * [create/get]_[resource]($name) - Create resources (categories, attributes, etc.)
   - Features:
     * Automatic resource creation (categories, attributes, tags)
     * Metadata storage for linking
     * Image/media handling
     * Relationship management
     * Error recovery for partial failures

5. STATE MANAGER CLASS: [Plugin_Name]_State_Manager
   - Responsibility: Remember previous state for efficient change detection
   - Methods:
     * rotate_state() - Move current to previous
     * save_state($data) - Persist current state
     * load_previous_state() - Load previous state index
     * get_previous_[item]($id) - Retrieve single previous item
     * compare_[items]($previous, $current) - Detect field changes
   - File Format: NDJSON (Newline-Delimited JSON)
     * One JSON object per line
     * Streamable without loading entire file to memory
     * Index file for fast lookups
   - Storage Location: /cache/ directory
     * Protected with .htaccess and index.php

6. LOGGER CLASS: [Plugin_Name]_Logger
   - Responsibility: Comprehensive logging for debugging and auditing
   - Methods:
     * log($message, $level, $context) - Generic log
     * info/warning/error/success($message, $context) - Level-specific
     * start_sync() - Mark sync beginning
     * end_sync($stats) - Mark sync completion
     * get_log_files($limit) - Retrieve log files
   - Log Location: /logs/ directory
   - Log Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] Message | Context: JSON
   - Features:
     * Daily log files
     * Protected with .htaccess
     * Supports context arrays

7. SCHEDULER CLASS: [Plugin_Name]_Cron (if applicable)
   - Pattern: Singleton
   - Responsibility: Schedule recurring tasks using WordPress Cron
   - Methods:
     * add_cron_schedules($schedules) - Register custom intervals
     * schedule_[task]() - Schedule task
     * unschedule_[task]() - Remove scheduled task
     * run_scheduled_[task]() - Execute when cron triggers
     * get_next_scheduled() - Get next run time
   - Features:
     * Custom intervals (minutes, hours, days)
     * Minimum interval validation
     * Auto-schedule on enable
     * Auto-unschedule on disable
     * Duplicate run prevention

8. ADMIN SETTINGS CLASS: [Plugin_Name]_Admin_Settings
   - Pattern: Singleton
   - Responsibility: Admin interface, settings registration
   - Methods:
     * add_menu() - Register admin menu
     * register_settings() - Register settings with WordPress Settings API
     * enqueue_assets($hook) - Load CSS/JavaScript
     * render_page() - Display settings interface
   - Features:
     * Settings tabs
     * Form fields with sanitization
     * Manual action button
     * Status display
     * Log viewer
     * Progress bar (via AJAX)

INTEGRATION POINTS:
- WordPress Hooks: activation_hook, deactivation_hook, plugins_loaded, admin_menu, admin_init, wp_ajax_*
- WordPress Functions: get_option, update_option, delete_option, wp_remote_get, wp_insert_post, wp_update_post, wp_delete_post
- Database: wp_postmeta, wp_posts, wp_options, wp_term_taxonomy (if applicable)
```

---

## PART 3: SPECIFIC FUNCTIONALITY & DATA MAPPING

```
DATA SOURCE FORMAT:
[Describe your external data structure - JSON, CSV, etc.]

Example JSON:
{
  "external_id_1": {
    "id": "external_id_1",
    "name": "Item Name",
    "price": 99.99,
    "quantity": 50,
    "category": "Parent - Child - Grandchild",
    "attributes": [
      {
        "name": "Color",
        "values": ["Red", "Blue", "Green"]
      }
    ],
    "related_items": ["id2", "id3"],
    "images": ["https://example.com/img1.jpg"]
  }
}

MAPPING RULES:
External Field        →  Internal Format/Storage
─────────────────────────────────────────────
id                   →  Store as meta: _external_id
name                 →  Main title/name field
price                →  Regular price (float)
quantity             →  Stock quantity (int)
category             →  Taxonomy: product_cat (hierarchical)
attributes           →  WooCommerce attributes (global taxonomies)
related_items        →  Product relationships
images               →  Media library (featured + gallery)
[Add your mappings]

SYNC LOGIC FLOW:
1. Fetch data from source
2. Load previous state (if exists)
3. For each item in source data:
   a. Check if exists in system
   b. If exists:
      - Calculate hash of new data
      - Compare with stored hash
      - If different: Update with field-level change detection
      - If same: Skip (no changes)
   c. If new:
      - Create new item
      - Store external ID reference
      - Log creation
4. Find items in system NOT in source data:
   - Delete or trash based on setting
5. Create relationships/links between items
6. Save new state for next sync
7. Update timestamps and statistics
8. Return sync report

CHANGE DETECTION STRATEGY:
- Primary: Hash comparison (md5 of entire item data)
- Secondary: Field-level comparison (if previous state available)
- Tertiary: Manual field comparison (for detailed logs)

ERROR HANDLING DURING SYNC:
- If entire fetch fails: Return error, don't proceed
- If individual item fails: Log error, continue processing
- If state save fails: Warn but don't fail sync
- If relationship link fails: Log warning, continue
- Collect all errors and report in final statistics

SPECIAL FEATURES:
[Add specific features needed for your plugin type]

Examples:
- Hierarchical category creation with parent-child relationships
- Global attribute creation with term assignment
- Image downloading and media library integration
- SKU generation from external ID
- Metadata storage for external references
- Product relationship linking (cross-sells, related, etc.)
```

---

## PART 4: SECURITY & VALIDATION

```
SECURITY REQUIREMENTS:

1. CAPABILITY CHECKING:
   - All AJAX handlers: check_ajax_referer('nonce_name')
   - All AJAX handlers: if (!current_user_can('manage_options')) wp_die()
   - Settings page: Use 'manage_woocommerce' or 'manage_options' capability

2. INPUT SANITIZATION:
   - JSON URL: sanitize_url() or wp_http_validate_url()
   - Text fields: sanitize_text_field()
   - Integers: absint() or intval()
   - Arrays: array_map('sanitize_text_field', $array)
   - Database: Use prepare() for SQL queries

3. OUTPUT ESCAPING:
   - HTML: esc_html()
   - Attributes: esc_attr()
   - URLs: esc_url()
   - JavaScript data: wp_json_encode()
   - Admin pages: esc_html__() for translatable strings

4. NONCE PROTECTION:
   - Generate: wp_create_nonce('action_name')
   - Verify: check_ajax_referer('action_name', 'nonce_param')
   - Use different nonces for different actions

5. FILE PROTECTION:
   - /logs/ and /cache/ directories: Add .htaccess with "deny from all"
   - Index.php in protected dirs: "<?php // Silence is golden"
   - Never expose sensitive paths to web

6. EXTERNAL DATA VALIDATION:
   - Verify data structure (required fields)
   - Validate data types
   - Check for malicious content
   - Rate limiting on API calls (if applicable)

7. ADDITIONAL SECURITY:
   - SSL verification: 'sslverify' => true in wp_remote_get()
   - Timeout settings: 'timeout' => 120
   - User-agent header: Identify your plugin
   - No direct access: if (!defined('ABSPATH')) exit;
```

---

## PART 5: USER INTERFACE & UX

```
ADMIN INTERFACE REQUIREMENTS:

Settings Page Location: WooCommerce menu (or appropriate parent menu)

TAB 1: SETTINGS
- Input: JSON URL (text field with validation)
- Input: Sync Enabled (toggle/checkbox)
- Input: Sync Interval (number field + select for unit: minutes/hours/days)
- Input: Delete Behavior (radio: trash vs. permanent delete)
- Button: "Save Settings" (WordPress default)
- Display: Last sync timestamp and status

TAB 2: STATUS/LOGS
- Display: Last sync result (success/error)
- Display: Sync statistics (created, updated, deleted, skipped, errors)
- Display: Next scheduled sync time
- Display: Log file list with download buttons
- Button: "View Logs" or inline log viewer
- Display: Real-time sync progress bar (during sync)

TAB 3: LOGS (Optional detailed tab)
- List all available log files
- Date filtering
- Download individual logs
- Clear logs button (with confirmation)

MAIN ACTIONS:
- Button: "Sync Now" (manually trigger sync)
  * Confirmation dialog: "Run sync now?"
  * Disabled during sync
  * Shows real-time progress
- Progress Bar:
  * Status message
  * Percentage complete (0-100%)
  * Current operation (fetching, processing, saving, etc.)
  * Updates every 2 seconds via AJAX

JAVASCRIPT REQUIREMENTS:
- jQuery for AJAX calls
- Real-time progress polling (setInterval)
- Button state management (disabled during sync)
- Progress bar animation
- Error/success notifications
- Nonce verification

STYLING:
- WordPress admin.css compatibility
- Responsive design
- Accessible (WCAG 2.1 AA)
- Custom CSS for branding
```

---

## PART 6: CODING STANDARDS & BEST PRACTICES

```
CODE STYLE & STANDARDS:

1. NAMING CONVENTIONS:
   - Functions: lowercase_with_underscores
   - Classes: CapitalizedCamelCase
   - Constants: UPPERCASE_WITH_UNDERSCORES
   - Private properties: $variable_name (lowercase)
   - Private methods: private_method_name()
   - Hooks/Actions: plugin_slug_descriptive_name

2. DOCUMENTATION:
   - Every class: PHPDoc with @package, @author, @since
   - Every public method: PHPDoc with @param, @return, @throws
   - Every property: PHPDoc with @var type
   - Complex logic: Inline comments explaining "why"
   - No "TODO" comments left in production code

3. ERROR HANDLING:
   - Use WP_Error for all errors
   - Check all return values for errors (is_wp_error())
   - Meaningful error messages for debugging
   - Never suppress errors with @
   - Proper exception handling with try/catch

4. PERFORMANCE:
   - Cache expensive operations
   - Use batch processing for large datasets
   - Minimize database queries
   - Lazy load when possible
   - Use indexes for file lookups

5. CODE ORGANIZATION:
   - One class per file
   - File naming: class-[plugin-slug]-[class-name].php
   - Logical method grouping
   - Maximum 500-700 lines per class (consider splitting larger ones)

6. WordPress Best Practices:
   - Use WordPress APIs (no direct database access)
   - Use wp_remote_get instead of curl
   - Use WP_Query instead of direct SQL
   - Use get/update/delete_option for persistent data
   - Use get/update/delete_post_meta for post metadata
   - Use wp_schedule_event for cron
   - Use add_action/do_action for hooks

7. INTERNATIONALIZATION:
   - Wrap all user-facing strings with __() or esc_html__()
   - Use consistent text domain: [plugin-slug]
   - Provide .pot file for translations
   - Use printf-style for dynamic strings

8. DEFENSIVE CODING:
   - Null checks before using variables
   - Type checking for parameters
   - Return type declarations (PHP 7.1+)
   - Parameter type hints
   - Assume network requests will fail
   - Assume users will do unexpected things

REQUIRED FILE STRUCTURE:
[plugin-slug]/
├── [plugin-slug].php              # Main plugin file
├── includes/
│   ├── class-[slug]-main.php
│   ├── class-[slug]-sync-handler.php
│   ├── class-[slug]-fetcher.php
│   ├── class-[slug]-mapper.php
│   ├── class-[slug]-state-manager.php
│   ├── class-[slug]-logger.php
│   ├── class-[slug]-cron.php
│   └── class-[slug]-admin-settings.php
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
├── logs/
│   ├── .htaccess (deny from all)
│   └── index.php (silence)
├── cache/
│   ├── .htaccess
│   └── index.php
└── README.md
```

---

## PART 7: TESTING & VALIDATION SCENARIOS

```
TESTING CHECKLIST:

1. FIRST-TIME SETUP:
   - [ ] Plugin activates without errors
   - [ ] Required directories created (/logs, /cache)
   - [ ] Default options set correctly
   - [ ] Admin menu item appears
   - [ ] Settings page loads without JavaScript errors

2. CONFIGURATION:
   - [ ] JSON URL validation works
   - [ ] Settings save correctly
   - [ ] Nonce verification works on settings form
   - [ ] AJAX nonce verification prevents CSRF
   - [ ] Only admins can access settings

3. DATA FETCHING:
   - [ ] Valid JSON URL fetches data
   - [ ] Invalid URL returns proper error
   - [ ] Network timeout handled gracefully
   - [ ] Empty response handled
   - [ ] Invalid JSON format detected
   - [ ] 404/500 errors handled
   - [ ] SSL certificate validation works

4. INITIAL SYNC:
   - [ ] Sync completes without errors
   - [ ] Correct number of items created
   - [ ] Metadata stored correctly
   - [ ] External IDs linked properly
   - [ ] Categories/tags created as needed
   - [ ] Images downloaded and attached
   - [ ] Stats calculated correctly

5. SECOND SYNC (NO CHANGES):
   - [ ] All items skipped (no changes)
   - [ ] Hashes match, updates skipped
   - [ ] Stats show 0 created, 0 updated
   - [ ] Execution faster than first sync
   - [ ] Logs show "skipped" entries

6. UPDATE SYNC (WITH CHANGES):
   - [ ] Changed items detected
   - [ ] Only changed items updated
   - [ ] Field-level changes logged
   - [ ] Stats accurately reflect changes
   - [ ] Unchanged items skipped

7. DELETE BEHAVIOR:
   - [ ] Items removed from source are trashed
   - [ ] Or permanently deleted (if configured)
   - [ ] Related items handled correctly
   - [ ] Logs show deletion

8. SCHEDULING:
   - [ ] Cron event scheduled correctly
   - [ ] Interval set properly
   - [ ] Cron executes on schedule
   - [ ] Prevents duplicate simultaneous runs
   - [ ] Reschedules when interval changes

9. MANUAL TRIGGER:
   - [ ] AJAX request works
   - [ ] Progress updates in real-time
   - [ ] Completion message shown
   - [ ] Button disabled during sync
   - [ ] Errors displayed to user

10. LOGGING:
    - [ ] All operations logged
    - [ ] Log files created daily
    - [ ] Errors logged with context
    - [ ] Logs accessible in admin
    - [ ] Logs protected from web access

11. EDGE CASES:
    - [ ] Very large datasets (1000+ items)
    - [ ] Items with missing optional fields
    - [ ] Duplicate external IDs handled
    - [ ] Special characters in data
    - [ ] Memory limits respected
    - [ ] Timeout settings appropriate

12. ERROR RECOVERY:
    - [ ] Sync can be retried after failure
    - [ ] Partial syncs don't corrupt data
    - [ ] State files cleaned up properly
    - [ ] Old logs cleaned up (7+ days)
```

---

## PART 8: ADDITIONAL INSTRUCTIONS FOR AI

```
IMPORTANT CODING INSTRUCTIONS FOR AI GENERATION:

1. STRUCTURE:
   - Create one class per file
   - Each class should be self-contained but loosely coupled
   - Use dependency injection to pass dependencies
   - Avoid creating dependencies inside methods
   - All properties should be private with getter methods if needed

2. METHODS:
   - Keep methods focused (do one thing well)
   - Maximum 50 lines per method if possible
   - Extract complex logic into separate helper methods
   - Use meaningful method names that describe what they do
   - Include parameter type hints and return type declarations

3. COMMENTS:
   - Add PHPDoc comments to every class and public method
   - Include @param, @return, @throws in method comments
   - Add inline comments for "why" not "what"
   - Explain non-obvious logic
   - Keep comments in sync with code

4. ERROR HANDLING:
   - Wrap external API calls in try/catch
   - Return WP_Error for all errors
   - Check return values before using
   - Log all errors with context
   - Provide user-friendly error messages

5. PROGRESS TRACKING:
   - Update progress every 10-50 items (depending on dataset size)
   - Store progress in WordPress options
   - Include: status, message, percentage, timestamp
   - Use AJAX to retrieve progress in real-time

6. LOGGING:
   - Log entry and exit of major operations
   - Log decisions (why something was skipped, etc.)
   - Include context arrays with relevant data
   - Use consistent log levels (info, warning, error, success)
   - Don't log sensitive data (passwords, API keys)

7. STATE MANAGEMENT:
   - Store state in /cache/ directory
   - Use NDJSON format for large state files
   - Create index file for fast lookups
   - Rotate state on each sync (current becomes previous)
   - Clean up old files

8. PERFORMANCE:
   - Cache taxonomy term lookups
   - Cache attribute lookups
   - Batch database operations when possible
   - Use index files for fast searches
   - Don't reload data unnecessarily

9. SECURITY IN CODE:
   - Every sanitization: explain why in comment
   - Every permission check: explain what permission needed
   - Every nonce: use unique action name
   - Validate array keys before accessing
   - Use prepared statements for any SQL

10. TESTABILITY:
    - Design classes so dependencies can be injected
    - Make methods testable (no WordPress globals in logic)
    - Use filters and actions for extensibility
    - Avoid singletons in testable classes (only in bootstrap)
```

---

## PART 9: CONFIGURATION & CUSTOMIZATION

```
PLUGIN SETTINGS (OPTIONS):

[plugin_slug]_enabled
- Type: 'yes' or 'no'
- Default: 'no'
- Purpose: Master on/off switch

[plugin_slug]_[data_source]_url
- Type: string (URL)
- Default: ''
- Purpose: URL/endpoint to fetch data from

[plugin_slug]_sync_interval_value
- Type: integer
- Default: 1
- Purpose: Number for interval

[plugin_slug]_sync_interval_unit
- Type: string ('minutes', 'hours', 'days')
- Default: 'hours'
- Purpose: Unit for interval

[plugin_slug]_delete_behavior
- Type: string ('trash' or 'delete')
- Default: 'trash'
- Purpose: What to do with removed items

[plugin_slug]_last_sync
- Type: MySQL datetime
- Purpose: Track last sync timestamp

[plugin_slug]_last_sync_stats
- Type: JSON
- Purpose: Store creation/update/delete counts

[plugin_slug]_last_sync_status
- Type: JSON
- Purpose: Store success/error with message

[plugin_slug]_sync_running
- Type: '1' or false
- Purpose: Flag that sync is in progress

[plugin_slug]_sync_progress
- Type: JSON with status, message, progress, timestamp
- Purpose: Real-time progress updates

HOOKS PROVIDED FOR EXTENSION:

Actions (for developers to hook into):
- do_action('[plugin_slug]_sync_started')
- do_action('[plugin_slug]_before_[item]_map', $external_data, $existing_id)
- do_action('[plugin_slug]_[item]_created', $internal_id, $external_data)
- do_action('[plugin_slug]_[item]_updated', $internal_id, $external_data)
- do_action('[plugin_slug]_[item]_deleted', $internal_id)
- do_action('[plugin_slug]_sync_completed', $stats)

Filters (for developers to modify behavior):
- apply_filters('[plugin_slug]_[data_source]_data', $data)
- apply_filters('[plugin_slug]_[item]_data_before_map', $item_data)
- apply_filters('[plugin_slug]_sync_interval', $interval_seconds)
```

---

## PART 10: FINAL INSTRUCTIONS

```
FINAL REMINDERS FOR AI:

1. COMPLETENESS:
   - Generate fully functional code, not just scaffolding
   - Include error handling for all scenarios
   - Implement all described features
   - Production-ready, not prototype code

2. QUALITY:
   - Follow WordPress coding standards exactly
   - Use best practices throughout
   - Make code maintainable and understandable
   - Include comments and documentation

3. TESTING:
   - Consider edge cases while coding
   - Implement defensive programming
   - Handle missing/invalid data gracefully
   - Log everything for debugging

4. SECURITY:
   - Never skip nonce checks
   - Never skip capability checks
   - Always sanitize/escape appropriately
   - Follow principle of least privilege

5. FORMATTING:
   - Use proper indentation (tabs)
   - Follow PSR-12 style guide
   - Consistent code style throughout
   - Clean and readable code

6. OPTIMIZATION:
   - Consider performance from the start
   - Cache expensive operations
   - Use batch processing for bulk operations
   - Implement streaming for large files

START GENERATING CODE:

I want you to:
1. Generate the main plugin file ([plugin-slug].php)
2. Generate all required class files
3. Generate admin interface HTML
4. Generate admin JavaScript
5. Generate admin CSS
6. Generate README.md with setup instructions

The code should be production-ready, fully documented, and follow all standards above.
```

---

---

# 🎯 HOW TO USE THIS PROMPT EFFECTIVELY

## Step 1: Customize the Template
Replace bracketed items with your specific details:
- `[Plugin Name]` → Your plugin name
- `[Your plugin's core functionality]` → Your description
- `[Feature 1-5]` → Your specific features
- etc.

## Step 2: Be Specific About Data
Provide **exact** JSON/data structure your plugin will use:
```
Instead of: "The plugin works with JSON data"
Say: "The JSON has this exact structure: {...}"
```

## Step 3: Add Business Logic
Include your specific requirements:
```
"Products should be marked as synced with meta key '_synced_timestamp'
and should not be re-updated if the hash hasn't changed in last 24 hours"
```

## Step 4: Request in Sections
First request:
```
"Generate the complete file structure and main bootstrap class with all methods documented"
```

Second request:
```
"Generate the WCPS_Sync_Handler class with complete run_sync() method and all helper methods"
```

This gets better results than asking for everything at once.

## Step 5: Iterate & Refine
After getting initial code:
```
"Add more detailed error logging to the fetch method"
"Make the progress updates more frequent"
"Add field-level change detection to the state manager"
```

---

# 💡 TIPS FOR BEST AI-GENERATED CODE

### DO:
✅ Be very specific about requirements
✅ Provide exact data structure examples
✅ Mention security requirements explicitly
✅ Ask for documentation in the code
✅ Request error handling scenarios
✅ Break large requests into smaller ones
✅ Review and refine iteratively
✅ Ask for WordPress-specific patterns
✅ Request comprehensive logging

### DON'T:
❌ Be vague: "Make a plugin that syncs stuff"
❌ Skip error handling requirements
❌ Forget security details
❌ Ask for multiple classes in one request
❌ Skip code review after generation
❌ Accept code without documentation
❌ Ignore WordPress standards
❌ Request code without testing plan

---

# 📋 COMPLETE WORKFLOW EXAMPLE

### Request 1: Bootstrap & Architecture
```
I want to create a WordPress plugin called "Product Sync Pro" that synchronizes 
WooCommerce products from a JSON API endpoint.

[Copy PARTS 1-2 of prompt, customize with your details]

Generate the main plugin file with the WC_Product_Sync_Pro class. 
Include all methods, proper error handling, and comprehensive PHPDoc comments.
```

### Request 2: Core Sync Logic
```
[Copy PART 3]

Now generate the sync handler class that orchestrates the entire operation.
Include all documented methods and error scenarios.
```

### Request 3: Data Handling
```
[Copy relevant parts of PARTS 3-4]

Generate the fetcher, mapper, and state manager classes.
```

### Request 4: UI & Admin
```
[Copy PARTS 5-6]

Generate the admin settings class, HTML template, JavaScript, and CSS.
```

### Request 5: Polish & Documentation
```
Generate comprehensive README.md with:
- Installation instructions
- Configuration guide
- How to use
- Troubleshooting
- Code examples for developers

Also generate a DEVELOPMENT.md file with:
- Architecture overview
- Class relationships
- How to extend the plugin
- Testing instructions
```

---

This prompt structure is **battle-tested** and produces **production-quality code** when used with modern AI models. Customize it for your needs and use it iteratively for best results.
