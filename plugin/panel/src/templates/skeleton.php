<?php
/**
 * Panel skeleton loading template.
 *
 * @var string      $id       Panel ID
 * @var string      $prefix   Panel prefix
 * @var int         $tabCount Number of tabs
 * @var string      $title    Panel title
 * @var string|null $logoUrl  Logo URL or null
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="<?php echo esc_attr($prefix); ?>-panel-<?php echo esc_attr($id); ?>" class="wp-panel-container">
    <div class="wp-panel">
        <div class="wp-panel__header">
            <div class="wp-panel__header-inner">
                <div class="wp-panel__header-left">
                    <?php if ($logoUrl): ?>
                        <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($title); ?>" class="wp-panel__logo" />
                    <?php else: ?>
                        <h1><span class="wp-panel-skeleton-text" style="width: 160px; height: 25px;"></span></h1>
                    <?php endif; ?>
                </div>
                <div class="wp-panel__header-right">
                    <!-- Future: links, actions -->
                </div>
            </div>
        </div>
        <?php if ($tabCount > 1): ?>
        <div class="wp-panel__tabs-bar">
            <div class="wp-panel__tabs-bar-inner">
                <div class="wp-panel__tabs">
                    <?php for ($universally_tab_index = 0; $universally_tab_index < min($tabCount, 4); $universally_tab_index++): ?>
                    <button class="wp-panel__tab<?php echo $universally_tab_index === 0 ? ' is-active' : ''; ?>" disabled>
                        <span class="wp-panel-skeleton-tab-text" style="width: <?php echo esc_attr(random_int(80, 160)); ?>px"></span>
                    </button>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="wp-panel__content">
            <div class="wp-panel__tab-content">
                <div class="wp-panel-section wp-panel-section--untitled components-panel__body is-opened">
                    <div class="wp-panel-section__body">
                        <?php for ($universally_field_index = 0; $universally_field_index < 3; $universally_field_index++): ?>
                        <div class="wp-panel-field">
                            <div class="wp-panel-field__label">
                                <span class="wp-panel-skeleton-text" style="width: <?php echo esc_attr(random_int(60, 100)); ?>px"></span>
                            </div>
                            <div class="wp-panel-field__control">
                                <span class="wp-panel-skeleton-input"></span>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
