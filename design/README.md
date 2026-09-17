# Design reference

`email-workspace.html` and `email-workspace.css` are the approved visual
foundation for Aicountly Email. They are a **scaffold, not the application**:

* every value in the HTML is illustrative and never ships;
* the application implements the same tokens and primitives in
  `web/src/styles/email.css`, with real state and real components;
* external email HTML is **never** injected into this layout. The application
  renders it through `web/src/components/SecureMessageFrame.tsx`, a sandboxed
  iframe fed by the server-side allowlist sanitizer
  (`server-php/src/Mail/HtmlSanitizer.php`).

Open `email-workspace.html` directly in a browser to review the design. Nothing
here is part of the build, and nothing here is deployed.
