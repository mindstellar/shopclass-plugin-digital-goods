# Changelog

## 2.0.1

### Changed

- Tested up to Shopclass 6.4.

## 2.0.0

First Shopclass release, rewritten from the Osclass plugin (1.1.0, 2013).

### Security

- **An upload could run as code on the server.** What was accepted was decided from
  `$_FILES[…]['type']` — a header the uploading client writes — and the file was then
  written under its own submitted name into `oc-content/uploads/digitalgoods/`, a
  directory the web server executes. A PHP script announced as `application/zip` was
  therefore uploaded, stored and executable. Uploads are now identified by reading the
  file with fileinfo, the extension has to be one the admin listed *and* has to agree with
  what the bytes say, and the stored name is random.
- **The download script served whatever path the caller named.** It built a filesystem
  path by joining the upload directory to the `file` query parameter and read that, using
  the database only to decide whether to bother. The parameter is now an opaque token used
  solely to find a row; the row decides the storage key, the name and the type.
- **There was no access control.** Anyone who knew a filename could download the file, and
  the file was reachable directly by URL in any case, which made the download script, its
  counter and any notion of who paid entirely optional. Who may download is now a setting,
  enforced in the one place a file can be obtained.

### Changed

- Files are stored through the storage layer, so a site with remote storage keeps them out
  of the web root and hands them over with a short-lived signed URL.
- The download address is a random token separate from the storage key, so the layout of
  the bucket can change without breaking a published link.
- Downloads are counted with an `UPDATE … i_downloads + 1` rather than read-modify-write,
  which lost a count whenever two downloads overlapped.
- Queries go through the query builder with bound values, replacing a model on the legacy
  DAO that assembled SQL by concatenation.
- Rows are removed by the listing's foreign key rather than by a cleanup pass, and the
  stored objects are deleted with the listing.
- The download endpoint is a registered route, so it runs inside the application instead of
  reaching for `oc-load.php` through a fixed number of parent directories.

### New

- Settings for who may download, how many files a listing may carry, the largest file, and
  the accepted types.
- An admin screen listing what has been attached and how often each file has been fetched.
- **Attaching files can be sold.** Where the billing subsystem is present (Shopclass 6.2.0
  and later), it registers as a per-listing upgrade a seller buys with credits, alongside
  bump and highlight. Off by default, and every entry point is guarded on the subsystem
  being there, so the plugin still runs — free — on 6.1.0.
- A warning on the settings screen when files are being stored somewhere the web server
  hands out directly.

### Fixed

- Uninstall removes the stored files as well as the table and the settings. It previously
  dropped the table, which was the only record of where the files were, stranding them.
