# ashshams.co.in

Website for ashshams.co.in — a static site plus one PHP endpoint for the
contact form.

## Layout

```
index.html          Home page, including the contact form
about-us.html       About page
privacy.html        Privacy policy
tnc.html            Terms & conditions
assets/             CSS, JS, fonts and images
php/contact.php     Contact form handler
php/config.php      Contact form settings
```

## Deploying

Upload the whole directory to the web root. Everything except `php/` is
static; `php/` needs PHP 7.4 or newer with the `mail()` function enabled,
which is the default on essentially every shared host.

## Contact form

The form in `index.html` posts to `php/contact.php`, which validates the
submission and emails it to the address set in `php/config.php`.

Settings live in `php/config.php` and each one can also be supplied as an
environment variable, so you can change them without editing the file:

| Setting | Environment variable | Default |
| --- | --- | --- |
| Where enquiries are delivered | `CONTACT_RECIPIENT` | `salimshivani@gmail.com` |
| From address on outgoing mail | `CONTACT_FROM_EMAIL` | `noreply@ashshams.co.in` |
| From display name | `CONTACT_FROM_NAME` | `AshShams Technologies Website` |
| Subject line prefix | `CONTACT_SUBJECT_PREFIX` | `[Website] ` |
| Submissions allowed per IP | `CONTACT_RATE_LIMIT` | `5` |
| Rate limit window, seconds | `CONTACT_RATE_LIMIT_WINDOW` | `3600` |
| Minimum seconds before submit | `CONTACT_MIN_SECONDS` | `3` |

### Before going live

**`CONTACT_FROM_EMAIL` must be a real mailbox on a domain this server is
allowed to send for**, normally the site's own domain. Putting the
visitor's address there instead is what causes contact form mail to land in
spam or be rejected outright, because it fails SPF and DMARC. The visitor's
address goes in `Reply-To`, so replying from your mail client still reaches
them.

Send yourself a test enquiry after deploying. If nothing arrives:

- Check the recipient's spam folder first.
- Look in the server's PHP error log; failures are recorded there.
- If mail still does not go out, set `set_envelope_sender` to `false` in
  `php/config.php`. A few hosts reject the `-f` parameter the script passes
  to sendmail.
- Hosts that block `mail()` entirely require SMTP. That means adding a
  library such as PHPMailer and swapping the single `mail()` call in
  `php/contact.php` for an SMTP send.

### How submissions are filtered

- Required fields, email format and length limits are checked server side,
  regardless of what the browser did.
- Carriage returns and line feeds are stripped from anything that reaches a
  mail header, which is what prevents header injection.
- A hidden honeypot field catches bots that fill in every input.
- Submissions arriving within `CONTACT_MIN_SECONDS` of the page rendering
  are rejected.
- Each IP is limited to `CONTACT_RATE_LIMIT` submissions per window, with
  the counter kept in the system temp directory.

The form works without JavaScript: it posts normally and the handler
returns a plain confirmation page. With JavaScript it submits in the
background and shows the result inline.

## Local preview

`npm start` serves the site with `http-server`, which is fine for the
static pages but will not run `php/contact.php`. To exercise the form
locally use PHP's built in server instead:

```
php -S 127.0.0.1:8000 -t .
```
