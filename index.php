<?php
/*
Plugin Name: Digital Goods
Plugin URI: https://github.com/mindstellar/shopclass-plugin-digital-goods
Description: Let sellers attach downloadable files to a listing, stored privately and served through a gated link.
Version: 2.4.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: digital-goods
Requires Shopclass: 7.0.0
Tested up to: 7.1
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-digital-goods/issues
Modes: classifieds, shop
*/

/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2013 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Api;
use mindstellar\digitalgoods\Billing;
use mindstellar\digitalgoods\Cart;
use mindstellar\digitalgoods\Downloads;
use mindstellar\digitalgoods\Files;
use mindstellar\digitalgoods\Plugin;
use mindstellar\digitalgoods\Receipt;
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

    $itemId = (int)$item['pk_i_id'];

    // Where the site charges for attachments, the listing needs the upgrade before any
    // file is taken. Checked here rather than only on the form, because the form is not
    // what decides — a post can arrive without it.
    if (!Billing::itemMayAttach($itemId)) {
        $anySent = false;
        foreach ($files['error'] as $error) {
            if ($error !== UPLOAD_ERR_NO_FILE) {
                $anySent = true;
                break;
            }
        }
        if ($anySent) {
            osc_add_flash_error_message(
                __('Attaching files to this listing has not been paid for yet.', 'digital-goods')
            );
        }

        return;
    }

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
 * Remove a listing's files when the listing goes.
 *
 * `delete_item` is the one hook every deletion path reaches, because the seller's delete
 * and the admin's both end at the model — but it runs after the listing row is gone. The
 * rows here therefore carry no foreign key onto it, so that they are still readable at
 * this point; see the note on the table definition. Files first, then the rows that name
 * them, so a failure part-way leaves something pointing at whatever is left.
 *
 * @param array<string,mixed>|int $item an id, or a row, depending on the caller
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
        Files::delete((int)$row['pk_i_id']);
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
 * The Downloads list, under Billing (under Settings while billing is switched off).
 *
 * @return void
 */
function dg_admin_menu()
{
    osc_add_admin_submenu_page(
        'billing',
        __('Downloads', 'digital-goods'),
        osc_admin_render_plugin_url(osc_plugin_folder(__FILE__) . 'admin/downloads.php'),
        'dg_downloads',
        'administrator'
    );
}

osc_register_plugin(osc_plugin_path(__FILE__), array('mindstellar\digitalgoods\Plugin', 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array('mindstellar\digitalgoods\Plugin', 'uninstall'));
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PREF_SECTION));
});
osc_register_settings_page(Plugin::PREF_SECTION, require __DIR__ . '/admin/settings.php');

// The download link is a route, so the file that serves it is reached through the
// application rather than by its path on disk.
osc_add_route(
    'digital-goods-download',
    'digital-goods/download/([a-f0-9]{32})',
    'digital-goods/download/{key}',
    osc_plugin_folder(__FILE__) . 'public/download.php'
);

// The buyer's page of everything bought that comes with files, in the account menu.
osc_add_route(
    'digital-goods-downloads',
    '^digital-goods/downloads/?(?:\?.*)?$',
    'digital-goods/downloads',
    osc_plugin_folder(__FILE__) . 'public/downloads.php',
    true,
    'custom',
    'custom',
    'Downloads'
);
osc_add_filter('user_menu_filter', array(Downloads::class, 'menu'));

// On a core with the API, listings carry ext.digital-goods.downloads for the caller.
Api::register();

// A cart of downloads only needs no address or delivery at checkout.
// Cores with the typed cart and receipt events get the typed listeners; older ones the filters.
osc_add_filter('cart_needs_shipping', array(Cart::class, class_exists('mindstellar\\billing\\event\\CartShipping') ? 'onShipping' : 'needsShipping'));

// A paid order's receipt lists the download links.
osc_add_filter('billing_receipt_details', array(Receipt::class, class_exists('mindstellar\\billing\\event\\ReceiptComposing') ? 'onDetails' : 'details'));

// Registered on init so the feature exists before anything reads the catalogue of what
// credits buy. A no-op where billing is not present.
osc_add_hook('init', array('mindstellar\digitalgoods\Billing', 'register'));
osc_add_hook('init', array('mindstellar\digitalgoods\Plugin', 'upgrade'));

osc_add_hook('admin_menu_init', 'dg_admin_menu');

osc_add_hook('item_form', 'dg_item_form');
osc_add_hook('item_edit', 'dg_item_form');
osc_add_hook('item_detail', 'dg_item_detail');

osc_add_hook('posted_item', 'dg_handle_upload');
osc_add_hook('edited_item', 'dg_handle_upload');
osc_add_hook('delete_item', 'dg_delete_item');
