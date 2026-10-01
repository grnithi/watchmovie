# What To Watch: Push Notifications and Improvement Ideas

Notes for later. Nothing here is implemented yet.

Current stack: PHP front end (`index.php`, `assets/`), `api/feed.php` with no database, a minimal service worker (`sw.js`), and deploy through GitHub Actions rsync.

## 1. Push notifications for PWA users

**Verdict:** possible, and it fits the current stack.

### How it works (Web Push)
1. The user taps "Notify me" and the browser asks for permission.
2. The browser creates a push subscription (an endpoint URL plus keys), and the site stores it.
3. When something is worth announcing, the server sends a message to each stored endpoint, signed with VAPID keys.
4. The service worker receives it and shows the notification.
5. Tapping the notification opens the site, for example `/india?mode=ott`.

### What it needs
- **Subscription storage.** There is no database. A SQLite or JSON file is enough for a small audience. Store the region and language preferences with each subscription so messages can be targeted.
- **A PHP library.** `minishlink/web-push` handles VAPID signing and encryption. It needs Composer and the `openssl` and `curl` extensions, so check the host supports them.
- **A trigger.** A daily cron job on the host, or a scheduled GitHub Actions run, that checks TMDB or Watchmode for new releases matching each user's preferences.
- **Service worker handlers.** Add `push` and `notificationclick` listeners to `sw.js`, plus a subscribe button in the page.

### Platform caveats
- **Android (Chrome):** works well, even when the PWA is closed.
- **iPhone:** needs iOS 16.4 or later, and only works after the app is added to the Home Screen, not from a normal Safari tab. The iOS install guide already covers this.
- **Permission prompt:** ask only after a user action, such as tapping "Notify me about Tamil OTT releases". Never ask on page load.
- **Frequency:** at most one a day, or a few a week. Let users choose what they get.

### Good notification types
- Weekly digest: "New this Friday on Netflix/Prime: ..."
- New release in the user's languages
- Reminder for a title the user saved

## 2. Other improvement ideas

Roughly ordered by value for effort.

1. **Watchlist and "Remind me".** Users bookmark a title and get notified when it hits OTT or theatres. This is what makes push useful. It can start in `localStorage` and sync later.
2. **Personalization.** Remember region, languages and platforms (Netflix, Prime, Hotstar and so on), and add a "My platforms" filter.
3. **Title detail view.** Trailer, cast, runtime, rating, a "where to watch" link per platform, and a share button. Details are already cached.
4. **Sharing.** `lib/og.php` is a good base. Add per-title share links with a good preview card for WhatsApp.
5. **Discovery.** Search, sort by rating, date or popularity, an "Out this week" calendar view, and a "Coming soon" countdown.
6. **Weekly email or WhatsApp digest.** Reaches people who have not installed the PWA. Email is the easier one.
7. **Polish.** Skeleton loaders, offline support for the last-seen list, Lighthouse and SEO work (including indexable per-title pages), and a dark/light toggle.
8. **Usage insight.** `lib/traffic.php` already exists. Track which titles get clicked to decide what to feature.

## 3. Suggested build order

Build the watchlist and push together, because each makes the other more useful.

1. Per-user preferences and a watchlist in `localStorage`.
2. Subscribe button and `push` handler in the service worker.
3. A small PHP endpoint that stores subscriptions.
4. A cron job that sends the weekly digest and per-title reminders.

## 4. Questions to answer before starting
- Can the host run Composer and cron jobs?
- What audience size should this plan for (hundreds, or thousands of subscribers)?
- Which notification types matter most: weekly digest, language-based, or saved-title reminders?
