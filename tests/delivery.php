<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * How a local file is sent: in chunks rather than read whole, with the right length and
 * range, safe headers, and the web server's own sendfile only when it is set up.
 *
 * Usage:  php tests/delivery.php
 */

use mindstellar\digitalgoods\Delivery;
use mindstellar\digitalgoods\Storage;

define('ABS_PATH', dirname(__DIR__) . '/');
define('CONTENT_PATH', sys_get_temp_dir() . '/dg_delivery_' . getmypid() . '/');
define('UPLOADS_PATH', CONTENT_PATH . 'uploads/');

require_once ABS_PATH . 'src/Storage.php';
require_once ABS_PATH . 'src/Uploads.php';
require_once ABS_PATH . 'src/Delivery.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('Range header');

pin('no header sends the whole file', null, Delivery::parseRange('', 1000));
pin('a closed range', array(100, 199), Delivery::parseRange('bytes=100-199', 1000));
pin('an open range runs to the end', array(500, 999), Delivery::parseRange('bytes=500-', 1000));
pin('a suffix range is the last n bytes', array(900, 999), Delivery::parseRange('bytes=-100', 1000));
pin('a suffix longer than the file is the whole file', array(0, 999), Delivery::parseRange('bytes=-5000', 1000));
pin('an end past the file is clipped', array(900, 999), Delivery::parseRange('bytes=900-5000', 1000));
pin('a start past the file cannot be satisfied', false, Delivery::parseRange('bytes=1000-', 1000));
pin('nor a start after the end', false, Delivery::parseRange('bytes=500-100', 1000));
pin('nor a zero-length suffix', false, Delivery::parseRange('bytes=-0', 1000));
pin('several ranges are ignored', null, Delivery::parseRange('bytes=0-1,5-6', 1000));
pin('another unit is ignored', null, Delivery::parseRange('items=0-1', 1000));
pin('a bare dash is ignored', null, Delivery::parseRange('bytes=-', 1000));

harness_section('Content-Disposition');

pin('a plain name', 'attachment; filename="Guide.pdf"; filename*=UTF-8\'\'Guide.pdf', Delivery::disposition('Guide.pdf'));
$evil = Delivery::disposition("a\"b\r\nSet-Cookie: x=1;.pdf");
check('quotes, line breaks and semicolons cannot end the header',
    strpos($evil, "\r") === false && strpos($evil, "\n") === false && substr_count($evil, '"') === 2, $evil);
$unicode = Delivery::disposition('Résumé.pdf');
check('a non-ASCII name keeps its UTF-8 form', strpos($unicode, "filename*=UTF-8''R%C3%A9sum%C3%A9.pdf") !== false, $unicode);
check('with an ASCII fallback', strpos($unicode, 'filename="R__sum__.pdf"') !== false, $unicode);
check('a path in the name is dropped', strpos(Delivery::disposition('../../etc/passwd'), 'filename="passwd"') !== false);

harness_section('headers');

$row  = array('s_key' => 'digital-goods/1/' . str_repeat('a', 32) . '.pdf', 's_name' => 'Guide.pdf', 's_content_type' => 'application/pdf');
$base = Delivery::baseHeaders($row);
check('nosniff is sent', in_array('X-Content-Type-Options: nosniff', $base, true));
check('the response is never stored', in_array('Cache-Control: private, no-store', $base, true));
check('the stored type is used', in_array('Content-Type: application/pdf', $base, true));
$odd = Delivery::baseHeaders(array('s_name' => 'x', 's_content_type' => "text/html\r\nX-Evil: 1"));
check('a malformed type falls back to octet-stream', in_array('Content-Type: application/octet-stream', $odd, true));

harness_section('plan');

$path = Storage::privateRoot() . $row['s_key'];
$full = Delivery::plan($row, $path, 1000, '');
pin('a whole file is a 200', 200, $full['status']);
check('with its exact length', in_array('Content-Length: 1000', $full['headers'], true));
check('and says ranges are accepted', in_array('Accept-Ranges: bytes', $full['headers'], true));
pin('the whole file is streamed', array(0, 1000), array($full['first'], $full['length']));

$part = Delivery::plan($row, $path, 1000, 'bytes=100-199');
pin('a range is a 206', 206, $part['status']);
check('with the span it covers', in_array('Content-Range: bytes 100-199/1000', $part['headers'], true));
check('and that span\'s length', in_array('Content-Length: 100', $part['headers'], true));
pin('only that span is streamed', array(100, 100), array($part['first'], $part['length']));

$bad = Delivery::plan($row, $path, 1000, 'bytes=2000-');
pin('an unsatisfiable range is a 416', 416, $bad['status']);
check('naming the size', in_array('Content-Range: bytes */1000', $bad['headers'], true));
pin('with no body', 0, $bad['length']);

harness_section('download count');

pin('a plain request counts', true, Delivery::counts(''));
pin('a range from the first byte counts', true, Delivery::counts('bytes=0-'));
pin('a resumed request does not', false, Delivery::counts('bytes=5000-'));

harness_section('streaming');

$file = tempnam(sys_get_temp_dir(), 'dg_stream_');
$data = random_bytes(Delivery::CHUNK * 2 + 123);
file_put_contents($file, $data);
$in  = fopen($file, 'rb');
$out = fopen('php://memory', 'w+b');
pin('a whole file is copied byte for byte', strlen($data), Delivery::copySpan($in, $out, 0, strlen($data)));
rewind($out);
pin('and matches', true, stream_get_contents($out) === $data);
fclose($out);

$out = fopen('php://memory', 'w+b');
Delivery::copySpan($in, $out, Delivery::CHUNK - 10, 20);
rewind($out);
pin('a span across a chunk boundary is exact', substr($data, Delivery::CHUNK - 10, 20), stream_get_contents($out));
fclose($in);
fclose($out);
unlink($file);

$src = file_get_contents(ABS_PATH . 'src/Delivery.php') . file_get_contents(ABS_PATH . 'public/download.php');
check('nothing reads a whole local file into memory', !preg_match('/file_get_contents|readfile\(|->get\(/', $src));

harness_section('fast paths');

pin('none unless configured', '', Delivery::fastPathHeader($row['s_key'], $path));
define('DG_ACCEL_REDIRECT', '/dg-private/');
pin('nginx gets an internal redirect under the configured prefix',
    'X-Accel-Redirect: /dg-private/1/' . str_repeat('a', 32) . '.pdf',
    Delivery::fastPathHeader($row['s_key'], $path));
pin('not for a file still in the old folder', '', Delivery::fastPathHeader($row['s_key'], UPLOADS_PATH . $row['s_key']));
pin('nor for a key of the wrong shape', '', Delivery::fastPathHeader('digital-goods/1/../../x', $path));
$fast = Delivery::plan($row, $path, 1000, 'bytes=0-10');
pin('the web server then sends the body', 0, $fast['length']);
check('and sets the length itself', !preg_grep('/^Content-Length/', $fast['headers']));
check('the safe headers still go out', in_array('X-Content-Type-Options: nosniff', $fast['headers'], true));

exit(harness_result());
