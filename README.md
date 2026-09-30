# What To Watch

OTT and theatrical release guide (movies and TV shows) for Tamil, Telugu, Hindi, Malayalam, Kannada and English, for the USA and India.

- Front-end: `index.php`, `assets/`
- Backend: `api/feed.php` (PHP, no database)
- Routes: `/usa` and `/india` (via `.htaccess`), e.g. `/india?mode=ott&type=tv&range=week`

## Local setup
1. `cp secrets.example.php secrets.php` and add your API keys.
2. Serve with Apache/PHP (or `php -S 127.0.0.1:8099`). Caching is disabled automatically on localhost.

## Deploy
Pushing to `main` runs `.github/workflows/deploy.yml`, which generates `secrets.php` from GitHub secrets
(`TMDB_ACCESS_TOKEN`, `WATCHMODE_API_KEY`) and rsyncs to `public_html/website_9d71f2eb/watchmovie/`.
Other required secrets: `SSH_PRIVATE_KEY`, `REMOTE_HOST`, `REMOTE_USER`.
