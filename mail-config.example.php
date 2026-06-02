<?php
/**
 * Copy this file to mail-config.php and fill in your SMTP credentials.
 * mail-config.php is gitignored and never committed.
 */
return [
    // SMTP server — use your hosting provider's outbound SMTP server
    // Common examples:
    //   cPanel shared hosting → mail.yourdomain.com
    //   Gmail               → smtp.gmail.com  (port 587, tls)
    //   Mailgun             → smtp.mailgun.org (port 587, tls)
    //   SES                 → email-smtp.us-east-1.amazonaws.com
    'smtp_host'     => 'mail.kyleferguson.ca',
    'smtp_port'     => 587,              // 587 (STARTTLS) or 465 (SMTPS)
    'smtp_secure'   => 'tls',           // 'tls' for port 587, 'ssl' for port 465
    'smtp_user'     => 'hello@kyleferguson.ca',
    'smtp_pass'     => 'YOUR_PASSWORD_HERE',

    // Who receives contact form submissions
    'to_email'      => 'hello@kyleferguson.ca',
    'to_name'       => 'Kyle Ferguson',

    // The From address on all outbound mail
    // Must be a mailbox on your SMTP server's domain to avoid SPF failures
    'from_email'    => 'hello@kyleferguson.ca',
    'from_name'     => 'Kyle Ferguson',

    // Reply-To for the admin notification (so you can reply directly to the sender)
    // Leave null to default to the sender's address
    'reply_to'      => null,

    // Set to true to enable SMTP debug output (never use in production)
    'debug'         => false,
];
