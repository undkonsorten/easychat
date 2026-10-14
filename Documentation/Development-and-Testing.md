# Development & testing

Tests and code checks run in containers via `Build/Scripts/runTests.sh` (docker or podman, based on
the [TYPO3 Best Practices _Tea extension_](https://github.com/TYPO3BestPractices/tea)). No local PHP needed. Run all commands from the extension's root directory.


```console
# install dependencies into .Build/ (once, and after switching PHP version)
Build/Scripts/runTests.sh -s composerUpdateMax

# unit tests / functional tests (sqlite default; mariadb, mysql, postgres via -d)
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional -d mariadb

# the same against TYPO3 12.4 on PHP 8.2
Build/Scripts/runTests.sh -t 12.4 -p 8.2 -s composerUpdateMax
Build/Scripts/runTests.sh -t 12.4 -p 8.2 -s unit

# code style (dry-run), static analysis, PHP lint
Build/Scripts/runTests.sh -s cgl -n
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s lintPhp

# all options
Build/Scripts/runTests.sh -h
```

The static checks and unit tests also run natively via Composer scripts (`composer check:static`,
`composer check:tests:unit`, `composer fix`), as in [TYPO3 Best Practice Extension _Tea example_](https://extensions.typo3.org/extension/tea). `lintPhp` in runTests.sh calls
`composer check:php:lint` itself.

The functional suite starts a throwaway Qdrant container, so the re-indexing tests run against a real
vector store with faked embeddings (no LLM API key needed). GitHub Actions (`.github/workflows/ci.yml`)
runs the same commands for TYPO3 12.4 (PHP 8.2–8.4) and TYPO3 13.4 (PHP 8.2–8.5).

EXT:index (`lochmueller/index`) is optional and needs TYPO3 13.4 and PHP 8.3+. The composer suites
install it where it fits (`-t 13.4` with PHP 8.3+), and its tests are skipped everywhere else.
PHPStan therefore runs on that full install (`-t 13.4 -p 8.3`).
