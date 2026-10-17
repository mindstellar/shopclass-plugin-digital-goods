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
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgPage = max(1, Params::getParamInt('dgPage'));
$dgPer  = 50;
$dgRows = Files::report($dgPer, ($dgPage - 1) * $dgPer);
$dgUrl  = static fn (int $page): string => osc_admin_render_plugin_url(osc_plugin_folder(DG_PLUGIN_FILE) . 'admin/downloads.php')
    . ($page > 1 ? '&dgPage=' . $page : '');
$dgCols = array(
    __('File', 'digital-goods'),
    __('Listing', 'digital-goods'),
    __('Size', 'digital-goods'),
    __('Downloads', 'digital-goods'),
    __('Added', 'digital-goods'),
);

osc_admin_page_head(__('Downloads', 'digital-goods'), array(
    array(
        'icon'  => 'bi-gear-fill',
        'url'   => osc_settings_page_url(Plugin::PREF_SECTION),
        'title' => __('Settings', 'digital-goods'),
    ),
));
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <?php foreach ($dgCols as $dgCol) { ?>
                <th><?php echo osc_esc_html($dgCol); ?></th>
            <?php } ?>
        </tr>
        </thead>
        <tbody>
        <?php if ($dgRows === array()) {
            osc_admin_table_empty(count($dgCols), array(
                'icon'  => 'bi-download',
                'title' => __('No files yet', 'digital-goods'),
                'text'  => __('Files sellers attach to listings show here.', 'digital-goods'),
            ));
        } ?>
        <?php foreach ($dgRows as $dgRow) { ?>
            <tr>
                <td><?php echo osc_esc_html($dgRow['s_name']); ?></td>
                <td data-col-name="<?php echo osc_esc_html($dgCols[1]); ?>">
                    <a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . (int)$dgRow['fk_i_item_id']); ?>">
                        <?php echo osc_esc_html($dgRow['s_title'] ?: ('#' . (int)$dgRow['fk_i_item_id'])); ?>
                    </a>
                </td>
                <td data-col-name="<?php echo osc_esc_html($dgCols[2]); ?>"><?php echo osc_esc_html(Uploads::formatSize((int)$dgRow['i_size'])); ?></td>
                <td data-col-name="<?php echo osc_esc_html($dgCols[3]); ?>"><?php echo (int)$dgRow['i_downloads']; ?></td>
                <td data-col-name="<?php echo osc_esc_html($dgCols[4]); ?>"><?php echo osc_admin_when((string)$dgRow['dt_date']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>

<?php if ($dgPage > 1 || count($dgRows) === $dgPer) { ?>
    <nav aria-label="<?php echo osc_esc_html(__('Pages', 'digital-goods')); ?>">
        <ul class="pagination">
            <?php if ($dgPage > 1) { ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo osc_esc_html($dgUrl($dgPage - 1)); ?>"><?php echo osc_esc_html(__('Previous', 'digital-goods')); ?></a>
                </li>
            <?php } ?>
            <?php if (count($dgRows) === $dgPer) { ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo osc_esc_html($dgUrl($dgPage + 1)); ?>"><?php echo osc_esc_html(__('Next', 'digital-goods')); ?></a>
                </li>
            <?php } ?>
        </ul>
    </nav>
<?php } ?>
