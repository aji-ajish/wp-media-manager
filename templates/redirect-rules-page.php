<?php

/**
 * Redirect Rules admin page template.
 * Location: templates/redirect-rules-page.php
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpmm-wrap">

    <div class="wpmm-header">
        <div class="wpmm-header__left">
            <span class="dashicons dashicons-randomize wpmm-header__icon"></span>
            <h1 class="wpmm-header__title"><?php esc_html_e('Redirect Rules Manager', 'wp-media-manager'); ?></h1>
        </div>
        <div class="wpmm-header__right">
            <button type="button" class="wpmm-btn wpmm-btn--primary" id="wpmm-add-rule-btn">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e('Add New Rule', 'wp-media-manager'); ?>
            </button>
        </div>
    </div>

    <div id="wpmm-rules-toast-container" aria-live="polite" aria-atomic="true"></div>

    <div class="wpmm-table-wrap">
        <div id="wpmm-rules-loading" class="wpmm-loading" hidden>
            <span class="wpmm-spinner"></span>
            <span><?php esc_html_e('Loading redirect rules…', 'wp-media-manager'); ?></span>
        </div>

        <div id="wpmm-rules-empty" class="wpmm-empty" hidden">
            <span class="dashicons dashicons-randomize wpmm-empty__icon"></span>
            <h3 class="wpmm-empty__title"><?php esc_html_e('No redirect rules yet', 'wp-media-manager'); ?></h3>
            <p class="wpmm-empty__desc"><?php esc_html_e('Click "Add New Rule" to create your first redirection setup.', 'wp-media-manager'); ?></p>
        </div>

        <div id="wpmm-rules-grid" class="wpmm-grid" hidden"></div>
    </div>
</div>

<div id="wpmm-rules-modal" class="wpmm-modal" role="dialog" aria-modal="true" aria-labelledby="wpmm-rule-modal-title" hidden>
    <div class="wpmm-modal__backdrop"></div>
    <div class="wpmm-modal__dialog" style="max-width: 500px;">

        <div class="wpmm-modal__header">
            <h2 class="wpmm-modal__title" id="wpmm-rule-modal-title"><?php esc_html_e('Add Redirect Rule', 'wp-media-manager'); ?></h2>
            <button type="button" class="wpmm-modal__close" id="wpmm-rules-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wp-media-manager' ); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>

        <div class="wpmm-modal__body">
            <input type="hidden" id="wpmm-rule-id" value="" />

            <div class="wpmm-form-row">
                <label class="wpmm-label" for="wpmm-rule-source">
                    <?php esc_html_e('Source Path / Old URL', 'wp-media-manager'); ?>
                    <span class="wpmm-label__hint" style="color:#2563eb; font-weight:bold;">
                        <?php esc_html_e('Enter only the path for the current site (e.g., old-url) or the full URL for an /old/different domain (e.g., https://olddomain.com/old-url).', 'wp-media-manager'); ?>
                    </span>
                </label>
                <input type="text" id="wpmm-rule-source" class="wpmm-input" autocomplete="off" placeholder="/old-url OR https://olddomain.com/old-url" />
            </div>

            <div class="wpmm-form-row">
                <label class="wpmm-label" for="wpmm-rule-target">
                    <?php esc_html_e('Target URL / Destination', 'wp-media-manager'); ?>
                    <span class="wpmm-label__hint" style="color:#16a34a; font-weight:bold;">
                        <?php esc_html_e('(Can be a full URL OR just a path: /new-url)', 'wp-media-manager'); ?>
                    </span>
                </label>
                <input type="text" id="wpmm-rule-target" class="wpmm-input" autocomplete="off" placeholder="/new-url OR https://newdomain.com/new-url" />
            </div>

            <div class="wpmm-form-row">
                <label class="wpmm-label" for="wpmm-rule-type"><?php esc_html_e('Redirect Type', 'wp-media-manager'); ?></label>
                <select id="wpmm-rule-type" class="wpmm-input" style="background:#fff;">
                    <option value="301"><?php esc_html_e('301 Permanent Redirect', 'wp-media-manager'); ?></option>
                    <option value="302"><?php esc_html_e('302 Temporary Redirect', 'wp-media-manager'); ?></option>
                    <option value="404"><?php esc_html_e('404 Not Found Page', 'wp-media-manager'); ?></option>
                </select>
            </div>
        </div>

        <div class="wpmm-modal__footer">
            <button type="button" class="wpmm-btn wpmm-btn--ghost" id="wpmm-rules-modal-cancel"><?php esc_html_e('Cancel', 'wp-media-manager'); ?></button>
            <button type="button" class="wpmm-btn wpmm-btn--primary" id="wpmm-rules-modal-save">
                <span class="wpmm-btn__label"><?php esc_html_e('Save Rule', 'wp-media-manager'); ?></span>
            </button>
        </div>

    </div>
</div>