<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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

use mindstellar\digitalgoods\Billing;
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgExtensions = Uploads::allowedExtensions();
$dgAccept     = '.' . implode(',.', $dgExtensions);

// On a new listing there is no id yet, so there is nothing to have bought against and the
// upload is offered; the post is checked either way. On an existing one the upgrade is
// what decides whether the field is worth showing.
$dgItemId  = function_exists('osc_item_id') ? (int)osc_item_id() : 0;
$dgMayAdd  = $dgItemId <= 0 || Billing::itemMayAttach($dgItemId);
$dgBuyUrl  = $dgItemId > 0 ? Billing::checkoutUrl($dgItemId) : '';
?>
<div class="dg-upload">
<?php if (!$dgMayAdd) { ?>
    <p class="dg-upload__locked">
        <?php echo osc_esc_html(__('Attaching downloadable files to this listing has not been paid for.', 'digital-goods')); ?>
        <?php if ($dgBuyUrl !== '') { ?>
            <a href="<?php echo osc_esc_html($dgBuyUrl); ?>"><?php echo osc_esc_html(__('Buy it with credits', 'digital-goods')); ?></a>
        <?php } ?>
    </p>
<?php } else { ?>
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
<?php } ?>
</div>
