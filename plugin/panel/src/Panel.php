<?php

namespace UniversallyPanel\Panel;

if (!defined('ABSPATH')) {
    exit;
}

use UniversallyPanel\Panel\Storage\SingleOption;
use UniversallyPanel\Panel\Storage\SeparateOption;
use UniversallyPanel\Panel\Storage\StorageInterface;

/**
 * Main Panel class - loads JSON config and registers admin page.
 */
final class Panel
{
    /** @var array<string, self> */
    private static array $instances = [];

    private string $id;
    private array $config;
    private StorageInterface $singleStorage;
    private StorageInterface $separateStorage;
    private string $configDir;
    private ?OnboardingState $onboardingState = null;

    /**
     * Create a Panel from a JSON file.
     */
    public static function fromJson(string $path): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(esc_html("Config file not found: {$path}"));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read, not a remote URL.
        $json = file_get_contents($path);
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . esc_html(json_last_error_msg()));
        }

        return new self($config, dirname($path));
    }

    /**
     * Create a Panel from a config array.
     */
    public static function fromArray(array $config, string $configDir = ''): self
    {
        return new self($config, $configDir);
    }

    private function __construct(array $config, string $configDir)
    {
        $this->config = $config;
        $this->configDir = $configDir;

        $this->validateConfig($config);
        $this->id = $config['id'];
        $this->singleStorage = new SingleOption($this->id);
        $this->separateStorage = new SeparateOption($this->id);

        self::$instances[$this->id] = $this;
    }

    private array $tabs = [];
    private array $sections = [];
    private array $fields = [];

    private function validateConfig(array $config): void
    {
        if (empty($config['id'])) {
            throw new \InvalidArgumentException('Panel config must have an "id"');
        }
        if (empty($config['title'])) {
            throw new \InvalidArgumentException('Panel config must have a "title"');
        }
        if (empty($config['schema'])) {
            throw new \InvalidArgumentException('Panel config must have a "schema"');
        }

        // Parse flat schema into tabs and fields
        $this->parseSchema($config['schema']);
    }

    /**
     * Parse flat schema into tabs, sections, and fields.
     * type:"tab" and type:"section" are reserved.
     */
    private function parseSchema(array $schema): void
    {
        $currentTab = null;
        $currentSection = null;
        $seenIds = [];

        foreach ($schema as $item) {
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? '';

            // Check for duplicate IDs
            if ($id && in_array($id, $seenIds, true)) {
                throw new \InvalidArgumentException(esc_html("Duplicate id in schema: {$id}"));
            }
            if ($id) {
                $seenIds[] = $id;
            }

            if ($type === 'tab') {
                // Tab definition - reset section
                $currentTab = $item;
                $currentSection = null;
                $this->tabs[$id] = $item;
            } elseif ($type === 'section') {
                // Section definition
                if ($currentTab === null) {
                    // Auto-create default tab if none defined
                    $currentTab = ['type' => 'tab', 'id' => 'general', 'label' => __('General', 'universally-language-translation-multilingual-tool')];
                    $this->tabs['general'] = $currentTab;
                }

                $item['_tab'] = $currentTab['id'];
                $currentSection = $item;
                $this->sections[$id] = $item;
            } else {
                // Field definition
                if ($currentTab === null) {
                    // Auto-create default tab if none defined
                    $currentTab = ['type' => 'tab', 'id' => 'general', 'label' => __('General', 'universally-language-translation-multilingual-tool')];
                    $this->tabs['general'] = $currentTab;
                }

                // Resolve storage: field > tab > panel > 'single'
                $panelStorage = $this->config['storage'] ?? 'single';
                $tabStorage = $currentTab['storage'] ?? $panelStorage;
                $fieldStorage = $item['storage'] ?? $tabStorage;

                // Resolve capability: field > section > tab > panel > null
                $panelCap = $this->config['capability'] ?? null;
                $tabCap = $currentTab['capability'] ?? $panelCap;
                $sectionCap = $currentSection['capability'] ?? $tabCap;
                $fieldCap = $item['capability'] ?? $sectionCap;

                $item['_tab'] = $currentTab['id'];
                $item['_section'] = $currentSection ? $currentSection['id'] : null;
                $item['_storage'] = $fieldStorage;
                $item['_capability'] = $fieldCap;
                $this->fields[$id] = $item;
            }
        }
    }

    /**
     * Register the panel with WordPress.
     */
    public function register(): self
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_body_class', [$this, 'addBodyClass']);
        add_action('wp_ajax_' . $this->id . '_save', [$this, 'handleSave']);

        return $this;
    }

    /**
     * Register admin menu.
     */
    public function registerMenu(): void
    {
        $menu = $this->config['menu'] ?? [];
        $location = $menu['location'] ?? 'settings';
        $capability = $this->config['capability'] ?? 'manage_options';

        if ($location === 'toplevel') {
            add_menu_page(
                $this->config['title'],
                $this->config['title'],
                $capability,
                $this->id,
                [$this, 'render'],
                $this->getMenuIcon($menu),
                $menu['position'] ?? null
            );

            // Optionally mirror the panel's tabs as sidebar submenu items.
            if (!empty($menu['submenuTabs'])) {
                $this->registerTabSubmenus($capability);
            }
        } else {
            // Map legacy shortcuts to actual WordPress slugs
            $locationMap = [
                'settings' => 'options-general.php',
                'tools' => 'tools.php',
            ];
            $parentSlug = $locationMap[$location] ?? $location;

            add_submenu_page(
                $parentSlug,
                $this->config['title'],
                $this->config['title'],
                $capability,
                $this->id,
                [$this, 'render']
            );
        }
    }

    /**
     * Mirror the panel's tabs as sidebar submenu items.
     *
     * Each item links to the panel page with the tab id as a URL hash; the React
     * panel (useHashTab) opens that tab on load and reacts to in-page hash changes.
     * The first accessible tab uses the bare page slug (no hash) so WordPress
     * highlights it as the current menu item. Replaces the parent entry WordPress
     * auto-adds, so the slug list reads General / Language Switcher / Styling / …
     */
    private function registerTabSubmenus(string $capability): void
    {
        global $submenu;

        if (empty($this->tabs)) {
            return;
        }

        $entries = [];
        $first = true;
        foreach ($this->tabs as $tabId => $tab) {
            $cap = $tab['capability'] ?? $capability;
            if (!current_user_can($cap)) {
                continue;
            }

            // WordPress renders index 2 verbatim as the href when it isn't a
            // registered page hook, which lets us carry the #tab anchor through.
            $slug = $first ? $this->id : 'admin.php?page=' . $this->id . '#' . $tabId;
            $label = $tab['label'] ?? $tabId;
            // Index 3 (page title) is required: WP core reads it (e.g.
            // get_admin_page_title()); omitting it triggers "Undefined array key 3"
            // and a downstream strip_tags(null) deprecation.
            $entries[] = [$label, $cap, $slug, $label];
            $first = false;
        }

        if (!empty($entries)) {
            // Intentionally replacing this menu's submenu with our per-tab links.
            // Scoped to our own slug; this is the supported way to add hash-anchored
            // submenu items, which add_submenu_page() can't express.
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $submenu[$this->id] = $entries;
        }
    }

    /**
     * Add body class on panel pages.
     */
    public function addBodyClass(string $classes): string
    {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, $this->id) !== false) {
            $classes .= ' wppanel-body';
        }
        return $classes;
    }

    /**
     * Render the panel container with skeleton loading state.
     */
    public function render(): void
    {
        $id = esc_attr($this->id);
        $prefix = 'universally';
        $tabCount = count($this->tabs);
        $title = esc_attr($this->config['title']);
        $logoUrl = $this->getLogoUrl();

        include __DIR__ . '/templates/skeleton.php';
    }

    /**
     * Resolve an asset path to a full URL.
     */
    private function resolveAssetPath(string $path): string
    {
        // Resolve relative to plugin root (parent of config directory)
        if ($this->configDir) {
            $pluginRoot = dirname($this->configDir);
            return esc_url(plugins_url($path, $pluginRoot . '/dummy.php'));
        }

        return esc_url($path);
    }

    /**
     * Resolve logoPath to a full URL.
     */
    private function getLogoUrl(): ?string
    {
        if (empty($this->config['logoPath'])) {
            return null;
        }

        return $this->resolveAssetPath($this->config['logoPath']);
    }

    /**
     * Get menu icon (dashicon or SVG data URI).
     */
    private function getMenuIcon(array $menu): string
    {
        // If iconPath is set, load SVG and convert to data URI
        if (!empty($menu['iconPath']) && $this->configDir) {
            $pluginRoot = dirname($this->configDir);
            $svgPath = $pluginRoot . '/' . ltrim($menu['iconPath'], '/');

            if (file_exists($svgPath)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read, not a remote URL.
                $svg = file_get_contents($svgPath);
                if ($svg !== false) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding a local SVG as a data URI.
                    return 'data:image/svg+xml;base64,' . base64_encode($svg);
                }
            }
        }

        // Fall back to dashicon or default
        return $menu['icon'] ?? 'dashicons-admin-generic';
    }

    /**
     * Enqueue panel assets.
     */
    public function enqueueAssets(string $hook): void
    {
        // Only load on our page
        if (strpos($hook, $this->id) === false) {
            return;
        }

        // Check onboarding status (must happen before getScriptData)
        $this->shouldShowOnboarding();

        $baseDir = dirname(__DIR__);
        $assetFile = $baseDir . '/build/index.asset.php';

        if (!file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;
        $buildUrl = plugins_url('build', $baseDir . '/panel.php');

        wp_enqueue_script(
            'universally-panel',
            $buildUrl . '/index.js',
            array_filter($asset['dependencies'], fn($dep) => $dep !== 'react-jsx-runtime'),
            $asset['version'],
            true
        );

        if (file_exists($baseDir . '/build/style-index.css')) {
            wp_enqueue_style(
                'universally-panel',
                $buildUrl . '/style-index.css',
                ['wp-components'],
                $asset['version']
            );
        }

        wp_localize_script('universally-panel', 'universallyPanelData', $this->getScriptData());
    }

    /**
     * Get data for JavaScript.
     */
    private function getScriptData(): array
    {
        // Filter fields user can access (using pre-resolved _capability)
        $accessibleFields = array_filter($this->fields, function ($field) {
            $cap = $field['_capability'] ?? null;
            return $cap === null || current_user_can($cap);
        });

        // Get accessible field IDs
        $accessibleIds = array_keys($accessibleFields);

        // Filter values to only include accessible fields (security: don't expose restricted data)
        $allValues = $this->getValues();
        $accessibleValues = array_intersect_key($allValues, array_flip($accessibleIds));

        // Filter tabs that have at least one accessible field
        $accessibleTabs = $this->getAccessibleTabs($accessibleFields);

        // Filter sections that belong to accessible tabs
        $accessibleSections = $this->getAccessibleSections($accessibleTabs);

        // Prepare config with resolved paths
        $config = $this->config;
        $logoUrl = $this->getLogoUrl();
        if ($logoUrl) {
            $config['logoPath'] = $logoUrl; // Replace path with resolved URL
        }

        // Resolve iconPath in headerActions
        if (!empty($config['headerActions'])) {
            $config['headerActions'] = array_map(function ($action) {
                if (!empty($action['iconPath'])) {
                    $action['iconPath'] = $this->resolveAssetPath($action['iconPath']);
                }
                return $action;
            }, $config['headerActions']);
        }

        $data = [
            'config' => $config,
            'parsed' => [
                'tabs' => $accessibleTabs,
                'sections' => $accessibleSections,
                'fields' => $accessibleFields,
            ],
            'values' => $accessibleValues,
            'nonce' => wp_create_nonce($this->id . '_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => $this->id . '_save',
            'restUrl' => rest_url('universally/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ];

        // Add onboarding data if active
        if ($this->onboardingState !== null && $this->onboardingState->isActive()) {
            $data['onboarding'] = $this->getOnboardingData();
        }

        return $data;
    }

    /**
     * Get tabs that have at least one accessible field.
     */
    private function getAccessibleTabs(array $accessibleFields): array
    {
        $usedTabs = array_unique(array_column($accessibleFields, '_tab'));
        return array_filter($this->tabs, fn($tab, $id) => in_array($id, $usedTabs, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get sections that belong to accessible tabs.
     */
    private function getAccessibleSections(array $accessibleTabs): array
    {
        $tabIds = array_keys($accessibleTabs);
        return array_filter($this->sections, fn($section) => in_array($section['_tab'] ?? '', $tabIds, true));
    }

    /**
     * Check if onboarding should be shown.
     */
    private function shouldShowOnboarding(): bool
    {
        $onboardingConfig = $this->config['onboarding'] ?? null;

        if (empty($onboardingConfig['enabled'])) {
            return false;
        }

        if (empty($onboardingConfig['steps'])) {
            return false;
        }

        $this->onboardingState = new OnboardingState($this->id);
        return $this->onboardingState->isActive();
    }

    /**
     * Get onboarding data for JavaScript.
     */
    private function getOnboardingData(): ?array
    {
        if (!$this->onboardingState) {
            return null;
        }

        $config = $this->config['onboarding'];
        $state = $this->onboardingState->get();

        // If status is pending and no current step, set first step
        if ($state['status'] === 'pending' || $state['current_step'] === null) {
            $firstStepId = $config['steps'][0]['id'] ?? null;
            if ($firstStepId) {
                $this->onboardingState->start($firstStepId);
                $state = $this->onboardingState->get();
            }
        }

        // Parse fields from all steps
        $fields = $this->parseOnboardingFields($config['steps']);

        return [
            'config' => $config,
            'state' => $state,
            'fields' => $fields,
        ];
    }

    /**
     * Parse fields from onboarding steps.
     */
    private function parseOnboardingFields(array $steps): array
    {
        $fields = [];
        $panelStorage = $this->config['storage'] ?? 'single';
        $panelCap = $this->config['capability'] ?? null;

        foreach ($steps as $step) {
            foreach ($step['fields'] ?? [] as $field) {
                $fieldId = $field['id'] ?? null;
                if (!$fieldId) {
                    continue;
                }

                // Resolve storage and capability (same logic as parseSchema)
                $field['_storage'] = $field['storage'] ?? $panelStorage;
                $field['_capability'] = $field['capability'] ?? $panelCap;
                $field['_step'] = $step['id'];

                $fields[$fieldId] = $field;
            }
        }

        return $fields;
    }

    /**
     * Handle AJAX save request.
     */
    public function handleSave(): void
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Verify nonce
        if (!isset($data['nonce']) || !wp_verify_nonce($data['nonce'], $this->id . '_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'universally-language-translation-multilingual-tool')], 403);
        }

        // Check capability
        $capability = $this->config['capability'] ?? 'manage_options';
        if (!current_user_can($capability)) {
            wp_send_json_error(['message' => __('Permission denied', 'universally-language-translation-multilingual-tool')], 403);
        }

        $values = $data['values'] ?? [];
        $errors = $this->validateAndSave($values);

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => __('Validation failed', 'universally-language-translation-multilingual-tool'),
                'errors' => $errors,
            ], 400);
        }

        // Handle onboarding step completion
        $onboarding = $data['onboarding'] ?? null;
        if ($onboarding && isset($this->config['onboarding']['enabled']) && $this->config['onboarding']['enabled']) {
            // Initialize onboarding state for AJAX request if not already done
            if ($this->onboardingState === null) {
                $this->onboardingState = new OnboardingState($this->id);
            }
            $stepId = $onboarding['stepId'] ?? null;
            $action = $onboarding['action'] ?? 'complete';

            if ($stepId && $action === 'complete') {
                $this->onboardingState->markStepCompleted($stepId);
            }
            if ($stepId && $action === 'skip') {
                $this->onboardingState->markStepSkipped($stepId);
            }
            if (isset($onboarding['nextStepId'])) {
                $this->onboardingState->setCurrentStep($onboarding['nextStepId']);
            }
            if ($action === 'finish') {
                $this->onboardingState->complete();
            }
            if ($action === 'skipAll') {
                $this->onboardingState->skip();
            }
        }

        wp_send_json_success([
            'message' => __('Settings saved', 'universally-language-translation-multilingual-tool'),
            'values' => $this->getValues(),
            'onboardingState' => $this->onboardingState !== null ? $this->onboardingState->get() : null,
        ]);
    }

    /**
     * Validate and save values.
     *
     * @return array<string, string> Validation errors
     */
    private function validateAndSave(array $values): array
    {
        $errors = [];
        $sanitized = [];
        $validator = new Validator();
        $sanitizer = new Sanitizer();

        // First pass: validate and sanitize all fields
        foreach ($this->fields as $fieldId => $fieldConfig) {
            if (!array_key_exists($fieldId, $values)) {
                continue;
            }

            // Check pre-resolved capability
            $fieldCap = $fieldConfig['_capability'] ?? null;
            if ($fieldCap !== null && !current_user_can($fieldCap)) {
                continue;
            }

            $value = $values[$fieldId];
            $label = $fieldConfig['label'] ?? $fieldId;

            // Validate using rules from config
            $validateRules = $fieldConfig['validate'] ?? '';
            if (!empty($validateRules)) {
                $valid = $validator->validate($value, $validateRules, $label);
                if ($valid instanceof \WP_Error) {
                    $errors[$fieldId] = $valid->get_error_message();
                    continue;
                }
            }

            // Sanitize using rules from config
            $sanitizeRules = $fieldConfig['sanitize'] ?? 'text_field';
            $sanitized[$fieldId] = [
                'value' => $sanitizer->sanitize($value, $sanitizeRules),
                'storage' => $fieldConfig['_storage'] ?? 'single',
            ];
        }

        // If any errors, don't save anything
        if (!empty($errors)) {
            return $errors;
        }

        // Second pass: save all sanitized values
        $singleValues = [];
        foreach ($sanitized as $fieldId => $data) {
            if ($data['storage'] === 'separate') {
                $this->separateStorage->set($fieldId, $data['value']);
            } else {
                $singleValues[$fieldId] = $data['value'];
            }
        }

        // Save single storage values
        if (!empty($singleValues)) {
            $existing = $this->singleStorage->getAll();
            $merged = array_merge($existing, $singleValues);

            $schemaFieldIds = array_keys(array_filter(
                $this->fields,
                fn($f) => ($f['_storage'] ?? 'single') === 'single'
            ));
            $clean = array_intersect_key($merged, array_flip($schemaFieldIds));

            $this->singleStorage->setAll($clean);
        }

        return $errors;
    }

    /**
     * Get all current values with defaults.
     */
    public function getValues(): array
    {
        $values = [];

        foreach ($this->fields as $fieldId => $fieldConfig) {
            $storage = $fieldConfig['_storage'] ?? 'single';
            $default = $fieldConfig['default'] ?? null;

            $storageImpl = $storage === 'separate'
                ? $this->separateStorage
                : $this->singleStorage;

            $values[$fieldId] = $storageImpl->get($fieldId, $default);
        }

        return $values;
    }

    /**
     * Get parsed tabs.
     */
    public function getTabs(): array
    {
        return $this->tabs;
    }

    /**
     * Get parsed sections.
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * Get parsed fields.
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get instance by ID.
     */
    public static function getInstance(string $id): ?self
    {
        return self::$instances[$id] ?? null;
    }

    /**
     * Get a value from a panel.
     *
     * @param string $panelId
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $panelId, string $field, $default = null)
    {
        $panel = self::getInstance($panelId);
        if (!$panel) {
            return $default;
        }

        $values = $panel->getValues();
        return $values[$field] ?? $default;
    }

    /**
     * Get all values from a panel.
     */
    public static function getAll(string $panelId): array
    {
        $panel = self::getInstance($panelId);
        return $panel ? $panel->getValues() : [];
    }

    /**
     * Check if a panel has a field value set.
     */
    public static function has(string $panelId, string $field): bool
    {
        $panel = self::getInstance($panelId);
        if (!$panel) {
            return false;
        }

        $values = $panel->getValues();
        return array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '';
    }
}
