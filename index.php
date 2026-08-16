<?php
/*
Plugin Name: Digital Goods
Plugin URI: https://github.com/mindstellar/shopclass-plugin-digital-goods
Description: Let sellers attach downloadable files to a listing, stored privately and served through a gated link.
Version: 2.0.0
Author: Mindstellar Community
Author URI: https://mindstellar.com
Short Name: digital-goods
Requires Shopclass: 6.1.0
Tested up to: 6.2
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-digital-goods/issues
*/

/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2013 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Files;
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Storage;
use mindstellar\digitalgoods\Uploads;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

define('DG_PLUGIN_FILE', __FILE__);

/**
 * The plugin's own classes, loaded on demand.
 *
 * A short PSR-4 loader for one namespace rather than a require list: core autoloads its
 * own tree, and a plugin adding to that has to bring its own.
 */
spl_autoload_register(static function ($class) {
    $prefix = 'mindstellar\\digitalgoods\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path     = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

/**
 * Whether this listing's category is one the plugin is switched on for.
 *
 * @param int|null $categoryId
 *
 * @return bool
 */
function dg_category_enabled($categoryId)
{
    return $categoryId !== null && $categoryId !== ''
        && osc_is_this_category('digital-goods', (int)$categoryId);
}

/**
 * Take the files submitted with a listing.
 *
 * @param array<string,mixed> $item
 *
 * @return void
 */
function dg_handle_upload($item)
{
    if (!is_array($item) || !isset($item['pk_i_id'], $item['fk_i_category_id'])) {
        return;
    }
    if (!dg_category_enabled($item['fk_i_category_id'])) {
        return;
    }

    $files = Params::getFiles('dg_files');
    if (!isset($files['error']) || !is_array($files['error'])) {
        return;
    }

    $itemId    = (int)$item['pk_i_id'];
    $existing  = count(Files::forItem($itemId));
    $allowance = Plugin::maxFiles() - $existing;
    $problems  = array();

    foreach ($files['error'] as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($allowance <= 0) {
            $problems[] = sprintf(
                __('Only %d file(s) can be attached to a listing.', 'digital-goods'),
                Plugin::maxFiles()
            );
            break;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $problems[] = __('One of the files did not upload.', 'digital-goods');
            continue;
        }

        $verdict = Uploads::inspect(
            (string)($files['tmp_name'][$index] ?? ''),
            (string)($files['name'][$index] ?? ''),
            (int)($files['size'][$index] ?? 0)
        );

        if (!$verdict['ok']) {
            $problems[] = $verdict['error'];
            continue;
        }

        $key = Storage::newKey($itemId, $verdict['extension']);
        if (!Storage::put((string)$files['tmp_name'][$index], $key, $verdict['type'])) {
            $problems[] = __('The file could not be stored.', 'digital-goods');
            continue;
        }

        Files::add(array(
            'fk_i_item_id'   => $itemId,
            's_name'         => $verdict['name'],
            's_token'        => bin2hex(random_bytes(16)),
            's_key'          => $key,
            's_content_type' => $verdict['type'],
            'i_size'         => (int)$files['size'][$index],
            'dt_date'        => date('Y-m-d H:i:s'),
        ));
        $allowance--;
    }

    foreach (array_unique($problems) as $problem) {
        osc_add_flash_error_message($problem);
    }
}

/**
 * Remove a listing's files from storage when the listing goes.
 *
 * The rows go with it through the foreign key's ON DELETE CASCADE, so only the stored
 * objects need chasing — and they have to be read before the listing is gone.
 *
 * @param array<string,mixed>|int $item
 *
 * @return void
 */
function dg_delete_item($item)
{
    $itemId = is_array($item) ? (int)($item['pk_i_id'] ?? 0) : (int)$item;
    if ($itemId <= 0) {
        return;
    }

    foreach (Files::forItem($itemId) as $row) {
        Storage::delete((string)$row['s_key']);
    }
}

/**
 * The upload field on the post and edit forms.
 *
 * @param int|null $categoryId
 *
 * @return void
 */
function dg_item_form($categoryId = null)
{
    if (!dg_category_enabled($categoryId)) {
        return;
    }

    require __DIR__ . '/public/item-form.php';
}

/**
 * The download list on a listing.
 *
 * @return void
 */
function dg_item_detail()
{
    if (!function_exists('osc_item_id') || !osc_item_id()) {
        return;
    }
    if (!dg_category_enabled(osc_item_category_id())) {
        return;
    }

    $dgFiles = Files::forItem((int)osc_item_id());
    if ($dgFiles === array()) {
        return;
    }

    $dgAllowed = Access::allows((int)osc_item_id());
    require __DIR__ . '/public/item-detail.php';
}

/**
 * Admin menu entries.
 *
 * @return void
 */
function dg_admin_menu()
{
    osc_add_admin_submenu_divider('plugins', __('Digital Goods', 'digital-goods'), 'dg_divider', 'administrator');
    osc_add_admin_submenu_page(
        'plugins',
        __('Digital Goods settings', 'digital-goods'),
        osc_admin_render_plugin_url(osc_plugin_folder(__FILE__) . 'admin/settings.php'),
        'dg_settings',
        'administrator'
    );
    osc_add_admin_submenu_page(
        'plugins',
        __('Digital Goods downloads', 'digital-goods'),
        osc_admin_render_plugin_url(osc_plugin_folder(__FILE__) . 'admin/downloads.php'),
        'dg_downloads',
        'administrator'
    );
}

/**
 * Send the plugin list's Configure link to the category picker, which is what decides
 * where the plugin applies.
 *
 * @return void
 */
function dg_configure()
{
    osc_plugin_configure_view(osc_plugin_path(__FILE__));
}

osc_register_plugin(osc_plugin_path(__FILE__), array('mindstellar\digitalgoods\Plugin', 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array('mindstellar\digitalgoods\Plugin', 'uninstall'));
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', 'dg_configure');

// The download link is a route, so the file that serves it is reached through the
// application rather than by its path on disk.
osc_add_route(
    'digital-goods-download',
    'digital-goods/download/([a-f0-9]{32})',
    'digital-goods/download/{key}',
    osc_plugin_folder(__FILE__) . 'public/download.php'
);

osc_add_hook('init_admin', array('mindstellar\digitalgoods\Plugin', 'handleAdminPost'));
osc_add_hook('admin_menu_init', 'dg_admin_menu');

osc_add_hook('item_form', 'dg_item_form');
osc_add_hook('item_edit', 'dg_item_form');
osc_add_hook('item_detail', 'dg_item_detail');

osc_add_hook('posted_item', 'dg_handle_upload');
osc_add_hook('edited_item', 'dg_handle_upload');
osc_add_hook('delete_item', 'dg_delete_item');
