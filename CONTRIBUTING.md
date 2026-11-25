# Contributing

Setup, formatting, linting and conventions follow [crabphp/framework's CONTRIBUTING.md](https://github.com/crabphp/framework/blob/main/CONTRIBUTING.md). Use PHP 8.4 or later, put a framework 0.7.5 or later checkout at `../framework`, and run `composer install` here.

Before a pull request, run here:

```sh
composer validate --strict
composer format:check
composer lint
composer test
```

CI runs the suite on Linux and macOS against framework `v0.7.5` and `main`.
