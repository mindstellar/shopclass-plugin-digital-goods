<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The buyer's Downloads page: markup only, drawn inside the theme's account chrome.
 */

use mindstellar\digitalgoods\Downloads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

if (!osc_is_web_user_logged_in()) {
    osc_redirect_to(osc_user_login_url());
    exit;
}

$dgBought = Downloads::forUser((int)osc_logged_user_id());
?>
<div class="oe-panel">
    <h2 class="oe-panel-title"><?php echo osc_esc_html(__('Downloads', 'digital-goods')); ?></h2>
    <?php if ($dgBought === array()) { ?>
        <p class="oe-empty"><?php echo osc_esc_html(__('You have not bought any downloads yet.', 'digital-goods')); ?></p>
    <?php } else { ?>
        <?php foreach ($dgBought as $dgOrder) { ?>
            <p class="oe-muted">
                <?php echo osc_esc_html(sprintf(__('Order #%1$d, %2$s', 'digital-goods'), $dgOrder['order'], osc_format_date($dgOrder['date']))); ?>
            </p>
            <ul class="dg-files__list">
                <?php foreach ($dgOrder['files'] as $dgFile) { ?>
                    <li class="dg-files__item">
                        <a class="dg-files__link" href="<?php echo osc_esc_html($dgFile['url']); ?>" rel="nofollow">
                            <?php echo osc_esc_html($dgFile['name']); ?>
                        </a>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    <?php } ?>
</div>
