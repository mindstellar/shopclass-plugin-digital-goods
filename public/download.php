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

Files::countDownload((int)$dgRow['pk_i_id']);

// A private object store can hand the file over itself, which keeps a large download off
// the web server entirely. Anything else is streamed from here.
$dgSigned = Storage::isPrivate() ? Storage::signedUrl($dgRow['s_key']) : '';
if ($dgSigned !== '') {
    osc_redirect_to($dgSigned);
    exit;
}

$dgBytes = Storage::read($dgRow['s_key']);
if ($dgBytes === false) {
    header('HTTP/1.1 404 Not Found');
    osc_add_flash_error_message(__('That file is not available.', 'digital-goods'));
    osc_redirect_to(osc_base_url());
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

// The name is quoted and stripped of anything that could end the header early; the type
// is the one recorded at upload from the file's own bytes, never from this request.
$dgName = str_replace(array('"', "\r", "\n"), '', (string)$dgRow['s_name']);

header('Content-Type: ' . (string)$dgRow['s_content_type']);
header('Content-Disposition: attachment; filename="' . $dgName . '"');
header('Content-Length: ' . strlen($dgBytes));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $dgBytes;
exit;
