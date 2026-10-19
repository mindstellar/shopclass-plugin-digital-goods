<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Billing;
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Storage;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * The settings page declaration. Core draws the form, checks CSRF and the admin's
 * capability, and stores each field under the "digital_goods" preference section.
 */

$d = 'digital-goods';

$clamp = static fn (int $min, int $max): Closure => static fn ($value): int => max($min, min($max, (int)$value));

$notices = static function () use ($d): void {
    if (!Storage::isPrivate()) {
        echo '<div class="flashmessage flashmessage-warning"><p>' . osc_esc_html(__(
            'Files are in a public bucket. For private files, turn on signed URLs under Settings → Storage.',
            $d
        )) . '</p></div>';
    }
    if (Plugin::categoryIds() === array()) {
        echo '<div class="flashmessage flashmessage-warning"><p>' . osc_esc_html(__(
            'Sellers cannot attach files yet. Choose at least one category.',
            $d
        )) . '</p></div>';
    }
};

$tree = static function () use ($d): void {
    osc_admin_tree_picker(array(
        'id'         => 'dg_category_tree',
        'intro'      => __('Categories that take files', $d),
        'categories' => Category::getInstance()->toTreeAll(),
        'selected'   => Plugin::categoryIds(),
    ));
    echo '<script>document.addEventListener("DOMContentLoaded", function () {'
        . 'if (typeof oscTreeview === "function") {'
        . 'oscTreeview(document.getElementById("dg_category_tree"), {collapsed: true});}});</script>';
};

$who = array(
    Access::REGISTERED => __('Signed-in visitors', $d),
    Access::ANYONE     => __('Anyone', $d),
);
// The buyer rule needs a core that records order lines.
if (Access::buyerRuleWorks()) {
    $who[Access::BUYER] = __('Anyone who bought it, and the seller', $d);
}
$who[Access::SELLER] = __('The seller only', $d);

$groups = array(
    array(
        'title'  => __('Categories', $d),
        'fields' => array(
            array('type' => 'custom', 'name' => 'notices', 'row' => false, 'render' => $notices),
            array('type' => 'custom', 'name' => 'category_tree', 'row' => false, 'render' => $tree),
            // Carries the ticked categories into the save. They live in core's plugin
            // category table; the copy stored here lets the save report the change.
            array(
                'type'    => 'hidden',
                'name'    => 'category_ids',
                'default' => '',
                'collect' => static function (): string {
                    $ids = array_values(array_unique(array_filter(array_map('intval', Params::getParamArray('categories')))));
                    sort($ids);

                    return implode(',', $ids);
                },
                'persist' => static function ($value): string {
                    $ids = $value === '' ? array() : array_map('intval', explode(',', (string)$value));
                    $now = Plugin::categoryIds();
                    sort($now);
                    if ($ids !== $now) {
                        Plugins::cleanCategoryFromPlugin(Plugin::SHORT_NAME);
                        Plugins::addToCategoryPlugin($ids, Plugin::SHORT_NAME);
                    }

                    return (string)$value;
                },
            ),
        ),
    ),
    array(
        'title'  => __('Files', $d),
        'fields' => array(
            array(
                'type'     => 'select',
                'name'     => 'access',
                'label'    => __('Who can download', $d),
                'help'     => __('Sellers can always download their own files.', $d),
                'options'  => $who,
                'default'  => Access::REGISTERED,
                'sanitize' => static fn ($v) => in_array($v, Access::rules(), true) ? $v : Access::REGISTERED,
            ),
            array(
                'type'     => 'number',
                'name'     => 'max_files',
                'label'    => __('Files per listing', $d),
                'help'     => __('From 1 to 20.', $d),
                'default'  => Plugin::DEFAULT_MAX_FILES,
                'attrs'    => array('min' => 1, 'max' => 20),
                'sanitize' => $clamp(1, 20),
            ),
            array(
                'type'     => 'number',
                'name'     => 'max_mb',
                'label'    => __('Largest file (MB)', $d),
                'help'     => sprintf(
                    __('This server takes up to %s per file.', $d),
                    Uploads::formatSize(Plugin::maxSizeBytes())
                ),
                'default'  => Plugin::DEFAULT_MAX_MB,
                'attrs'    => array('min' => 1, 'max' => 2048),
                'sanitize' => $clamp(1, 2048),
            ),
            array(
                'type'     => 'text',
                'name'     => 'allowed_ext',
                'label'    => __('File types', $d),
                'help'     => sprintf(
                    __('Separate with commas. You can use: %s.', $d),
                    implode(', ', array_keys(Uploads::typeMap()))
                ),
                'default'  => Plugin::DEFAULT_EXTENSIONS,
                // Only types whose file contents the plugin can check.
                'sanitize' => static function ($value): string {
                    $extensions = array();
                    foreach (explode(',', (string)$value) as $ext) {
                        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(trim($ext)));
                        if ($ext !== '' && isset(Uploads::typeMap()[$ext])) {
                            $extensions[$ext] = true;
                        }
                    }

                    return $extensions === array()
                        ? Plugin::DEFAULT_EXTENSIONS
                        : implode(',', array_keys($extensions));
                },
            ),
        ),
    ),
);

if (Billing::available()) {
    $groups[] = array(
        'title'  => __('Charging', $d),
        'fields' => array(
            array(
                'type'  => 'checkbox',
                'name'  => 'require_purchase',
                'label' => __('Charge sellers to attach files', $d),
                'help'  => __('Sellers pay credits per listing. Files already attached stay.', $d),
            ),
            array(
                'type'     => 'number',
                'name'     => 'price_credits',
                'label'    => __('Credits per listing', $d),
                'default'  => 5,
                'attrs'    => array('min' => 1),
                'sanitize' => static fn ($v): int => max(1, (int)$v ?: 5),
            ),
        ),
    );
}

return array(
    'title'  => __('Digital Goods', $d),
    'menu'   => 'settings',
    'group'  => 'shop',
    'groups' => $groups,
);
