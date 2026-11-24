### Changed
- Chapter images can now be up to 10 MiB. Kiption stores them as WebP of at most 2 MiB when `php-gd` is installed, and refuses images over 2 MiB when it is not. Self-hosters should set `upload_max_filesize = 12M` and `post_max_size = 14M`; INSTALL.md has the details.
- When chapter images are off, the editor now tells administrators to turn on Allow Images in Chapters under Settings, Stories, and tells authors that the administrator has not turned images on.
