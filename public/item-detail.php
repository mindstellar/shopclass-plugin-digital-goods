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
 * The attached files, on a listing.
 *
 * Every link points at the download route, never at the stored object: the route is the
 * only place the access rule is applied and the only place a download is counted.
 *
 * @var array<int,array<string,mixed>> $dgFiles
 * @var bool                           $dgAllowed
 */

use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
?>
<div class="dg-files">
    <h3 class="dg-files__title"><?php echo osc_esc_html(__('Files included', 'digital-goods')); ?></h3>

    <ul class="dg-files__list">
        <?php foreach ($dgFiles as $dgFile) { ?>
            <li class="dg-files__item">
                <?php if ($dgAllowed) { ?>
                    <a class="dg-files__link"
                       href="<?php echo osc_esc_html(osc_route_url('digital-goods-download', array('key' => $dgFile['s_token']))); ?>"
                       rel="nofollow">
                        <?php echo osc_esc_html($dgFile['s_name']); ?>
                    </a>
                <?php } else { ?>
                    <span class="dg-files__name"><?php echo osc_esc_html($dgFile['s_name']); ?></span>
                <?php } ?>
                <span class="dg-files__meta">
                    <?php echo osc_esc_html(Uploads::formatSize((int)$dgFile['i_size'])); ?>
                </span>
            </li>
        <?php } ?>
    </ul>

    <?php if (!$dgAllowed) { ?>
        <p class="dg-files__denied"><?php echo osc_esc_html(mindstellar\digitalgoods\Access::deniedMessage()); ?></p>
    <?php } ?>
</div>
