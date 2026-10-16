# Contributing to IvoPDF

Thank you for helping! Bug reports, test cases (HTML that renders differently than in a browser, dompdf or mPDF) and pull requests are welcome.

## Contributor License Agreement (required)

Before your first pull request can be merged you must sign the [Contributor License Agreement](CLA.md). The CLA Assistant bot will ask you to post this comment on your pull request:

> I have read the CLA Document and I hereby sign the CLA

You only sign once. The CLA lets the maintainer keep the project's licensing flexible in the future; you keep the copyright to your work.

## Pull requests

1. Keep changes focused; one feature or fix per pull request.
2. Add or update a test in `tests/` that fails without your change.
3. Run every suite and make sure there are no PHP warnings or deprecations:

```bash
php tests/run_tests.php
```

```bash
php tests/test_everything.php
```

```bash
php tests/test_features.php
```

4. Render the demos in both languages and look at the result:

```bash
php examples/generate.php
```

5. Code style: `declare(strict_types=1)`, typed parameters and returns, short docblocks, no external dependencies beyond `ext-dom`, `ext-mbstring` and `ext-zlib` (`ext-gd` optional).
6. Do not copy code, fonts or data from other projects unless their license is compatible with AGPL-3.0; name the source and license in the pull request.

## Reporting bugs

Include the smallest HTML/CSS that shows the problem, the IvoPDF options you used, the PHP version, and (if possible) a screenshot of how a browser renders the same HTML. Security issues: please do not open a public issue — use GitHub's private vulnerability reporting for this repository.
