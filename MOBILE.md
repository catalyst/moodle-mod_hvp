# Mobile H5P using mod_hvp
mod_hvp supports the [Moodle mobile app](https://moodle.com/solutions/moodle-app) using two methods. Please see the table below for the tradeoffs of each method.

|           | Web iframe | Bundled |
| --------- | ------------- | -------- |
| Rendering | Renders `embed.php` inside of iframe, essentially a wrapper of the embed page. | Uses a bootstrapper script to control and load the javascript directly inside of a blank iframe. |
| Reliability | Reliable - if it works on the web, it should work here.  | Best effort - Some content types may not work in this mode.  |
| Offline support | Not supported. | Mostly supported, see feature matrix below |

# Web iframe method
This is the original method of rendering included in `mod_hvp`. It simply renders the `embed.php` page inside of the mobile app, as if your site was just being access from a mobile browser.

This means that almost everything should work exactly the same as on web moodle.

# Bundled method
This is a new method of rendering that allows for the majority of h5ps to run **completely offline**.

`mod_hvp` will bundle the javascript, css, and assets needed by a h5p and package it in a way that the mobile app can execute it offline. This will happen on the initial load of the H5P by a user, or by a user downloading the course in the app. After this, the H5P will run even without a connection to the original moodle server.

Because this method is more complicated, not all features are supported, see the matrix below and usage section for caveats.

## Usage

1. Ensure your Moodle site is configured to allow the Mobile app.
2. Enable the configuration `mod_hvp/mobilehandler` to `Bundled render method`. This will enable the bundled mobile app handler site-wide. Individual course modules can choose to follow the site default, or specify their own which takes precedence.
3. Inside the mobile app, navigate to a course with a `hvp` activity in it and enter the activity. After the initial load, the app will cache the h5p activity.

Note the mobile app caches the activity. This means changes to the activity (e.g. editing the h5p) or changing the render method will not take effect until you have purged the apps caches. There are two ways to do this (from inside the mobile app):

1. Re-synchronise the course
2. Delete the downloaded course data

## Feature matrix
| Feature | Support Level | Notes |
| ------- | ------------- | ----- |
| Offline completion | ✅ Supported  | Completion will be stored and re-synced when the device comes online |
| Cached audio | ✅ Supported | |
| Cached video | ✅ Supported | |
| Cached images | 🆗 Mostly supported | Images in CSS that are not directly on an elements style attribute are not cached |
| Cached fonts and icons | 🆗 Mostly supported | Only `.ttf` is supported, as other fonts are not compatible on IOS. Note the default font differs between operating system (e.g. Roboto on Android, and SF Pro on IOS) |
| Fullscreen | ❌ Not supported | The app has no nice way to exit fullscreen (since there is no physical keyboard), so all H5Ps have fullscreen mode disabled |
| Microphone | ❌ Not supported | Mobile microphone access requires a different set of APIs than H5Ps are built with, so microphone access is unlikely to work. |

| Content type | Support Level | Notes |
| ------- | ------------- | ----- |
| Course presentation | ✅ Supported |
| Quiz | ✅ Supported |
| Accordion | ✅ Supported |
| Interactive video | ✅ Supported | `.webm` videos are not playable on IOS due to IOS missing the required codecs. Note large videos can take minutes to download, and the user will need to way for the entire video to download before it will begin playing (partial/streaming playback is not supported). |
| Image slider | ✅ Supported |
| Image hotspots | ✅ Supported | < `1.10.7` is not supported due to issues with fullscreen being disabled |
| Document export | ❌ Not supported | |
| Speak the words | ❌ Not supported | Microphone access not supported |

Most content types should work, but after testing they should be listed here as either supported, or unsupported.

## Other notes
- Changing orientation of the device after the h5p loads has mixed support among the content types. Most H5P content types are built with desktop in mind which does not support orientation changing.
- After the initial load of a H5P activity, the user will need to re-synchronise the course to see any changes that are made.

## For Developers

Developers wanting to make updates to or work with the bundled hvp method, please read the below.

### Caching
Be aware of what caches may exist so ensure changes made are propogated properly:
- For mustache template changes, you must purge the sites template cache.
- For JS or content type changes, the user must re-synchronise the course inside the app.
- You may also need to delete the downloaded data for the course inside the app.

### Lang strings
Lang strings are cached by the app based on the plugin version. You MUST increase the version every time the lang strings change. Simply purging the app/moodle caches will not load them in.

Additionally if you are adding a new lang string, you must add it to the list in `db/mobile.php` before upgrading the version 

### Be mindful of global JS.
The app is basically a web browser, so you have the power to access any of the JS variables exposed in the window, etc. However with this comes the responsibility that you MUST cleanup after yourself. E.g. any intervals must be cleared, otherwise they will persist until the entire app is restarted.

There is currently a function `setHVpInterval` in the bootstrap code that will watch for the iframe being removed (indicating user navigated away), and automatically clear intervals. You should use that as much as possible. 

### File URLs format is not consistent
All environments/devices use different file urls. Some examples: `file://...` ,`http://localhost/__appdata__/...`

Be wary of relying on a specific format for file extension to see if an app is a filesystem file, or a moodle file.

### Debugging
You can get the mobile app using the bundled method to forward javascript logs back to the sites error log using the setting `mod_hvp/mobiledebugging`. Note this value is included in the bundles javascript so the user must reload their course cache for the new value to take effect.

### CRON handler
Any changes to the mobile (not web) CRON handler require the user to log out and back in again.

### Unique ids
Whenever querying elements inside of the template, always use a unique identifier. This avoid a race condition where the previous activities javascript may still be running while the new activity loads, causing it to behave unexpectedly.
