<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

/**
 * Sends a local file to the browser without loading it into memory.
 *
 * Optional fast paths, set in config.php: DG_ACCEL_REDIRECT (an nginx internal location
 * for the private folder) or DG_XSENDFILE (Apache mod_xsendfile). Without either, PHP
 * streams the file and answers single byte ranges.
 */
class Delivery
{
    /** Bytes read per chunk while streaming. */
    public const CHUNK = 1048576;

    /**
     * The byte range a Range header asks for.
     *
     * Only one range is served; a list of ranges, another unit or a malformed header is
     * ignored, and the whole file is sent.
     *
     * @param string $header the Range request header, '' when absent
     * @param int    $size   the file size in bytes
     *
     * @return array{0:int,1:int}|false|null [first, last] byte, false when it cannot be
     *                                       satisfied (416), null to send the whole file
     */
    public static function parseRange($header, $size)
    {
        $header = trim((string)$header);
        $size   = (int)$size;
        if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            // The last n bytes.
            $length = (int)$m[2];
            if ($length === 0 || $size === 0) {
                return false;
            }

            return array(max(0, $size - $length), $size - 1);
        }

        $first = (int)$m[1];
        $last  = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
        if ($first >= $size || $first > $last) {
            return false;
        }

        return array($first, $last);
    }

    /**
     * A Content-Disposition value that cannot break the header and keeps a non-ASCII name.
     *
     * @param string $name the label stored at upload
     *
     * @return string
     */
    public static function disposition($name)
    {
        $name = Uploads::safeLabel($name);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace(array('"', '\\', ';'), '_', $ascii);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * Headers every download carries, whichever way the bytes are sent.
     *
     * @param array<string,mixed> $row the file's row
     *
     * @return string[]
     */
    public static function baseHeaders(array $row)
    {
        $type = (string)($row['s_content_type'] ?? '');
        if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD', $type)) {
            $type = 'application/octet-stream';
        }

        return array(
            'Content-Type: ' . $type,
            'Content-Disposition: ' . self::disposition((string)($row['s_name'] ?? '')),
            'X-Content-Type-Options: nosniff',
            'Cache-Control: private, no-store',
        );
    }

    /**
     * The fast-path header for a file in the private folder, or '' when none is set up.
     *
     * @param string $key
     * @param string $path the file's absolute path
     *
     * @return string
     */
    public static function fastPathHeader($key, $path)
    {
        // Only files in the private folder: the location below maps onto it alone.
        if (!Storage::isKey($key) || $path !== Storage::privateRoot() . $key) {
            return '';
        }

        if (defined('DG_ACCEL_REDIRECT') && is_string(DG_ACCEL_REDIRECT) && DG_ACCEL_REDIRECT !== '') {
            return 'X-Accel-Redirect: ' . rtrim(DG_ACCEL_REDIRECT, '/') . '/' . substr($key, strlen(Storage::PREFIX));
        }
        if (defined('DG_XSENDFILE') && DG_XSENDFILE) {
            return 'X-Sendfile: ' . $path;
        }

        return '';
    }

    /**
     * Everything to send for a local file: status, headers and the byte span to stream.
     *
     * @param array<string,mixed> $row
     * @param string              $path
     * @param int                 $size
     * @param string              $range the Range request header
     *
     * @return array{status:int,headers:string[],first:int,length:int}
     *         length 0 means no body from PHP (fast path, or a 416)
     */
    public static function plan(array $row, $path, $size, $range)
    {
        $headers = self::baseHeaders($row);

        $fast = self::fastPathHeader((string)($row['s_key'] ?? ''), $path);
        if ($fast !== '') {
            // The web server sends the body, answers ranges and sets the length.
            $headers[] = $fast;

            return array('status' => 200, 'headers' => $headers, 'first' => 0, 'length' => 0);
        }

        $headers[] = 'Accept-Ranges: bytes';
        $span = self::parseRange($range, $size);

        if ($span === false) {
            $headers[] = 'Content-Range: bytes */' . $size;

            return array('status' => 416, 'headers' => $headers, 'first' => 0, 'length' => 0);
        }

        if ($span === null) {
            $headers[] = 'Content-Length: ' . $size;

            return array('status' => 200, 'headers' => $headers, 'first' => 0, 'length' => $size);
        }

        $length    = $span[1] - $span[0] + 1;
        $headers[] = 'Content-Range: bytes ' . $span[0] . '-' . $span[1] . '/' . $size;
        $headers[] = 'Content-Length: ' . $length;

        return array('status' => 206, 'headers' => $headers, 'first' => $span[0], 'length' => $length);
    }

    /**
     * Whether a request counts as a download. A resumed or partial fetch that does not
     * start at the first byte is the same download continuing.
     *
     * @param string $range
     *
     * @return bool
     */
    public static function counts($range)
    {
        $range = trim((string)$range);

        return $range === '' || preg_match('/^bytes=0-/', $range) === 1;
    }

    /**
     * Copy a span of a file to an output stream in chunks.
     *
     * @param resource $in
     * @param resource $out
     * @param int      $first
     * @param int      $length
     *
     * @return int bytes written
     */
    public static function copySpan($in, $out, $first, $length)
    {
        if ($first > 0 && fseek($in, $first) !== 0) {
            return 0;
        }

        $sent = 0;
        while ($sent < $length && !feof($in)) {
            $chunk = fread($in, (int)min(self::CHUNK, $length - $sent));
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
            $sent += strlen($chunk);
            if (connection_aborted()) {
                break;
            }
            flush();
        }

        return $sent;
    }

    /**
     * Send a local file and end the request.
     *
     * @param array<string,mixed> $row
     * @param string              $path
     *
     * @return void
     */
    public static function sendFile(array $row, $path)
    {
        $size = (int)filesize($path);
        $plan = self::plan($row, $path, $size, (string)($_SERVER['HTTP_RANGE'] ?? ''));

        self::endBuffers();
        http_response_code($plan['status']);
        foreach ($plan['headers'] as $header) {
            header($header);
        }

        if ($plan['length'] > 0) {
            $in  = fopen($path, 'rb');
            $out = fopen('php://output', 'wb');
            if ($in !== false && $out !== false) {
                @set_time_limit(0);
                self::copySpan($in, $out, $plan['first'], $plan['length']);
            }
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }

        exit;
    }

    /**
     * Send bytes already in memory (a public bucket) and end the request.
     *
     * @param array<string,mixed> $row
     * @param string              $bytes
     *
     * @return void
     */
    public static function sendBytes(array $row, $bytes)
    {
        self::endBuffers();
        foreach (self::baseHeaders($row) as $header) {
            header($header);
        }
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    /**
     * Drop any output buffering and compression so the file goes out as it is read.
     *
     * @return void
     */
    private static function endBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
    }
}
