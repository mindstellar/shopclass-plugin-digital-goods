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
 * Hands a file to a buyer, and is the only thing that can.
 *
 * Reached through a registered route rather than by its path, so it runs inside a booted
 * application instead of pulling oc-load.php in from a guessed number of parent
 * directories, the way the original did.
 *
 * The important difference is where the served bytes come from. The original built a
 * filesystem path by joining the upload directory to the `file` query parameter and read
 * whatever that pointed at, with the database consulted only to decide whether to bother.
 * Here the parameter is an opaque token and nothing else: the row it finds decides the
 * storage key, the name and the type, and a request naming something with no row simply
 * has no file.
 */

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Delivery;
use mindstellar\digitalgoods\Files;
use mindstellar\digitalgoods\Storage;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$dgToken = Params::getParamString('key');
$dgRow   = Files::byToken($dgToken);

if (!is_array($dgRow) || $dgRow === array()) {
    // The same answer for "no such file" and "not yours": telling them apart would let a
    // caller map which keys exist.
    header('HTTP/1.1 404 Not Found');
    osc_add_flash_error_message(__('That file is not available.', 'digital-goods'));
    osc_redirect_to(osc_base_url());
    exit;
}

if (!Access::allows((int)$dgRow['fk_i_item_id'])) {
    header('HTTP/1.1 403 Forbidden');
    osc_add_flash_error_message(Access::deniedMessage());

    // Back to the listing the file belongs to, so the message lands somewhere with
    // context. osc_item_url() reads the item in the view, so the row is fetched here and
    // passed to the builder that takes one.
    $dgItem = Item::newInstance()->findByPrimaryKey((int)$dgRow['fk_i_item_id']);
    osc_redirect_to(is_array($dgItem) && $dgItem !== array()
        ? osc_item_url_from_item($dgItem)
        : osc_base_url());
    exit;
}

$dgRange = (string)($_SERVER['HTTP_RANGE'] ?? '');
if (Delivery::counts($dgRange)) {
    Files::countDownload((int)$dgRow['pk_i_id']);
}

$dgKey = (string)$dgRow['s_key'];

// A file on this disk is streamed from here: the private folder is not served by the
// web server, so this route is the only way to it.
$dgPath = Storage::localPath($dgKey);
if ($dgPath !== '') {
    Delivery::sendFile($dgRow, $dgPath);
}

// A private bucket hands the file over itself, which keeps a large download off the web
// server. The URL is signed and short-lived.
$dgSigned = Storage::signedUrl($dgKey, (string)$dgRow['s_name']);
if ($dgSigned !== '') {
    osc_redirect_to($dgSigned);
    exit;
}

// A public bucket, or a signing failure: read through the adapter, so the object's
// address is never shown.
$dgBytes = Storage::readRemote($dgKey);
if ($dgBytes === false) {
    header('HTTP/1.1 404 Not Found');
    osc_add_flash_error_message(__('That file is not available.', 'digital-goods'));
    osc_redirect_to(osc_base_url());
    exit;
}

Delivery::sendBytes($dgRow, $dgBytes);
