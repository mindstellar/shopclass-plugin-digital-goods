<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * This file only draws. The POST is handled on init_admin in Plugin::handleAdminPost(),
 * before the panel has printed anything, so the redirect after saving still works.
 */

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Storage;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgRules = array(
    Access::REGISTERED => __('Signed-in visitors', 'digital-goods'),
    Access::ANYONE     => __('Anyone', 'digital-goods'),
    Access::SELLER     => __('The seller only', 'digital-goods'),
);
$dgCurrent = Access::rule();
$dgKnown   = array_keys(Uploads::typeMap());
?>
<h2 class="render-title"><?php echo osc_esc_html(__('Digital Goods', 'digital-goods')); ?></h2>

<?php if (!Storage::isPrivate()) { ?>
    <div class="flashmessage flashmessage-warning">
        <p><?php echo osc_esc_html(__(
            'Files are being stored in the uploads directory, which the web server hands out directly. The download link enforces who may download and counts each fetch, but it cannot stop someone who has the stored file\'s address from bypassing it. Configure remote storage with signed URLs under Settings → Storage for files that are genuinely private.',
            'digital-goods'
        )); ?></p>
    </div>
<?php } ?>

<form action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>" method="post" class="form-horizontal">
    <?php echo osc_csrf_token_form(); ?>
    <input type="hidden" name="page" value="plugins"/>
    <input type="hidden" name="action" value="renderplugin"/>
    <input type="hidden" name="file" value="<?php echo osc_esc_html(osc_plugin_folder(DG_PLUGIN_FILE) . 'admin/settings.php'); ?>"/>
    <input type="hidden" name="dg_action" value="save"/>

    <div class="form-row">
        <label class="form-label" for="dg-access"><?php echo osc_esc_html(__('Who can download', 'digital-goods')); ?></label>
        <div class="form-controls">
            <select id="dg-access" name="access">
                <?php foreach ($dgRules as $dgValue => $dgLabel) { ?>
                    <option value="<?php echo osc_esc_html($dgValue); ?>"
                        <?php echo $dgCurrent === $dgValue ? 'selected="selected"' : ''; ?>>
                        <?php echo osc_esc_html($dgLabel); ?>
                    </option>
                <?php } ?>
            </select>
            <span class="help-block"><?php echo osc_esc_html(__(
                'The seller always reaches their own files, whichever of these is chosen.',
                'digital-goods'
            )); ?></span>
        </div>
    </div>

    <div class="form-row">
        <label class="form-label" for="dg-max-files"><?php echo osc_esc_html(__('Files per listing', 'digital-goods')); ?></label>
        <div class="form-controls">
            <input type="number" id="dg-max-files" name="max_files" min="1" max="20" class="input-small"
                   value="<?php echo (int)Plugin::maxFiles(); ?>"/>
        </div>
    </div>

    <div class="form-row">
        <label class="form-label" for="dg-max-mb"><?php echo osc_esc_html(__('Largest file (MB)', 'digital-goods')); ?></label>
        <div class="form-controls">
            <input type="number" id="dg-max-mb" name="max_mb" min="1" max="2048" class="input-small"
                   value="<?php echo (int)osc_get_preference('max_mb', Plugin::PREF_SECTION) ?: Plugin::DEFAULT_MAX_MB; ?>"/>
            <span class="help-block"><?php echo osc_esc_html(sprintf(
                __('This server will not accept more than %s per file whatever is set here.', 'digital-goods'),
                Uploads::formatSize(Plugin::maxSizeBytes())
            )); ?></span>
        </div>
    </div>

    <div class="form-row">
        <label class="form-label" for="dg-ext"><?php echo osc_esc_html(__('Accepted file types', 'digital-goods')); ?></label>
        <div class="form-controls">
            <input type="text" id="dg-ext" name="allowed_ext" class="input-large"
                   value="<?php echo osc_esc_html(implode(',', Uploads::allowedExtensions())); ?>"/>
            <span class="help-block"><?php echo osc_esc_html(sprintf(
                __('Comma separated. An upload has to match its extension when the file itself is examined, so only these are available: %s', 'digital-goods'),
                implode(', ', $dgKnown)
            )); ?></span>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save', 'digital-goods')); ?></button>
    </div>
</form>
