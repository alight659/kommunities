# Kommunities

A lightweight, anonymous community forum platform built as a single PHP file. No email addresses, no account registration -- just a randomly generated token that serves as your identity.

## Features
- Anonymous identity system using bearer tokens
- Reddit-style forums (called "communities" or k/forum-name)
- Posts with optional image attachments (JPEG, PNG, GIF, WebP)
- Nested comments and replies
- Upvote / downvote system
- User profiles with bio and avatar
- Forum admin controls (rename, delete, moderate posts)
- Dark mode
- CSRF protection
- SQLite database with WAL mode

## Installation
A Dockerfile is provided for containerised deployment.

**Requirements:** Docker

**Build and run**

```bash
docker build -t kommunities .
docker run -p 8080:80 kommunities
```

Then open `http://localhost:8080` in your browser. The database is created automatically on first run.

**Persisting data**

By default the SQLite database is wiped when the container stops. Mount a volume to keep it across restarts:

```bash
docker run -p 8080:80 -v $(pwd)/data:/var/www/html kommunities
```

No Composer, no build step, no configuration files needed.

## Usage

### Web interface
Open the site in a browser. You will be prompted to generate an identity token or log in with an existing one. Save your token somewhere safe - **it cannot be recovered if lost**.

## Identity and Security
- Identities are random 16-character hex tokens (format: `XXXX-XXXX-XXXX-XXXX`).
- There is no password reset and no recovery mechanism. Losing your token means losing your identity.
- Tokens are stored as session cookies and in `localStorage` for convenience.
- All state-mutating actions require a CSRF token.
- Uploaded images are validated by magic bytes (not the browser-supplied MIME type), and re-encoded through GD to strip EXIF metadata when the extension is available.

## Forum Administration
When you create a forum you become its admin. As admin you can:

- Rename the forum
- Delete the forum (removes everything related to it)
- Delete any post in the forum

## Limitations
- Intended for small community forum pages.
- Single-file design means all logic, HTML, and SQL live in `index.php`. This is intentional for ease of deployment.
- Images are stored as BLOBs in SQLite. For high-traffic sites, consider moving to filesystem or object storage.


## License
CopyLeft - do what you want with it.

## Author
- [@alight659](https://github.com/alight659)