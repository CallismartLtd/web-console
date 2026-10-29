# PHP Web Terminal

A small PHP web terminal with a class-based PHP controller, separate CSS and JavaScript assets, and an xterm.js frontend. The interactive shell is the only source of command prompts; the browser forwards PTY output bytes and does not construct or redraw shell prompts.

## Requirements

- Linux/Unix
- PHP 8.1+ with the POSIX extension
- util-linux `script` command
- Network access to jsDelivr for xterm.js and its fit addon (or serve those libraries locally)

## Run locally

```sh
php -S 127.0.0.1:8080 -t .
```

Open `http://127.0.0.1:8080/index.php`.

Remote access is denied by default. If remote access is deliberately required, set both environment variables before starting PHP:

```sh
CONSOLE_ALLOW_REMOTE=1 CONSOLE_PASSWORD='use-a-long-random-password' php -S 0.0.0.0:8080 -t .
```

Use HTTPS and a properly configured web server for deployment. Do not expose this terminal to the public internet without additional network controls.

## Files

- `index.php` — minimal entry point
- `src/TerminalServer.php` — session, authentication, CSRF, PTY lifecycle and JSON endpoints
- `assets/terminal.js` — private `PHPWebTerminal` class in an IIFE; no app class or config is added to `window`
- `assets/terminal.css` — responsive terminal UI

The Upload file toolbar action uploads a selected file into the interactive shell’s current working directory (so it follows `cd`), up to 20 MB. The PHP process must be able to write to that directory; PHP upload limits such as `upload_max_filesize` and `post_max_size` must also permit the chosen file.

The terminal intentionally uses one prompt source: the shell running inside the PTY. No fallback JavaScript prompt renderer or command-runner prompt is included.
