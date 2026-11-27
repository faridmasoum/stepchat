# Contributing

Thanks for helping! Bug reports, ideas and pull requests are all welcome.

## Setup

```bash
git clone https://github.com/faridmasoum/stepchat.git
cd stepchat
composer install
```

## Before opening a pull request

```bash
composer check          # PHPUnit (unit + real HTTP), PHPStan, code style
cd e2e && npm ci && npx playwright install chromium   # first time only
composer test:e2e       # real browser widget against a real PHP endpoint
node --check resources/dist/stepchat-widget.js
```

All of these must pass before you open a pull request. CI runs the same checks.

## Guidelines

- The core (`src/` outside `src/Laravel`) must stay free of framework dependencies and keep working on PHP 8.0.
- Add a test for every behaviour change. The engine must stay deterministic: an interpreter may only fill gaps, never override a successful parse.
- The widget is a single dependency-free file. Never insert server-provided text with `innerHTML`; use `textContent`.
- Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`...).
- Adding a language? Use `StepChatWidget.addLocale()` shape in the `LOCALES` table and keep every key.
