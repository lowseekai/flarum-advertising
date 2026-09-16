# Flarum Advertising

User-managed advertising slots for Flarum 2.x.

## Features

- Sidebar and top advertising slots.
- User submission flow with image upload.
- Administrator review queue.
- Points are charged only when an administrator approves an advertisement.
- Approved advertisements automatically expire after their purchased duration.
- Hourly expiry command registered through Flarum Scheduler.
- Simplified Chinese and English translations.

## Requirements

- Flarum `2.0.0-rc.8` or a compatible 2.x release.
- `ramon/point-system` from the `lowseekai/point-system` repository.

## Development

```sh
composer install
cd js
npm install
npm run build
```

The extension exposes the `lowseekai-advertising.submit` and
`lowseekai-advertising.manage` permissions. Grant them from the Flarum
Administration panel after enabling the extension.
