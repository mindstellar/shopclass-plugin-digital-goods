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
 * The upload control on the post and edit forms.
 *
 * `accept` and the stated limits are a courtesy to whoever is filling the form in. What
 * decides whether a file is taken is Uploads::inspect(), which reads the bytes.
 */

use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgExtensions = Uploads::allowedExtensions();
$dgAccept     = '.' . implode(',.', $dgExtensions);
?>
<div class="dg-upload">
    <label class="dg-upload__label" for="dg_files">
        <?php echo osc_esc_html(__('Downloadable files', 'digital-goods')); ?>
    </label>

    <input type="file" id="dg_files" name="dg_files[]" multiple
           accept="<?php echo osc_esc_html($dgAccept); ?>"/>

    <p class="dg-upload__hint">
        <?php echo osc_esc_html(sprintf(
            __('Up to %1$d file(s), %2$s each. Accepted: %3$s', 'digital-goods'),
            Plugin::maxFiles(),
            Uploads::formatSize(Plugin::maxSizeBytes()),
            implode(', ', $dgExtensions)
        )); ?>
    </p>
</div>
