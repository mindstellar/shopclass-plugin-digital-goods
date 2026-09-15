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
 * What has been attached, and how often it has been fetched.
 */

use mindstellar\digitalgoods\Files;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgPage = max(1, Params::getParamInt('dgPage'));
$dgPer  = 50;
$dgRows = Files::report($dgPer, ($dgPage - 1) * $dgPer);
?>
<h2 class="render-title"><?php echo osc_esc_html(__('Digital Goods downloads', 'digital-goods')); ?></h2>

<?php if ($dgRows === array()) { ?>
    <p class="text"><?php echo osc_esc_html(__('No files have been attached yet.', 'digital-goods')); ?></p>
<?php } else { ?>
    <table class="table">
        <thead>
        <tr>
            <th><?php echo osc_esc_html(__('File', 'digital-goods')); ?></th>
            <th><?php echo osc_esc_html(__('Listing', 'digital-goods')); ?></th>
            <th><?php echo osc_esc_html(__('Size', 'digital-goods')); ?></th>
            <th><?php echo osc_esc_html(__('Downloads', 'digital-goods')); ?></th>
            <th><?php echo osc_esc_html(__('Added', 'digital-goods')); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($dgRows as $dgRow) { ?>
            <tr>
                <td><?php echo osc_esc_html($dgRow['s_name']); ?></td>
                <td>
                    <a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . (int)$dgRow['fk_i_item_id']); ?>">
                        <?php echo osc_esc_html($dgRow['s_title'] ?: ('#' . (int)$dgRow['fk_i_item_id'])); ?>
                    </a>
                </td>
                <td><?php echo osc_esc_html(Uploads::formatSize((int)$dgRow['i_size'])); ?></td>
                <td><?php echo (int)$dgRow['i_downloads']; ?></td>
                <td><?php echo osc_esc_html((string)$dgRow['dt_date']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if ($dgPage > 1 || count($dgRows) === $dgPer) { ?>
        <div class="dg-pager">
            <?php if ($dgPage > 1) { ?>
                <a href="<?php echo osc_esc_html(osc_admin_render_plugin_url(osc_plugin_folder(DG_PLUGIN_FILE) . 'admin/downloads.php') . '&dgPage=' . ($dgPage - 1)); ?>">
                    <?php echo osc_esc_html(__('Previous', 'digital-goods')); ?>
                </a>
            <?php } ?>
            <?php if (count($dgRows) === $dgPer) { ?>
                <a href="<?php echo osc_esc_html(osc_admin_render_plugin_url(osc_plugin_folder(DG_PLUGIN_FILE) . 'admin/downloads.php') . '&dgPage=' . ($dgPage + 1)); ?>">
                    <?php echo osc_esc_html(__('Next', 'digital-goods')); ?>
                </a>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
