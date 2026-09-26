# Changelog

All notable changes to `messages-for-laravel` will be documented in this file.

## Unreleased

### Security

- Private attachments (the default) are now stored on a non-public disk: new
  `messages.media.private_disk` (`MESSAGES_MEDIA_PRIVATE_DISK`, default `local`) holds private
  originals and their variants whenever `messages.media.disk` is unset. They used to land on
  media-library's default `public` disk — served under `/storage` once `storage:link` runs — so a
  DM attachment was reachable without its signed URL, despite being documented as reachable only
  through the signed streaming route. The signed stream route serves them from the private disk.
